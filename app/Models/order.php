<?php
// app/Models/Order.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\OrderItem; // Import the OrderItem model

class Order extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'customer_email',
        'name', // This likely maps to customer_name in your JSON
        'phone', // This likely maps to customer_phone in your JSON
        'type',
        'status',
        'total',
        'payment_method',
        'pincode',
        'address',
        // Add 'subtotal', 'gst', 'cafe_charges', 'thank_you' if these are columns in your 'orders' table
        'subtotal',
        'gst',
        'cafe_charges',
        'thank_you',
    ];

    // Define the relationship to your order items
    // This is crucial for the `with('items')` call in your controller
    public function items()
    {
        // An Order has many OrderItems.
        // The foreign key on the 'order_items' table that links back to 'orders' table is 'order_id'.
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    // Define relationship with Customer (if a customer_id column exists on the orders table)
    // Your `customer_email` column suggests you might be relating via email, which is less common
    // than a foreign key `customer_id`. If `customer_email` is just for storing the email
    // and not a foreign key, remove the `belongsTo(Customer::class)` relationship.
    // If you do have a `customer_id` foreign key, update this relationship.
    public function customer()
    {
        // If 'customer_email' in 'orders' table links to 'email' in 'customers' table
        return $this->belongsTo(Customer::class, 'customer_email', 'email');
    }
}