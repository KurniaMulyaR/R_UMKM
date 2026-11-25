<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FeatureHubController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\PurchasingController;
use App\Http\Controllers\ReportController;
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

    Route::middleware('role:owner,manager,warehouse')->prefix('purchasing')->name('purchasing.')->group(function () {
        Route::get('/suppliers', [PurchasingController::class, 'suppliers'])->name('suppliers');
        Route::post('/suppliers', [PurchasingController::class, 'storeSupplier'])->name('suppliers.store');
        Route::get('/orders', [PurchasingController::class, 'index'])->name('index');
        Route::get('/orders/create', [PurchasingController::class, 'create'])->name('create');
        Route::post('/orders', [PurchasingController::class, 'store'])->name('store');
        Route::get('/orders/{id}', [PurchasingController::class, 'show'])->name('show');
        Route::post('/orders/{id}/receive', [PurchasingController::class, 'receive'])->name('receive');
    });
    Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss'])->middleware('role:owner,manager')->name('reports.profit-loss');
    Route::middleware('role:owner,manager')->prefix('expenses')->name('expenses.')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('index');
        Route::post('/', [ExpenseController::class, 'store'])->name('store');
        Route::post('/{id}/void', [ExpenseController::class, 'void'])->name('void');
    });
    Route::middleware('role:owner,manager')->prefix('assets')->name('assets.')->group(function () {
        Route::get('/', [AssetController::class, 'index'])->name('index');
        Route::post('/', [AssetController::class, 'store'])->name('store');
        Route::post('/depreciation', [AssetController::class, 'postDepreciation'])->name('depreciation.store');
        Route::get('/{id}', [AssetController::class, 'show'])->name('show');
        Route::post('/{id}/maintenance', [AssetController::class, 'storeMaintenance'])->name('maintenance.store');
        Route::post('/{id}/dispose', [AssetController::class, 'dispose'])->name('dispose');
    });
    Route::middleware('role:owner,manager')->prefix('purchasing')->name('purchasing.')->group(function () {
        Route::post('/orders/{id}/approve', [PurchasingController::class, 'approve'])->name('approve');
        Route::post('/orders/{id}/cancel', [PurchasingController::class, 'cancel'])->name('cancel');
        Route::post('/bills/{billId}/pay', [PurchasingController::class, 'pay'])->name('pay');
        Route::get('/aging', [PurchasingController::class, 'aging'])->name('aging');
        Route::get('/bills/{billId}/return', [PurchaseReturnController::class, 'create'])->name('return.create');
        Route::post('/bills/{billId}/return', [PurchaseReturnController::class, 'store'])->name('return.store');
        Route::post('/returns/{returnId}/refund', [PurchaseReturnController::class, 'refund'])->name('return.refund');
    });
});
