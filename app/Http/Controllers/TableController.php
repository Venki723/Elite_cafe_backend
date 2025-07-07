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

class TableController extends Controller
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
            'booking_type'      => 'required|in:online,offline', // Added to differentiate booking types
        ]);

        $bookedPersons = $validated['persons'];
        $reservationDate = $validated['reservation_date'];
        $reservationTime = $validated['reservation_time'];
        $bookingType = $validated['booking_type']; // Get the booking type
        $slotDurationMinutes = 60; // Standard reservation slot duration

        $startDateTime = Carbon::parse($reservationDate . ' ' . $reservationTime);
        $endDateTime = $startDateTime->copy()->addMinutes($slotDurationMinutes);

        // 2. Prevent booking for past times on the current day
        if ($startDateTime->isToday() && $startDateTime->lt(Carbon::now(config('app.timezone')))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot book for a past time today. Please choose a future time.',
            ], 400);
        }

        // --- Specific Capacity 2 Table Limit Check for 'online' booking type ---
        // This check is specific to online bookings for capacity 2 tables
        $capacity2Limit = 3;
        if ($bookingType === 'online' && $bookedPersons <= 2) {
            $currentlyBookedCapacity2OnlineTables = Reservation::where(function ($q) use ($startDateTime, $endDateTime) {
                    $q->where('reserved_from', '<', $endDateTime)
                      ->where('reserved_to', '>', $startDateTime);
                })
                ->whereHas('tables', function ($q) {
                    $q->where('capacity', 2)
                      ->where('table_type', 'online'); // Ensure we count only online tables
                })
                ->count();

            if ($currentlyBookedCapacity2OnlineTables >= $capacity2Limit) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Please choose the next slot, all online tables of capacity 2 are booked for ' .
                                 $startDateTime->format('h:i A') . ' on ' . $reservationDate . '.',
                ], 400);
            }
        }
        // --- END Specific Capacity 2 Table Limit Check ---


        // 3. Find available table combinations, passing the booking type
        $assignedTables = $this->findTableCombinations($bookedPersons, $startDateTime, $endDateTime, $bookingType);

        if (empty($assignedTables)) {
            $message = 'Sorry, no suitable tables are available for ' . $bookedPersons . ' guests at ' .
                       $startDateTime->format('h:i A') . ' on ' . $reservationDate . '. Please try another time slot or fewer guests.';

            // Optional: More specific message for offline bookings if no tables are found
            if ($bookingType === 'offline') {
                $message = 'Sorry, no offline tables or unbooked online tables are available for ' . $bookedPersons . ' guests at ' .
                           $startDateTime->format('h:i A') . ' on ' . $reservationDate . '. Please try another time slot.';
            }

            return response()->json([
                'status'  => 'error',
                'message' => $message,
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
                'reservation_time'  => $startDateTime->format('H:i:s'),
                'reserved_from'     => $startDateTime,
                'reserved_to'       => $endDateTime,
                'status'            => 'confirmed',
                'message'           => $validated['message'],
                'booking_type'      => $bookingType, // Store the booking type in the reservation
            ]);

            // 5. Attach assigned tables to the reservation via the pivot table
            $reservation->tables()->attach(collect($assignedTables)->pluck('id'));

            // 6. Staff Assignment Logic (remains largely the same)
            $staffAssignmentsToCreate = [];
            $assignedStaffIdsCurrentReservation = [
                'Waiter' => [], 'Manager' => [], 'Cleaner' => [],
            ];
            $maxTablesPerStaff = 3;

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
                        Log::warning("Could not find an available {$role} for table ID: {$table->id} at {$reservationDate} {$reservationTime}.");
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

            // 7. Commit the entire transaction
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
     * and time slot, respecting capacity limits and considering online-to-offline shift.
     *
     * @param int $targetPersons The number of persons for the reservation.
     * @param Carbon $startDateTime The start time of the reservation slot.
     * @param Carbon $endDateTime The end time of the reservation slot.
     * @param string $bookingType The type of booking ('online' or 'offline').
     * @return array A list of RestaurantTable models representing the best combination found.
     */
    protected function findTableCombinations(int $targetPersons, Carbon $startDateTime, Carbon $endDateTime, string $bookingType): array
    {
        // Define capacity limits for online reservations for specific table capacities.
        $capacityLimits = [
            2 => 3, // Max 3 tables of capacity 2 are available for online booking
            4 => 6, // Max 6 tables of capacity 4 are available for online booking
            6 => 2, // Example: Max 2 tables of capacity 6 are available online
        ];

        // Get all tables that are currently booked for the given time slot, regardless of type
        $bookedTableIdsForSlot = Reservation::where(function ($q) use ($startDateTime, $endDateTime) {
                $q->where('reserved_from', '<', $endDateTime)
                  ->where('reserved_to', '>', $startDateTime);
            })
            ->where('status', 'confirmed') // Only consider confirmed bookings
            ->with('tables')
            ->get()
            ->flatMap(fn ($reservation) => $reservation->tables->pluck('id'))
            ->unique()
            ->toArray();

        $allAvailableTables = new Collection();

        // 1. Add explicitly 'offline' tables to the pool
        if ($bookingType === 'offline') {
            $offlineTables = RestaurantTable::where('table_type', 'offline')
                                            ->whereNotIn('id', $bookedTableIdsForSlot)
                                            ->get();
            $allAvailableTables = $allAvailableTables->merge($offlineTables);
        }

        // 2. Add 'online' tables that are not booked and are available within their limits
        //    For offline bookings, also consider online tables if current time is close to slot.
        $currentTime = Carbon::now(config('app.timezone'));
        $thresholdTime = $startDateTime->copy()->subMinutes(10); // 10 minutes before the slot, convert online to offline

        foreach ($capacityLimits as $capacity => $limit) {
            // Count how many online tables of this capacity are already booked for this slot
            $onlineTablesBookedCount = Reservation::where(function ($q) use ($startDateTime, $endDateTime) {
                    $q->where('reserved_from', '<', $endDateTime)
                      ->where('reserved_to', '>', $startDateTime);
                })
                ->whereHas('tables', function ($q) use ($capacity) {
                    $q->where('capacity', $capacity)
                      ->where('table_type', 'online');
                })
                ->count();

            $availableOnlineSlotsInLimit = $limit - $onlineTablesBookedCount;

            if ($availableOnlineSlotsInLimit > 0) {
                // Fetch online tables that are not booked and within their global capacity limit
                $onlineTablesToConsider = RestaurantTable::where('capacity', $capacity)
                    ->where('table_type', 'online')
                    ->whereNotIn('id', $bookedTableIdsForSlot) // Exclude tables already booked by anyone (online/offline)
                    ->orderBy('id')
                    ->get(); // Get all potentially available online tables

                // Now apply the limit based on booking type
                if ($bookingType === 'online') {
                    // For online bookings, strictly adhere to the defined capacity limit
                    $onlineTablesToConsider = $onlineTablesToConsider->take($availableOnlineSlotsInLimit);
                    $allAvailableTables = $allAvailableTables->merge($onlineTablesToConsider);
                } elseif ($bookingType === 'offline' && $currentTime->gte($thresholdTime) && $startDateTime->isSameDay($currentTime)) {
                    // For offline bookings, AND if current time is near the slot, consider ALL unbooked online tables
                    // that were originally "online" type and not booked.
                    // This is the core of the "shifting" logic.
                    $allAvailableTables = $allAvailableTables->merge($onlineTablesToConsider);
                }
            }
        }

        // Sort tables by capacity ascending for efficient combination search
        $allAvailableTables = $allAvailableTables->sortBy('capacity')->values();

        $bestCombination = [];
        $minTotalCapacityDifference = PHP_INT_MAX;
        $minTableCount = PHP_INT_MAX;

        // Recursive function to find the best table combination
        $findCombinations = function ($index, $currentCapacity, $currentCombination)
            use (
                &$findCombinations, $allAvailableTables, $targetPersons,
                &$bestCombination, &$minTotalCapacityDifference, &$minTableCount
            ) {
            $currentTableCount = count($currentCombination);
            $currentCapacityDifference = $currentCapacity - $targetPersons;

            // If we have met or exceeded the target persons
            if ($currentCapacity >= $targetPersons) {
                // Check if this combination is better than the current best found
                if ($currentCapacityDifference < $minTotalCapacityDifference ||
                    ($currentCapacityDifference === $minTotalCapacityDifference && $currentTableCount < $minTableCount)) {
                    $bestCombination = $currentCombination;
                    $minTotalCapacityDifference = $currentCapacityDifference;
                    $minTableCount = $currentTableCount;
                }
                return;
            }

            // Base case: If we've iterated through all available tables, stop this recursive branch.
            if ($index >= $allAvailableTables->count()) {
                return;
            }

            $table = $allAvailableTables[$index];

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