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
use App\Http\Controllers\AccountingPeriodController;
use App\Http\Controllers\ChartOfAccountsController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\QrisChargeController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\XenditWebhookController;
use App\Http\Controllers\StockCountController;
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

Route::post('/webhooks/xendit/{tenantId}', XenditWebhookController::class)->middleware('throttle:120,1')->name('webhooks.xendit');

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
    Route::post('/pos/qris', [QrisChargeController::class, 'store'])->middleware('throttle:20,1')->name('pos.qris.store');
    Route::get('/pos/qris/{id}/status', [QrisChargeController::class, 'status'])->middleware('throttle:120,1')->name('pos.qris.status');
    Route::post('/pos/qris/{id}/complete', [QrisChargeController::class, 'complete'])->middleware('throttle:30,1')->name('pos.qris.complete');
    Route::post('/pos/qris/{id}/cancel', [QrisChargeController::class, 'cancel'])->name('pos.qris.cancel');
    Route::get('/payment-gateway', [PaymentGatewayController::class, 'edit'])->middleware('role:owner')->name('payment-gateway.edit');
    Route::put('/payment-gateway', [PaymentGatewayController::class, 'update'])->middleware('role:owner')->name('payment-gateway.update');
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
    Route::get('/sales/{id}/invoice', [DocumentController::class, 'invoice'])->name('documents.invoice');
    Route::get('/sales/{id}/delivery-note', [DocumentController::class, 'deliveryNote'])->name('documents.delivery-note');
    Route::get('/sales/{id}/receipts/{paymentId}', [DocumentController::class, 'receipt'])->name('documents.receipt');
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
    Route::prefix('stock-counts')->name('stock-counts.')->group(function () {
        Route::get('/', [StockCountController::class, 'index'])->middleware('role:owner,manager,warehouse')->name('index');
        Route::get('/create', [StockCountController::class, 'create'])->middleware('role:owner,manager,warehouse')->name('create');
        Route::post('/', [StockCountController::class, 'store'])->middleware('role:owner,manager,warehouse')->name('store');
        Route::get('/{id}', [StockCountController::class, 'show'])->middleware('role:owner,manager,warehouse')->name('show');
        Route::post('/{id}/approve', [StockCountController::class, 'approve'])->middleware('role:owner,manager')->name('approve');
        Route::post('/{id}/reject', [StockCountController::class, 'reject'])->middleware('role:owner,manager')->name('reject');
    });
    Route::middleware('role:owner,manager')->prefix('reconciliations')->name('reconciliations.')->group(function () {
        Route::get('/', [ReconciliationController::class, 'index'])->name('index');
        Route::post('/', [ReconciliationController::class, 'store'])->name('store');
        Route::get('/{id}', [ReconciliationController::class, 'show'])->name('show');
        Route::put('/{id}', [ReconciliationController::class, 'update'])->name('update');
        Route::delete('/{id}', [ReconciliationController::class, 'destroy'])->name('destroy');
    });
    Route::get('/ledger', [LedgerController::class, 'index'])->middleware('role:owner,manager')->name('ledger.index');
    Route::get('/accounts', [ChartOfAccountsController::class, 'index'])->middleware('role:owner,manager')->name('accounts.index');
    Route::post('/accounts', [ChartOfAccountsController::class, 'store'])->middleware('role:owner')->name('accounts.store');
    Route::prefix('journals')->name('journals.')->group(function () {
        Route::get('/', [JournalController::class, 'index'])->middleware('role:owner,manager')->name('index');
        Route::get('/create', [JournalController::class, 'create'])->middleware('role:owner')->name('create');
        Route::post('/', [JournalController::class, 'store'])->middleware('role:owner')->name('store');
        Route::get('/import', [JournalController::class, 'importForm'])->middleware('role:owner')->name('import');
        Route::get('/import/template', [JournalController::class, 'template'])->middleware('role:owner')->name('template');
        Route::post('/import', [JournalController::class, 'import'])->middleware('role:owner')->name('import.store');
        Route::get('/{id}', [JournalController::class, 'show'])->middleware('role:owner,manager')->name('show');
    });
    Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware('role:owner,manager')->name('reports.balance-sheet');
    Route::post('/reports/equity', [ReportController::class, 'storeEquity'])->middleware('role:owner')->name('reports.equity.store');
    Route::get('/reports/periods', [AccountingPeriodController::class, 'index'])->middleware('role:owner,manager')->name('reports.periods');
    Route::post('/reports/periods/close', [AccountingPeriodController::class, 'close'])->middleware('role:owner')->name('reports.periods.close');
    Route::post('/reports/periods/reopen', [AccountingPeriodController::class, 'reopen'])->middleware('role:owner')->name('reports.periods.reopen');
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
        Route::post('/{id}/acquisition', [AssetController::class, 'acquisition'])->name('acquisition.store');
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
