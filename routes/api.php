<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Authentication routes
Route::prefix('auth')->middleware('guest')->group(function () {
    Route::post('/login', [App\Http\Controllers\Api\AuthController::class, 'login']);
    Route::post('/register', [App\Http\Controllers\Api\AuthController::class, 'register']);
    Route::post('/forgot-password', [App\Http\Controllers\Api\AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [App\Http\Controllers\Api\AuthController::class, 'resetPassword']);
    Route::post('/refresh', [App\Http\Controllers\Api\AuthController::class, 'refresh']);
});

// Authenticated API routes
Route::middleware('auth:sanctum')->group(function () {
    // User management
    Route::get('/user', [App\Http\Controllers\Api\UserController::class, 'show']);
    Route::put('/user/profile', [App\Http\Controllers\Api\UserController::class, 'updateProfile']);
    Route::put('/user/password', [App\Http\Controllers\Api\UserController::class, 'updatePassword']);

    // Mess management
    Route::apiResource('messes', App\Http\Controllers\Api\MessController::class);
    Route::get('/messes/{mess}/statistics', [App\Http\Controllers\Api\MessController::class, 'statistics']);
    Route::post('/messes/{mess}/members', [App\Http\Controllers\Api\MessController::class, 'addMember']);
    Route::delete('/messes/{mess}/members/{member}', [App\Http\Controllers\Api\MessController::class, 'removeMember']);

    // Mess Member management
    Route::get('/messes/{messId}/members', [App\Http\Controllers\Api\MessMemberController::class, 'index']);
    Route::post('/messes/{messId}/members', [App\Http\Controllers\Api\MessMemberController::class, 'store']);
    Route::get('/messes/{messId}/members/{memberId}', [App\Http\Controllers\Api\MessMemberController::class, 'show']);
    Route::put('/messes/{messId}/members/{memberId}', [App\Http\Controllers\Api\MessMemberController::class, 'update']);
    Route::delete('/messes/{messId}/members/{memberId}', [App\Http\Controllers\Api\MessMemberController::class, 'destroy']);
    Route::post('/messes/{messId}/members/{memberId}/approve', [App\Http\Controllers\Api\MessMemberController::class, 'approve']);
    Route::post('/messes/{messId}/members/{memberId}/reject', [App\Http\Controllers\Api\MessMemberController::class, 'reject']);
    Route::get('/messes/{messId}/members/{memberId}/statistics', [App\Http\Controllers\Api\MessMemberController::class, 'statistics']);
    Route::post('/messes/{messId}/members/bulk-action', [App\Http\Controllers\Api\MessMemberController::class, 'bulkAction']);
    Route::get('/messes/{messId}/members/search', [App\Http\Controllers\Api\MessMemberController::class, 'search']);

    // Meal management
    Route::apiResource('meals', App\Http\Controllers\Api\MealController::class);
    Route::get('/meals/today', [App\Http\Controllers\Api\MealController::class, 'today']);
    Route::post('/meals/today', [App\Http\Controllers\Api\MealController::class, 'enterToday']);
    Route::get('/meals/summary', [App\Http\Controllers\Api\MealController::class, 'summary']);
    Route::post('/meals/lock', [App\Http\Controllers\Api\MealController::class, 'lock']);

    // Bazar management
    Route::get('/bazars', [App\Http\Controllers\Api\BazarController::class, 'index']);
    Route::post('/bazars', [App\Http\Controllers\Api\BazarController::class, 'store']);
    Route::get('/bazars/{id}', [App\Http\Controllers\Api\BazarController::class, 'show']);
    Route::put('/bazars/{id}', [App\Http\Controllers\Api\BazarController::class, 'update']);
    Route::delete('/bazars/{id}', [App\Http\Controllers\Api\BazarController::class, 'destroy']);
    Route::post('/bazars/{id}/receipt', [App\Http\Controllers\Api\BazarController::class, 'uploadReceipt']);
    Route::get('/bazars/report', [App\Http\Controllers\Api\BazarController::class, 'report']);

    // Expense management
    Route::apiResource('expenses', App\Http\Controllers\Api\ExpenseController::class);
    Route::post('/expenses/approve/{id}', [App\Http\Controllers\Api\ExpenseController::class, 'approve']);
    Route::post('/expenses/{id}/receipt', [App\Http\Controllers\Api\ExpenseController::class, 'uploadReceipt']);
    Route::get('/expenses/report', [App\Http\Controllers\Api\ExpenseController::class, 'report']);
    Route::get('/expenses/statistics', [App\Http\Controllers\Api\ExpenseController::class, 'statistics']);
    Route::get('/expenses/categories', [App\Http\Controllers\Api\ExpenseController::class, 'categories']);

    // Billing management
    Route::get('/bills', [App\Http\Controllers\Api\BillController::class, 'index']);
    Route::get('/bills/{id}', [App\Http\Controllers\Api\BillController::class, 'show']);
    Route::post('/bills/generate', [App\Http\Controllers\Api\BillController::class, 'generate']);
    Route::get('/bills/{id}/pdf', [App\Http\Controllers\Api\BillController::class, 'pdf']);

    // Payment management
    Route::apiResource('payments', App\Http\Controllers\Api\PaymentController::class);
    Route::post('/payments/{id}/approve', [App\Http\Controllers\Api\PaymentController::class, 'approve']);
    Route::post('/payments/{id}/receipt', [App\Http\Controllers\Api\PaymentController::class, 'uploadReceipt']);
    Route::get('/payments/history', [App\Http\Controllers\Api\PaymentController::class, 'history']);
    Route::get('/payments/statistics', [App\Http\Controllers\Api\PaymentController::class, 'statistics']);
    Route::get('/payments/methods-summary', [App\Http\Controllers\Api\PaymentController::class, 'paymentMethodsSummary']);
    Route::get('/payments/collection-report', [App\Http\Controllers\Api\PaymentController::class, 'collectionReport']);

    // Dashboard
    Route::get('/dashboard/admin', [App\Http\Controllers\Api\DashboardController::class, 'admin']);
    Route::get('/dashboard/member', [App\Http\Controllers\Api\DashboardController::class, 'member']);

    // Notifications
    Route::get('/notifications', [App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read', [App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);

    // Announcements
    Route::get('/announcements', [App\Http\Controllers\Api\AnnouncementController::class, 'index']);
    Route::post('/announcements', [App\Http\Controllers\Api\AnnouncementController::class, 'store']);
    Route::put('/announcements/{id}/read', [App\Http\Controllers\Api\AnnouncementController::class, 'markAsRead']);

    // Settings
    Route::get('/settings', [App\Http\Controllers\Api\SettingController::class, 'index']);
    Route::put('/settings', [App\Http\Controllers\Api\SettingController::class, 'update']);
    Route::get('/settings/system', [App\Http\Controllers\Api\SettingController::class, 'systemSettings']);
    Route::put('/settings/system', [App\Http\Controllers\Api\SettingController::class, 'updateSystemSettings']);
    Route::post('/settings/reset', [App\Http\Controllers\Api\SettingController::class, 'reset']);
    Route::get('/settings/preferences', [App\Http\Controllers\Api\SettingController::class, 'preferences']);
    Route::put('/settings/preferences', [App\Http\Controllers\Api\SettingController::class, 'updatePreferences']);

    // Export functionality
    Route::get('/export/monthly-report', [App\Http\Controllers\Api\ExportController::class, 'monthlyReport']);
    Route::get('/export/bazar-list', [App\Http\Controllers\Api\ExportController::class, 'bazarList']);
    Route::get('/export/meal-list', [App\Http\Controllers\Api\ExportController::class, 'mealList']);

    // Optional: Inventory management
    Route::prefix('inventory')->group(function () {
        // Inventory Items
        Route::get('/', [App\Http\Controllers\Api\InventoryController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\InventoryController::class, 'store']);
        Route::get('/{id}', [App\Http\Controllers\Api\InventoryController::class, 'show']);
        Route::put('/{id}', [App\Http\Controllers\Api\InventoryController::class, 'update']);
        Route::delete('/{id}', [App\Http\Controllers\Api\InventoryController::class, 'destroy']);

        // Stock Management
        Route::post('/{id}/add-stock', [App\Http\Controllers\Api\InventoryController::class, 'addStock']);
        Route::post('/{id}/remove-stock', [App\Http\Controllers\Api\InventoryController::class, 'removeStock']);

        // Inventory Transactions
        Route::get('/transactions', [App\Http\Controllers\Api\InventoryController::class, 'transactions']);

        // Analytics & Reports
        Route::get('/statistics', [App\Http\Controllers\Api\InventoryController::class, 'statistics']);
        Route::get('/alerts', [App\Http\Controllers\Api\InventoryController::class, 'alerts']);
        Route::get('/categories', [App\Http\Controllers\Api\InventoryController::class, 'categories']);
    });

    // Optional: Attendance management
    Route::prefix('attendance')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\AttendanceController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\AttendanceController::class, 'store']);
        Route::get('/{id}', [App\Http\Controllers\Api\AttendanceController::class, 'show']);
        Route::put('/{id}', [App\Http\Controllers\Api\AttendanceController::class, 'update']);
        Route::delete('/{id}', [App\Http\Controllers\Api\AttendanceController::class, 'destroy']);

        // QR Code Operations
        Route::post('/scan', [App\Http\Controllers\Api\AttendanceController::class, 'scanQR']);
        Route::post('/generate-qr', [App\Http\Controllers\Api\AttendanceController::class, 'generateQR']);
        Route::get('/my-qr-codes', [App\Http\Controllers\Api\AttendanceController::class, 'myQRCodes']);
        Route::put('/qr-codes/{id}/deactivate', [App\Http\Controllers\Api\AttendanceController::class, 'deactivateQR']);

        // Approval Operations
        Route::put('/{id}/approve', [App\Http\Controllers\Api\AttendanceController::class, 'approve']);
        Route::put('/{id}/reject', [App\Http\Controllers\Api\AttendanceController::class, 'reject']);
        Route::get('/pending', [App\Http\Controllers\Api\AttendanceController::class, 'pending']);
        Route::get('/today', [App\Http\Controllers\Api\AttendanceController::class, 'today']);

        // Analytics & Reports
        Route::get('/statistics', [App\Http\Controllers\Api\AttendanceController::class, 'statistics']);
        Route::get('/trends', [App\Http\Controllers\Api\AttendanceController::class, 'trends']);
        Route::get('/peak-times', [App\Http\Controllers\Api\AttendanceController::class, 'peakTimes']);
    });
});
