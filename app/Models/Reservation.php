<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
 
/**
 * Class Reservation
 * Represents a guest reservation in the restaurant.
 * Corresponds to the 'reservations' table in your database schema.
 */
class Reservation extends Model
{
    use HasFactory;
 
    protected $table = 'reservations';
 
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'booked_persons',
        'reservation_date',
        'reservation_time',
        'reserved_from',
        'reserved_to',
        // 'assigned_table_id', // This column should have been removed by the migration
        'status',
        'message',
        'actual_arrival_time',
        'actual_persons_arrived',
        'type',
    ];
 
    protected $casts = [
        'reservation_date'    => 'date',
        'reservation_time'    => 'datetime',
        'reserved_from'       => 'datetime',
        'reserved_to'         => 'datetime',
        'actual_arrival_time' => 'datetime',
    ];
 
    /**
     * Get the restaurant tables assigned to this reservation.
     * A Reservation can be assigned many RestaurantTables (via a pivot table).
     */
    public function tables()
    {
        // This defines a many-to-many relationship.
        // 'reservation_tables' is the name of your pivot table.
        // 'reservation_id' is the foreign key on the pivot table for this model (Reservation).
        // 'restaurant_table_id' is the foreign key on the pivot table for the related model (RestaurantTable).
        return $this->belongsToMany(RestaurantTable::class, 'reservation_tables', 'reservation_id', 'restaurant_table_id')
                    ->withTimestamps(); // Include if your pivot table has created_at/updated_at columns
    }
}
 
 