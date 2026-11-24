<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeatureHubController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\ShiftController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) return redirect()->route('dashboard');
    return DB::table('users')->count() === 0 ? redirect()->route('setup') : redirect()->route('login');
})->name('home');

Route::get('/setup', [SetupController::class, 'create'])->name('setup');
Route::post('/setup', [SetupController::class, 'store'])->middleware(['guest', 'throttle:5,1'])->name('setup.store');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:8,1')->name('login.store');
});
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/features', FeatureHubController::class)->name('features.index');
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('/pos/checkout', [PosController::class, 'checkout'])->middleware('throttle:30,1')->name('pos.checkout');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->middleware('role:owner,manager,warehouse')->name('products.store');
    Route::get('/products/{id}/edit', [ProductController::class, 'edit'])->middleware('role:owner,manager')->name('products.edit');
    Route::put('/products/{id}', [ProductController::class, 'update'])->middleware('role:owner,manager')->name('products.update');
    Route::post('/products/{id}/archive', [ProductController::class, 'archive'])->middleware('role:owner,manager')->name('products.archive');
    Route::get('/staff', [StaffController::class, 'index'])->middleware('role:owner,manager')->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->middleware('role:owner')->name('staff.store');
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
    Route::post('/shifts/open', [ShiftController::class, 'open'])->name('shifts.open');
    Route::post('/shifts/close', [ShiftController::class, 'close'])->name('shifts.close');
    Route::get('/sales', [SalesController::class, 'index'])->name('sales.index');
    Route::get('/sales/{id}', [SalesController::class, 'show'])->name('sales.show');
    Route::post('/sales/{id}/payments', [SalesController::class, 'collectPayment'])->name('sales.payments.store');
    Route::post('/sales/{id}/void', [SalesController::class, 'void'])->name('sales.void');
});
