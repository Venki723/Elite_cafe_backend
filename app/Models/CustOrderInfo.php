<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\OrderItem;

class CustOrderInfo extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'id',
        'customer_email',
        'name',
        'phone',
        'type',
        'status',
        'total',
        'payment_method',
        'pincode',
        'locality',
        'address',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
    ];

    // ✅ Add this to define the relationship to OrderItem
public function orderItems()
{
    return $this->hasMany(OrderItem::class, 'cust_id', 'id');
}


}
