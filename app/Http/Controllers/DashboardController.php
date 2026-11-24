<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $day = now($tenant->timezone)->toDateString();
        $sales = DB::table('sales')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->where('status', 'completed')->whereDate('sold_at', $day);
        $summary = [
            'revenue' => (clone $sales)->sum('grand_total'),
            'transactions' => (clone $sales)->count(),
            'gross_profit' => DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sales.tenant_id', $tenant->id)->where('sales.outlet_id', $outlet->id)
                ->where('sales.status', 'completed')->whereDate('sales.sold_at', $day)
                ->sum(DB::raw('sale_items.line_total - sale_items.unit_cost_snapshot * sale_items.quantity')),
        ];
        $weekStart = Carbon::now($tenant->timezone)->subDays(6)->startOfDay();
        $dailySales = DB::table('sales')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->where('status', 'completed')->whereBetween('sold_at', [$weekStart, Carbon::now($tenant->timezone)->endOfDay()])
            ->selectRaw('DATE(sold_at) as sale_date, SUM(grand_total) as revenue, COUNT(*) as transactions')
            ->groupByRaw('DATE(sold_at)')->get()->keyBy('sale_date');
        $weeklyActivity = collect(range(6, 0))->map(function (int $daysAgo) use ($tenant, $dailySales) {
            $date = Carbon::now($tenant->timezone)->subDays($daysAgo);
            $record = $dailySales->get($date->toDateString());

            return [
                'date' => $date->toDateString(),
                'label' => $date->locale('id')->translatedFormat('D'),
                'revenue' => (float) ($record->revenue ?? 0),
                'transactions' => (int) ($record->transactions ?? 0),
            ];
        });
        $maxDailyRevenue = max(1, (float) $weeklyActivity->max('revenue'));
        $lowStock = DB::table('products')->join('product_variants', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('stock_balances', function ($join) use ($outlet) {
                $join->on('stock_balances.product_variant_id', '=', 'product_variants.id')->where('stock_balances.outlet_id', '=', $outlet->id);
            })->where('products.tenant_id', $tenant->id)->where('products.is_active', true)->where('products.track_stock', true)
            ->whereRaw('COALESCE(stock_balances.quantity_on_hand, 0) <= COALESCE(products.min_stock, 0)')
            ->select('products.id', 'products.name', 'products.sku', 'products.min_stock', 'product_variants.id as variant_id', 'stock_balances.quantity_on_hand')
            ->orderBy('products.name')->limit(6)->get();
        $recentSales = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.tenant_id', $tenant->id)->where('sales.outlet_id', $outlet->id)
            ->where('sales.status', 'completed')->orderByDesc('sales.sold_at')->limit(6)
            ->select('sales.*', 'customers.name as customer_name')->get();
        $openShift = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->where('cashier_user_id', auth()->id())->where('status', 'open')->first();
        $productCount = DB::table('products')->where('tenant_id', $tenant->id)->where('is_active', true)->whereNull('deleted_at')->count();

        $overview = $this->overview($tenant, $outlet, $summary, $weeklyActivity, $lowStock, $recentSales, $productCount, (bool) $openShift);

        return view('dashboard', compact('tenant', 'outlet', 'summary', 'lowStock', 'recentSales', 'openShift', 'day', 'productCount', 'weeklyActivity', 'maxDailyRevenue', 'overview'));
    }

    /** Data untuk komponen DashboardOverview.vue (kartu kas, grafik, donat, transaksi terbaru). */
    private function overview(object $tenant, object $outlet, array $summary, $weeklyActivity, $lowStock, $recentSales, int $productCount, bool $shiftOpen): array
    {
        $tz = $tenant->timezone;
        $weekStart = Carbon::now($tz)->subDays(6)->startOfDay();

        // Saldo per akun kas = debit - kredit pada akun COA yang ditautkan, hanya jurnal yang sudah diposting.
        $cashAccounts = DB::table('cash_accounts')->where('cash_accounts.tenant_id', $tenant->id)->where('cash_accounts.is_active', true)
            ->whereNotNull('cash_accounts.chart_account_id')
            ->where(fn ($q) => $q->whereNull('cash_accounts.outlet_id')->orWhere('cash_accounts.outlet_id', $outlet->id))
            ->orderBy('cash_accounts.type')->get(['cash_accounts.id', 'cash_accounts.name', 'cash_accounts.type', 'cash_accounts.chart_account_id'])
            ->map(function ($account) use ($tenant) {
                $balance = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->where('journal_lines.tenant_id', $tenant->id)->where('journal_lines.account_id', $account->chart_account_id)
                    ->where('journal_entries.status', 'posted')->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as balance')->value('balance');

                return ['name' => $account->name, 'type' => $account->type, 'balance' => (float) $balance];
            })->values();

        $methodLabels = ['cash' => 'Tunai', 'bank_transfer' => 'Transfer bank', 'qris_external' => 'QRIS', 'credit' => 'Kasbon'];
        $methods = DB::table('payments')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->whereIn('status', ['confirmed', 'pending'])->where('created_at', '>=', $weekStart)
            ->groupBy('method')->selectRaw('method, SUM(amount) as total')->pluck('total', 'method')
            ->map(fn ($total, $method) => ['key' => $method, 'label' => $methodLabels[$method] ?? $method, 'value' => (float) $total])->values();

        $trendStart = Carbon::now($tz)->subDays(29)->startOfDay();
        $trendRows = DB::table('sales')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('status', 'completed')
            ->whereBetween('sold_at', [$trendStart, Carbon::now($tz)->endOfDay()])
            ->selectRaw('DATE(sold_at) as d, SUM(grand_total) as revenue')->groupByRaw('DATE(sold_at)')->pluck('revenue', 'd');
        $trend = collect(range(29, 0))->map(function (int $ago) use ($tz, $trendRows) {
            $date = Carbon::now($tz)->subDays($ago);

            return ['label' => $date->format('d/m'), 'revenue' => (float) ($trendRows[$date->toDateString()] ?? 0)];
        })->values();

        // Untung kotor per hari (7 hari) untuk grafik batang ganda.
        $profitRows = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.tenant_id', $tenant->id)->where('sales.outlet_id', $outlet->id)->where('sales.status', 'completed')
            ->where('sales.sold_at', '>=', $weekStart)
            ->selectRaw('DATE(sales.sold_at) as d, SUM(sale_items.line_total - sale_items.unit_cost_snapshot * sale_items.quantity) as profit')
            ->groupByRaw('DATE(sales.sold_at)')->pluck('profit', 'd');

        return [
            'summary' => ['revenue' => (float) $summary['revenue'], 'transactions' => (int) $summary['transactions'], 'grossProfit' => (float) $summary['gross_profit'], 'products' => $productCount],
            'cashAccounts' => $cashAccounts,
            'week' => $weeklyActivity->map(fn ($day) => $day + ['profit' => (float) ($profitRows[$day['date']] ?? 0)])->values(),
            'methods' => $methods,
            'trend' => $trend,
            'lowStock' => $lowStock->map(fn ($item) => ['name' => $item->name, 'sku' => $item->sku, 'qty' => (float) ($item->quantity_on_hand ?? 0)])->values(),
            'recent' => $recentSales->map(fn ($sale) => [
                'number' => $sale->sale_number, 'url' => route('sales.show', $sale->id),
                'customer' => $sale->customer_name ?: 'Pelanggan umum',
                'time' => Carbon::parse($sale->sold_at)->format('d M, H:i'),
                'total' => (float) $sale->grand_total, 'credit' => (float) $sale->paid_total < (float) $sale->grand_total,
            ])->values(),
            'shiftOpen' => $shiftOpen,
            'urls' => ['pos' => route('pos.index'), 'sales' => route('sales.index'), 'products' => route('products.index'), 'shifts' => route('shifts.index'), 'customers' => route('customers.index'), 'features' => route('features.index')],
        ];
    }
}
