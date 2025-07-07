<?php
 
namespace App\Http\Controllers;
 
use Illuminate\Http\Request;
use App\Models\MenuInfo;
use App\Models\OrderInfo;
use App\Models\CustOrderInfo;
use Illuminate\Support\Facades\Validator;
 
class CartController extends Controller
{
    public function storeOrder(Request $request)
{
    // Step 1: Validate input
    $validator = Validator::make($request->all(), [
        'name'             => 'required|string|max:255',
        'phone'            => 'required|string|max:15',
        'customer_email'   => 'nullable|email',
        'payment_method'   => 'required|in:cod',
        'type'             => 'required|in:delivery,takeaway',
        'address'          => 'required_if:type,delivery|nullable|string|max:500',
        'pincode'          => 'required_if:type,delivery|nullable|string|max:10',
        'cart'             => 'required|array|min:1',
        'cart.*.name'      => 'required|string',
        'cart.*.price'     => 'required|numeric|min:0',
        'cart.*.quantity'  => 'required|integer|min:1',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Validation failed',
            'errors'  => $validator->errors()
        ], 422);
    }

    $validated = $validator->validated();

    // Step 2: Create customer order
    $order = CustOrderInfo::create([
        'name'            => $validated['name'],
        'phone'           => $validated['phone'],
        'customer_email'  => $validated['customer_email'] ?? null,
        'payment_method'  => $validated['payment_method'],
        'type'            => strtoupper($validated['type']),
        'status'          => 'pending',
        'pincode'         => $validated['type'] === 'delivery' ? ($validated['pincode'] ?? null) : null,
        'address'         => $validated['type'] === 'delivery' ? ($validated['address'] ?? null) : null,
    ]);

    // Step 3: Save each cart item with GST
    $totalAmount = 0;

    foreach ($validated['cart'] as $item) {
        $menuItem = MenuInfo::where('name', $item['name'])->first();

        if ($menuItem) {
            $basePrice = $menuItem->price * $item['quantity'];
            $gst = $basePrice * 0.05; // 5% GST
            $itemTotal = $basePrice + $gst;
            $totalAmount += $itemTotal;

            OrderInfo::create([
                'cust_id'   => $order->id,
                'order_id'  => $menuItem->id,
                'qty'       => $item['quantity'],
                // Optionally, add columns for price and gst in your table:
                // 'price'     => $menuItem->price,
                // 'gst'       => $gst,
            ]);
        }
    }

    // Step 4: Update order total
    $order->update(['total' => round($totalAmount, 2)]);

    // Step 5: Respond
    return response()->json([
        'status'   => 'success',
        'message'  => 'Order placed successfully!',
        'order_id' => $order->id,
        'total'    => round($totalAmount, 2)
    ], 201);
}

 
}
 
 