<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Authentication routes will be handled by Tyro
Route::middleware(['guest'])->group(function () {
    // Login, register, password reset routes
});

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [App\Http\Controllers\Web\DashboardController::class, 'index'])->name('dashboard');

    // Profile
    Route::get('/profile', [App\Http\Controllers\Web\ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [App\Http\Controllers\Web\ProfileController::class, 'update'])->name('profile.update');
    Route::put('/password', [App\Http\Controllers\Web\ProfileController::class, 'updatePassword'])->name('password.update');

    // Meals
    Route::get('/meals', [App\Http\Controllers\Web\MealController::class, 'index'])->name('meals.index');
    Route::post('/meals', [App\Http\Controllers\Web\MealController::class, 'store'])->name('meals.store');

    // Mess management (admin only)
    Route::middleware(['role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::resource('members', App\Http\Controllers\Web\MemberController::class);
        Route::resource('bazars', App\Http\Controllers\Web\BazarController::class);
        Route::resource('expenses', App\Http\Controllers\Web\ExpenseController::class);
        Route::resource('bills', App\Http\Controllers\Web\BillController::class);
        Route::resource('payments', App\Http\Controllers\Web\PaymentController::class);
    });
});
