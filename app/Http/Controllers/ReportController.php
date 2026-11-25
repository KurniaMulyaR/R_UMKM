<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    /**
     * Jurnal yang sudah dibalik (reversed) tetap dihitung bersama jurnal pembaliknya sehingga efek netonya nol.
     */
    private const COUNTED_STATUSES = ['posted', 'reversed'];

    public function profitLoss(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $tz = $tenant->timezone;
        $valid = fn (?string $value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
        $from = $valid($request->query('from')) ? $request->query('from') : Carbon::now($tz)->startOfMonth()->toDateString();
        $to = $valid($request->query('to')) ? $request->query('to') : Carbon::now($tz)->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.tenant_id', $tenant->id)->whereIn('journal_entries.status', self::COUNTED_STATUSES)
            ->whereBetween('journal_entries.entry_date', [$from, $to])->whereIn('chart_of_accounts.account_type', ['revenue', 'expense'])
            ->groupBy('chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.account_type')
            ->orderBy('chart_of_accounts.code')
            ->selectRaw('chart_of_accounts.code as code, chart_of_accounts.name as name, chart_of_accounts.account_type as type, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->get()
            ->map(function ($row) {
                $row->amount = $row->type === 'revenue' ? (float) $row->credit - (float) $row->debit : (float) $row->debit - (float) $row->credit;

                return $row;
            })->filter(fn ($row) => abs($row->amount) > 0.004)->values();

        $revenue = $rows->where('type', 'revenue');
        $costOfSales = $rows->filter(fn ($row) => str_starts_with($row->code, '5'));
        $operating = $rows->filter(fn ($row) => $row->type === 'expense' && ! str_starts_with($row->code, '5'));
        $totalRevenue = (float) $revenue->sum('amount');
        $totalCost = (float) $costOfSales->sum('amount');
        $totalOperating = (float) $operating->sum('amount');

        return view('reports.profit-loss', [
            'from' => $from, 'to' => $to, 'revenue' => $revenue, 'costOfSales' => $costOfSales, 'operating' => $operating,
            'totalRevenue' => $totalRevenue, 'totalCost' => $totalCost, 'grossProfit' => $totalRevenue - $totalCost,
            'totalOperating' => $totalOperating, 'netProfit' => $totalRevenue - $totalCost - $totalOperating,
        ]);
    }
}
