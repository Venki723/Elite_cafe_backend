<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CustOrderInfo;
use App\Models\OrderItem;
use App\Models\MenuInfo;
use Carbon\Carbon;

class AdminOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = CustOrderInfo::with('orderItems'); // eager load

        $dateFilter = $request->query('date_filter');

        switch ($dateFilter) {
            case 'today':
                $query->whereDate('created_at', Carbon::today());
                break;
            case 'this_week':
                $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
                break;
            case 'this_month':
                $query->whereYear('created_at', Carbon::now()->year)
                      ->whereMonth('created_at', Carbon::now()->month);
                break;
            case 'last_month':
                $lastMonth = Carbon::now()->subMonth();
                $query->whereYear('created_at', $lastMonth->year)
                      ->whereMonth('created_at', $lastMonth->month);
                break;
            case 'last_3_months':
                $query->where('created_at', '>=', Carbon::now()->subMonths(3));
                break;
        }

        $orders = $query->orderBy('created_at', 'desc')->get();

        $detailedOrders = $orders->map(function ($order) {
            $items = [];
            $subtotal = 0;

            foreach ($order->orderItems as $index => $item) {
                $menu = MenuInfo::find($item->cust_id);
                $name = $menu->name ?? 'Unknown Item';
                $price = $menu->price ?? 0;
                $qty = $item->qty ?? 0;

                $totalPerItem = $price * $qty;
                $subtotal += $totalPerItem;

                $items[] = [
                    'sno'        => $index + 1,
                    'item_name'  => $name,
                    'quantity'   => $qty,
                    'price'      => round($price, 2),
                    'total'      => round($totalPerItem, 2),
                ];
            }

            $gst = $subtotal * 0.10;
            $cafeCharges = $subtotal * 0.05;
            $grandTotal = $subtotal + $gst + $cafeCharges;

            return [
                'id' => $order->id,
                'customer_name'  => $order->name,
                'customer_phone' => $order->phone,
                 'type'          => $order->type,
                'items'          => $items,
                'payment_method' => $order->payment_method,
                'subtotal'       => round($subtotal, 2),
                'gst'            => round($gst, 2),
                'cafe_charges'   => round($cafeCharges, 2),
                'total'          => round($grandTotal, 2),
                'thank_you'      => 'Thank you for ordering with EliteCafe!',
                'created_at'     => $order->created_at->format('d M Y, h:i A'),
            ];
        });

        return response()->json($detailedOrders);
    }
}
