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

        return view('dashboard', compact('tenant', 'outlet', 'summary', 'lowStock', 'recentSales', 'openShift', 'day', 'productCount', 'weeklyActivity', 'maxDailyRevenue'));
    }
}
