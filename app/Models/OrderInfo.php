<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// It seems this model might be redundant if OrderItem.php correctly represents your order_items table.
// OrderInfo and OrderItem both refer to 'order_items' table.
// You should typically only have one model per database table.
// Assuming OrderItem.php is the correct and more complete representation of order_items.
// If not, you need to clarify the purpose of OrderInfo vs OrderItem.
class OrderInfo extends Model
{
    protected $table = 'order_items'; // This table is also used by OrderItem.php

    public $timestamps = false; // Disable timestamps if the table doesn't have created_at and updated_at

    protected $fillable = [
        'cust_id', // This would likely be 'customer_id' if linking to a customer model
        'order_id',
        'qty', // This likely maps to 'quantity' in OrderItem.php
    ];

    // If this model is intended to be used, it needs relationships too.
    // public function order()
    // {
    //     return $this->belongsTo(Order::class, 'order_id', 'id');
    // }
    //
    // public function customer()
    // {
    //     return $this->belongsTo(Customer::class, 'cust_id', 'id');
    // }
}