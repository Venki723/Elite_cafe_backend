<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon; // For date and time manipulation
use App\Models\RestaurantTable; // Import your RestaurantTable model
use App\Models\Reservation;       // Import your Reservation model (formerly TableInfo)
use Illuminate\Support\Facades\Log; // Import the Log facade

class OfflineReservation extends Controller
{
    /**
     * Handle the request to check table availability for offline reservations.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkAvailability(Request $request)
    {
        // 1. Validate incoming request data
        $validator = Validator::make($request->all(), [
            'reservation_date' => 'required|date_format:Y-m-d',
            'reservation_time' => 'required|date_format:H:i',
            'persons'          => 'required|integer|min:1|max:10', // Max 10 persons as per frontend validation
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid input data.',
                'errors'  => $validator->errors(),
            ], 400); // Bad Request
        }

        $reservationDate = $request->input('reservation_date');
        $reservationTime = $request->input('reservation_time');
        $persons         = $request->input('persons');

        // Combine date and time for more granular "past time" validation
        $requestedDateTime = Carbon::parse($reservationDate . ' ' . $reservationTime);

        // New Validation: Ensure the combined date and time is not in the past
        if ($requestedDateTime->lessThan(Carbon::now())) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Reservation time cannot be in the past.',
            ], 400);
        }

        // Define the 45-minute buffer for offline reservations
        // This means an offline reservation can start 45 minutes before a scheduled online reservation
        $offlineBufferStart = $requestedDateTime->copy()->subMinutes(45);
        // Assuming an offline reservation also takes up 1 hour for simplicity, similar to online
        $offlineBufferEnd = $requestedDateTime->copy()->addHours(1);

        Log::debug('Requested Slot Details:', [
            'reservation_date' => $reservationDate,
            'reservation_time' => $reservationTime,
            'persons' => $persons,
            'requested_datetime' => $requestedDateTime->toDateTimeString(),
            'offline_buffer_start' => $offlineBufferStart->toDateTimeString(),
            'offline_buffer_end' => $offlineBufferEnd->toDateTimeString(),
        ]);


        // 2. Fetch actual available tables from the database
        // Fetch ONLY offline tables, as per the latest requirement.
        $allOfflineTables = RestaurantTable::where('table_type', 'offline')->get();
        Log::debug('All Offline Tables Fetched:', $allOfflineTables->toArray());

        $availableTables = [];

        foreach ($allOfflineTables as $table) { // Loop through only offline tables
            // Check if the table's capacity meets the requested number of persons
            // Removed the `$table->capacity >= $persons` filter here.
            // Now, all available offline tables will be checked for conflicts, and the frontend can combine them.
            
            $isBooked = false; // Flag for general conflict (any reservation overlaps with the offline buffer)
            
            Log::debug('Checking Table:', ['table_id' => $table->id, 'table_name' => $table->name, 'capacity' => $table->capacity, 'table_type' => $table->table_type]);

            // Check if this table is already booked for the requested date and time using the many-to-many relationship
            $conflictingReservations = Reservation::whereHas('tables', function ($query) use ($table) {
                $query->where('restaurant_table_id', $table->id);
            })
            ->where('reservation_date', $reservationDate) // Filter by date first for efficiency
            ->where(function ($query) use ($offlineBufferStart, $offlineBufferEnd) {
                // Overlap condition: (StartA < EndB) && (EndA > StartB)
                // Where A is the requested offline slot (offlineBufferStart, offlineBufferEnd)
                // And B is the existing reservation (reserved_from, reserved_to)
                $query->where('reserved_from', '<', $offlineBufferEnd)
                      ->where('reserved_to', '>', $offlineBufferStart);
            })
            ->get();

            if ($conflictingReservations->isNotEmpty()) {
                $isBooked = true;
                Log::debug('Table IS BOOKED due to general overlap!', ['table_id' => $table->id, 'conflicting_reservation_ids' => $conflictingReservations->pluck('id')->toArray()]);
            }
            
            // Determine if the table is available based on its type and booking status
            if (!$isBooked) { // If there's no general conflict
                // Only offline tables are considered available now
                $tableData = $table->toArray();
                $tableData['name'] = trim($tableData['name']);
                $availableTables[] = $tableData;
                Log::debug('Offline Table IS AVAILABLE:', ['table_id' => $table->id]);
            } else {
                Log::debug('Table NOT AVAILABLE (general conflict):', ['table_id' => $table->id]);
            }
        }

        // Trim names for the final output
        $formattedAvailableTables = array_map(function($table) {
            $table['name'] = trim($table['name']);
            return $table;
        }, $availableTables);


        // 3. Return response
        if (empty($formattedAvailableTables)) {
            Log::debug('No tables found for the requested criteria.');
            return response()->json([
                'status'  => 'success', // Still 'success' but indicates no tables found
                'message' => 'No tables available for the selected time and persons.',
                'tables'  => [],
            ], 200);
        } else {
            Log::debug('Available tables found:', ['tables_count' => count($formattedAvailableTables), 'tables' => $formattedAvailableTables]);
            return response()->json([
                'status'  => 'success',
                'message' => 'Available tables fetched successfully.',
                'tables'  => $formattedAvailableTables,
            ], 200);
        }
    }

    /**
     * Handle the request to save an offline reservation.
     * This method corresponds to the `saveOfflineReservation` in your frontend controller.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveOfflineReservation(Request $request)
    {
        // 1. Validate incoming request data
        $validated = $request->validate([
            'first_name'       => 'required|string|max:200',
            'last_name'        => 'required|string|max:300',
            'email'            => 'required|email|max:200',
            'phone_number'     => 'required|digits:10',
            'persons'          => 'required|integer|min:1|max:10',
            'reservation_date' => 'required|date_format:Y-m-d',
            'reservation_time' => 'required|date_format:H:i:s', // Expecting HH:mm:ss from frontend
            'message'          => 'nullable|string|max:300',
            'selected_table_id' => 'required|integer|exists:tables,id', // Ensure a table is selected and exists
        ]);
       
        try {
            // Calculate reserved_from and reserved_to based on reservation_date and reservation_time
            $reservedFrom = Carbon::parse($validated['reservation_date'] . ' ' . $validated['reservation_time']);
            // Assuming a default reservation duration, e.g., 1 hour. Adjust as needed.
            $reservedTo = $reservedFrom->copy()->addHours(1); 

            // Create the reservation record
            $reservation = Reservation::create([ // Use Reservation model
                'first_name'       => $validated['first_name'],
                'last_name'        => $validated['last_name'],
                'email'            => $validated['email'],
                'phone_number'     => $validated['phone_number'],
                'booked_persons'   => $validated['persons'], // Mapped to booked_persons
                'reservation_date' => $validated['reservation_date'],
                'reservation_time' => $validated['reservation_time'],
                'message'          => $validated['message'],
                'status'           => 'pending', // Set a default status, e.g., 'pending', 'confirmed'
                'reserved_from'    => $reservedFrom,
                'reserved_to'      => $reservedTo,
                'type'             => 'offline',
                // If you have a 'reservation_type' column in your 'reservations' table, set it here:
                // 'reservation_type' => 'offline',
            ]);
 
            // Attach the selected table to the reservation via the pivot table
            // If multiple tables can be selected, 'selected_table_id' would be an array.
            // For now, assuming single selection based on validation.
            $reservation->tables()->attach($validated['selected_table_id']);

            return response()->json([
                'status'  => 'success',
                'data' => 'hello',
                'message' => 'Reservation confirmed and table assigned successfully.',
                'reservation_id' => $reservation->id,
                
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error saving offline reservation: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Reservation failed. Please try again. Error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
