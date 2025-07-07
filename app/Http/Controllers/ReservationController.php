<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Staff;
use App\Models\StaffTableAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class ReservationController extends Controller
{
    /**
     * Handles the creation of a new reservation, including table and staff assignment.
     *
     * @param Request $request The incoming HTTP request containing reservation details.
     * @return \Illuminate\Http\JsonResponse
     */
    public function reservationdetails(Request $request)
    {
        // 1. Validate incoming request data
        $validated = $request->validate([
            'first_name'        => 'required|string|max:200',
            'last_name'         => 'required|string|max:300',
            'email'             => 'required|email|max:200',
            'phone_number'      => 'required|digits:10',
            'persons'           => 'required|integer|min:1|max:100',
            'reservation_date'  => 'required|date_format:Y-m-d|after_or_equal:today',
            'reservation_time'  => 'required|date_format:H:i',
            'message'           => 'nullable|string|max:300',
        ]);

        $bookedPersons = $validated['persons'];
        $reservationDate = $validated['reservation_date'];
        $reservationTime = $validated['reservation_time'];
        $slotDurationMinutes = 60; // Standard reservation slot duration

        $startDateTime = Carbon::parse($reservationDate . ' ' . $reservationTime);
        $endDateTime = $startDateTime->copy()->addMinutes($slotDurationMinutes);

        // 2. Prevent booking for past times on the current day
        // Using config('app.timezone') for accurate time comparison
        if ($startDateTime->isToday() && $startDateTime->lt(Carbon::now(config('app.timezone')))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot book for a past time today. Please choose a future time.',
            ], 400);
        }

        // --- NEW LOGIC: Check for specific capacity 2 table limit BEFORE finding combinations ---
        $capacity2Limit = 3; // Maximum 3 tables of capacity 2 allowed
        if ($bookedPersons <= 2) { // This check is primarily for requests for 1 or 2 persons
            $currentlyBookedCapacity2Tables = Reservation::where(function ($q) use ($startDateTime, $endDateTime) {
                    $q->where('reserved_from', '<', $endDateTime)
                      ->where('reserved_to', '>', $startDateTime);
                })
                ->whereHas('tables', function ($q) {
                    $q->where('capacity', 2);
                })
                ->count();

            if ($currentlyBookedCapacity2Tables >= $capacity2Limit) {
                // If the limit for capacity 2 tables is reached, and this request is for 1 or 2 persons,
                // we immediately reject with the specific message.
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Please choose the next slot, all tables of capacity 2 are booked for ' .
                                 $startDateTime->format('h:i A') . ' on ' . $reservationDate . '.',
                ], 400);
            }
        }
        // --- END NEW LOGIC ---

        // 3. Find available table combinations
        // The findTableCombinations method will now only consider tables *within* the general limits,
        // but the specific capacity 2 hard limit check is handled above for exact messaging.
        $assignedTables = $this->findTableCombinations($bookedPersons, $startDateTime, $endDateTime);

        if (empty($assignedTables)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Sorry, no suitable tables are available for ' . $bookedPersons . ' guests at ' .
                             $startDateTime->format('h:i A') . ' on ' . $reservationDate . '. Please try another time slot or fewer guests.',
            ], 400);
        }

        // Start database transaction for atomicity (all or nothing)
        DB::beginTransaction();

        try {
            // 4. Create the reservation record
            $reservation = Reservation::create([
                'first_name'        => $validated['first_name'],
                'last_name'         => $validated['last_name'],
                'email'             => $validated['email'],
                'phone_number'      => $validated['phone_number'],
                'booked_persons'    => $bookedPersons,
                'reservation_date'  => $reservationDate,
                'reservation_time'  => $startDateTime->format('H:i:s'), // Store time as H:i:s
                'reserved_from'     => $startDateTime,
                'reserved_to'       => $endDateTime,
                'status'            => 'confirmed', // Default status for new reservations
                'message'           => $validated['message'],
                'type'             => 'online',
            ]);

            // 5. Attach assigned tables to the reservation via the pivot table
            // This assumes the 'tables' relationship on the Reservation model is correctly defined as belongsToMany.
            $reservation->tables()->attach(collect($assignedTables)->pluck('id'));

            // 6. Staff Assignment Logic
            $staffAssignmentsToCreate = [];
            $assignedStaffIdsCurrentReservation = [
                'Waiter' => [],
                'Manager' => [],
                'Cleaner' => [],
            ];
            $maxTablesPerStaff = 3; // Maximum number of tables a staff member can be assigned at one time slot

            foreach ($assignedTables as $table) {
                $currentTableAssignedStaff = [];
                foreach (['Waiter', 'Manager', 'Cleaner'] as $role) {
                    $roleColumn = strtolower($role) . '_id';

                    $staff = Staff::where('role', $role)
                        ->whereNotIn('staff_id', function ($query) use ($reservationDate, $reservationTime, $roleColumn, $maxTablesPerStaff) {
                            $query->select($roleColumn)
                                ->from('staff_table_assignments')
                                ->where('assignment_date', $reservationDate)
                                ->where('assignment_time', $reservationTime)
                                ->whereNotNull($roleColumn)
                                ->groupBy($roleColumn)
                                ->havingRaw("COUNT({$roleColumn}) >= ?", [$maxTablesPerStaff]);
                        })
                        ->whereNotIn('staff_id', $assignedStaffIdsCurrentReservation[$role])
                        ->inRandomOrder()
                        ->first();

                    if ($staff) {
                        $currentTableAssignedStaff[$role] = $staff->staff_id;
                        $assignedStaffIdsCurrentReservation[$role][] = $staff->staff_id;
                    } else {
                        Log::warning("Could not find an available {$role} (max {$maxTablesPerStaff} tables reached or no staff available) for table ID: {$table->id} at {$reservationDate} {$reservationTime}.");
                        DB::rollBack();
                        return response()->json([
                            'status'  => 'error',
                            'message' => "Sorry, we couldn't assign enough staff for your reservation (e.g., no available {$role}). Please try a different time or fewer guests.",
                        ], 500);
                    }
                }

                if (isset($currentTableAssignedStaff['Waiter'], $currentTableAssignedStaff['Manager'], $currentTableAssignedStaff['Cleaner'])) {
                    $staffAssignmentsToCreate[] = [
                        'table_id'          => $table->id,
                        'assignment_date'   => $reservationDate,
                        'assignment_time'   => $reservationTime,
                        'waiter_id'         => $currentTableAssignedStaff['Waiter'],
                        'manager_id'        => $currentTableAssignedStaff['Manager'],
                        'cleaner_id'        => $currentTableAssignedStaff['Cleaner'],
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ];
                }
            }

            if (!empty($staffAssignmentsToCreate)) {
                StaffTableAssignment::insert($staffAssignmentsToCreate);
            } else {
                Log::error("No staff assignments could be prepared for insertion after table assignment. Check staff availability and assignment logic.");
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Failed to finalize staff assignments. Please contact support.',
                ], 500);
            }

            // 7. Commit the entire transaction if all operations (reservation, table attach, staff assign) were successful
            DB::commit();

            // 8. Prepare response data for the client
            $assignedTableNames = collect($assignedTables)->pluck('name')->implode(', ');
            $totalCapacity = collect($assignedTables)->sum('capacity');

            $assignedTableIdsForResponse = collect($assignedTables)->pluck('id')->toArray();
            $staffAssignments = StaffTableAssignment::with(['waiter', 'manager', 'cleaner'])
                ->whereIn('table_id', $assignedTableIdsForResponse)
                ->where('assignment_date', $reservationDate)
                ->where('assignment_time', $reservationTime)
                ->get();

            $staffDetails = [];
            foreach ($staffAssignments as $assignment) {
                foreach (['waiter', 'manager', 'cleaner'] as $role) {
                    $staffMember = $assignment->$role;
                    if ($staffMember) {
                        if (!isset($staffDetails[$staffMember->staff_id])) {
                            $staffDetails[$staffMember->staff_id] = [
                                'staff_id' => $staffMember->staff_id,
                                'name' => $staffMember->first_name . ' ' . $staffMember->last_name,
                                'role' => $staffMember->role,
                                'assigned_tables_ids' => []
                            ];
                        }
                        if (!in_array($assignment->table_id, $staffDetails[$staffMember->staff_id]['assigned_tables_ids'])) {
                            $staffDetails[$staffMember->staff_id]['assigned_tables_ids'][] = $assignment->table_id;
                        }
                    }
                }
            }

            $groupedStaffDetails = array_values(array_map(function ($s) {
                $s['assigned_tables_ids'] = array_values(array_unique($s['assigned_tables_ids']));
                return $s;
            }, $staffDetails));

            // 9. Return success response with all relevant booking details
            return response()->json([
                'status'                    => 'success',
                'message'                   => 'Reservation successfully created for ' . $bookedPersons . ' guests.',
                'data'                      => $reservation,
                'assigned_tables'           => collect($assignedTables)->map(function ($table) {
                    return ['id' => $table->id, 'name' => trim($table->name), 'capacity' => $table->capacity];
                }),
                'assigned_table_names'      => trim($assignedTableNames),
                'total_assigned_capacity'   => $totalCapacity,
                'assigned_staff'            => $groupedStaffDetails,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Reservation failed: ' . $e->getMessage(), ['exception' => $e, 'trace' => $e->getTraceAsString()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'An unexpected server error occurred during reservation. Please try again or contact support.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Finds the best combination of available tables for the given number of persons
     * and time slot, respecting capacity limits for online bookings and preferring
     * single tables or combinations with fewer tables.
     *
     * @param int $targetPersons The number of persons for the reservation.
     * @param Carbon $startDateTime The start time of the reservation slot.
     * @param Carbon $endDateTime The end time of the reservation slot.
     * @return array A list of RestaurantTable models representing the best combination found.
     */
    protected function findTableCombinations(int $targetPersons, Carbon $startDateTime, Carbon $endDateTime): array
    {
        // Define capacity limits for online reservations for specific table capacities.
        // This acts as a global cap on how many tables of a certain capacity can be booked via this system.
        $capacityLimits = [
            2 => 3, // Max 3 tables of capacity 2 are available for online booking
            4 => 6, // Max 6 tables of capacity 4 are available for online booking
            6 => 2, // Example: Max 2 tables of capacity 6 are available online
            // Add other capacities and their limits as needed.
        ];

        $onlineReservableTables = new Collection();
        foreach ($capacityLimits as $capacity => $limit) {
            // Get already booked tables for this capacity and time slot
            $bookedTableIdsForCapacity = Reservation::where(function ($q) use ($startDateTime, $endDateTime) {
                    $q->where('reserved_from', '<', $endDateTime)
                      ->where('reserved_to', '>', $startDateTime);
                })
                ->whereHas('tables', function ($q) use ($capacity) {
                    $q->where('capacity', $capacity);
                })
                ->with('tables')
                ->get()
                ->flatMap(fn ($reservation) => $reservation->tables->where('capacity', $capacity)->pluck('id'))
                ->unique()
                ->toArray();

            // Calculate how many more tables of this capacity can be booked
            $availableSlotsForCapacity = $limit - count($bookedTableIdsForCapacity);

            if ($availableSlotsForCapacity > 0) {
                // Fetch only the number of available tables up to the remaining limit
                $tablesOfType = RestaurantTable::where('capacity', $capacity)
                    ->whereNotIn('id', $bookedTableIdsForCapacity)
                    ->orderBy('id')
                    ->take($availableSlotsForCapacity)
                    ->get();
                $onlineReservableTables = $onlineReservableTables->merge($tablesOfType);
            }
        }

        // Sort tables by capacity ascending for efficient combination search (smaller tables first)
        $onlineReservableTables = $onlineReservableTables->sortBy('capacity')->values();

        $bestCombination = [];
        $minTotalCapacityDifference = PHP_INT_MAX;
        $minTableCount = PHP_INT_MAX;

        $findCombinations = function ($index, $currentCapacity, $currentCombination)
            use (
                &$findCombinations, $onlineReservableTables, $targetPersons,
                &$bestCombination, &$minTotalCapacityDifference, &$minTableCount
            ) {
            $currentTableCount = count($currentCombination);
            $currentCapacityDifference = $currentCapacity - $targetPersons;

            if ($currentCapacity >= $targetPersons) {
                if ($currentCapacityDifference < $minTotalCapacityDifference ||
                    ($currentCapacityDifference === $minTotalCapacityDifference && $currentTableCount < $minTableCount)) {
                    $bestCombination = $currentCombination;
                    $minTotalCapacityDifference = $currentCapacityDifference;
                    $minTableCount = $currentTableCount;
                }
                return;
            }

            if ($index >= $onlineReservableTables->count()) {
                return;
            }

            $table = $onlineReservableTables[$index];

            // Option 1: Include the current table in the combination
            if ($currentTableCount < 4) { // General limit on number of tables in a combination
                $findCombinations(
                    $index + 1,
                    $currentCapacity + $table->capacity,
                    array_merge($currentCombination, [$table])
                );
            }

            // Option 2: Exclude the current table from the combination and try the next table
            $findCombinations(
                $index + 1,
                $currentCapacity,
                $currentCombination
            );
        };

        $findCombinations(0, 0, []);

        return $bestCombination;
    }
}