<?php
 
use App\Http\Controllers\ContactUsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminMenuController;
//  use App\Http\Controllers\CheckoutController;
 use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\Api\AdminCustomerApiController;
use App\Http\Controllers\CustomerDetailController;
use App\Http\Controllers\AdminCustomerDetail;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Api\CheckoutApiController;
use App\Http\Controllers\Api\ApiStaffController;
use App\Http\Controllers\OfflineReservation;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TableReservationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\CartController;
use Illuminate\Support\Facades\Auth;





// Route::prefix('admin')->middleware('auth:admin-api')->group(function () {
//     Route::apiResource('staff', ApiStaffController::class);
// });
// use App\Http\Controllers\Api\DashboardStatsController;

// Route::get('/admin/dashboard-counts', [DashboardStatsController::class, 'getCounts']);


Route::middleware('auth:api')->group(function () {
    Route::post('/checkout', [CheckoutApiController::class, 'placeOrder']);
});

// Route::middleware('auth:admin-api')->group(function () {
//     Route::get('/admin/customers', [AdminCustomerApiController::class, 'index']); // List customers
//     Route::get('/admin/customers/{email}', [AdminCustomerApiController::class, 'show']); 
//     // View customer + orders
// });
// Route::middleware('auth:admin-api')->get('/admin/customers', [AdminCustomerApiController::class, 'index']);

// Route::middleware('auth:admin-api')->get('/admin/customers', [AdminCustomerApiController::class, 'index']);
Route::prefix('admin/customers')->middleware(['auth:admin-api'])->group(function () {
    Route::get('/', [AdminCustomerApiController::class, 'index']);
    Route::post('{email}/soft-delete', [AdminCustomerApiController::class, 'softDelete']);
    Route::post('{email}/restore', [AdminCustomerApiController::class, 'restore']);
    Route::post('{email}/suspend', [AdminCustomerApiController::class, 'suspend']);
    Route::post('{email}/unsuspend', [AdminCustomerApiController::class, 'unsuspend']);
    Route::post('{email}/notes', [AdminCustomerApiController::class, 'updateNotes']);
    Route::get('{email}/logins', [AdminCustomerApiController::class, 'loginHistory']);
});

Route::middleware('auth:api')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/profile/update', [AuthController::class, 'updateProfile']);
});
// Protect routes that require user to be logged in
// Route::middleware(['user.auth'])->group(function () {
//     // Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
//     // Route::post('/profile/update', [ProfileController::class, 'update'])->name('profile.update');

//     Route::get('/cartinfo', [CartController::class, 'show_cart'])->name('cart_show');
//     // Route::get('/checkout', [FrontendCheckoutController::class, 'showCheckout'])->name('checkout');
//     // Route::post('/checkout', [FrontendCheckoutController::class, 'processCheckout'])->name('checkout.process');
// });

// api.php


// Route::middleware('auth:admin-api')->group(function () {
//     Route::get('/admin/customers', [UserController::class, 'index']);
// });




Route::middleware('auth:admin-api')->group(function () {
Route::get('/admin/orders', [AdminOrderController::class, 'index']);
});
 
// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::post('/contact', [ContactUsController::class, 'store']); 
// In your API routes (routes/api.php)
// Route::get('/admin/menu/categories', [MenuApiController::class, 'getCategories']);
// Route::get('/admin/menu/subcategories', [MenuApiController::class, 'getSubcategories']);



// Route::get('/menu-items', [MenuItemController::class, 'index']);
 
// Route::get('/menu-items', [MenuItemController::class, 'index']);

// Route::post('/menu-items', [MenuItemController::class, 'store']);
Route::get('/menu',[MenuController::class,'menuinfo']);
// Route::get('/order', [OrderController::class, 'orderinfo'])->name('cart.view');
Route::get('/order', [OrderController::class, 'orderinfo']);

Route::post('/signup',[AuthController::class, 'signup']);
Route::post('/login',[AuthController::class, 'login']);
Route::post('/verify-otp',[AuthController::class, 'otp']);
Route::post('/send-otp',[AuthController::class, 'otp']);
// Route::get('/tableinfo', [TableController::class, 'tableinfo']);

Route::post('/reservationdetails', [ReservationController::class, 'reservationdetails']);



// Route::middleware('auth:sanctum')->group(function() {
//     Route::post('/checkout/dinein', [CheckoutController::class, 'dineIn']);
//     Route::post('/checkout/takeaway', [CheckoutController::class, 'takeaway']);
//     Route::post('/checkout/delivery', [CheckoutController::class, 'delivery']);
//     // Add other cart/order APIs as needed
// });
Route::middleware('auth:customer-api')->get('/customer/profile', function () {
    return response()->json(Auth::guard('customer-api')->user());
});


Route::post('/review',[ReviewController::class,'reviewdata']);
// Admin login
Route::post('/admin/login', [AdminAuthController::class, 'login']);

// Admin menu management (protect with middleware in real app)
// Route::middleware('auth:admin-api')->group(function () {
    Route::get('/admin/menu', [AdminMenuController::class, 'index']);
    Route::post('/admin/menu', [AdminMenuController::class, 'store']);
    Route::put('/admin/menu/{id}', [AdminMenuController::class, 'update']);
    // Route::delete('/admin/menu/{id}', [AdminMenuController::class, 'destroy']);
    // Route::get('/admin/menu/hidden', [AdminMenuController::class, 'hiddenItems']);
    Route::put('/admin/menu/{id}/toggle', [AdminMenuController::class, 'toggleVisibility']);
    


// });

Route::middleware('auth:admin-api')->prefix('admin')->group(function () {
    Route::get('/staff', [ApiStaffController::class, 'index']);            // List all active staff (not deleted)
    Route::post('/staff', [ApiStaffController::class, 'store']);          // Create new staff
    Route::get('/staff/{id}', [ApiStaffController::class, 'show']);       // Show single staff member
    Route::put('/staff/{id}', [ApiStaffController::class, 'update']);     // Update staff member
    Route::delete('/staff/{id}', [ApiStaffController::class, 'destroy']); // Soft delete staff member (mark deleted_at)

    // Soft delete related routes
    Route::get('/staff-removed', [ApiStaffController::class, 'removed']);         // List soft-deleted staff
    Route::patch('/staff/{id}/restore', [ApiStaffController::class, 'restore']);  // Restore soft deleted staff
});

Route::post('/placeorder',[CartController::class,'storeorder']);


// Route::get('/admin/revenue', [RevenueController::class, 'index'])->name('api.admin.revenue');


Route::post('/admin/offlinereservation', [OfflineReservation::class, 'checkAvailability']);
Route::post('/admin/offlinereservationsave', [OfflineReservation::class, 'saveOfflineReservation']);