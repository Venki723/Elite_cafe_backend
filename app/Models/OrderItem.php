<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\MenuInfo; // Import the MenuInfo model
use App\Models\Order;    // Import the Order model

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'menu_item_id',
        'item_name',
        'item_price', // Rename from 'price' for consistency if your column is item_price
        'quantity',
        // Add 'total' if you store it directly in the order_items table
        // 'total',
    ];

    public $timestamps = false; // Assuming your 'order_items' table does NOT have created_at/updated_at columns.
                                // If it does, remove this line.

    // Define relationship with Order (inverse of Order's hasMany)
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    // Define relationship with MenuItem (MenuInfo in your case)
    // An OrderItem belongs to a MenuInfo item
    public function menuItem()
    {
        // 'menu_item_id' is the foreign key on 'order_items' table
        // 'id' is the local key on 'menu_info' table
        return $this->belongsTo(MenuInfo::class, 'menu_item_id', 'id');
    }
}