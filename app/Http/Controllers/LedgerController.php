<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LedgerController extends Controller
{
    private const COUNTED_STATUSES = ['posted', 'reversed'];

    private const ROW_LIMIT = 1000;

    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $tz = $tenant->timezone;
        $valid = fn (?string $value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
        $from = $valid($request->query('from')) ? $request->query('from') : Carbon::now($tz)->startOfMonth()->toDateString();
        $to = $valid($request->query('to')) ? $request->query('to') : Carbon::now($tz)->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $accounts = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->orderBy('code')->get(['id', 'code', 'name', 'account_type', 'normal_balance']);
        $account = $accounts->firstWhere('id', $request->query('account')) ?? $accounts->first();

        $rows = collect();
        $opening = 0.0;
        $truncated = false;
        if ($account) {
            $sign = $account->normal_balance === 'credit' ? -1 : 1;
            $opening = $sign * (float) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.tenant_id', $tenant->id)->where('journal_lines.account_id', $account->id)
                ->whereIn('journal_entries.status', self::COUNTED_STATUSES)->where('journal_entries.entry_date', '<', $from)
                ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));
            $lines = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.tenant_id', $tenant->id)->where('journal_lines.account_id', $account->id)
                ->whereIn('journal_entries.status', self::COUNTED_STATUSES)->whereBetween('journal_entries.entry_date', [$from, $to])
                ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.created_at')->orderBy('journal_lines.created_at')
                ->limit(self::ROW_LIMIT + 1)
                ->select('journal_entries.id as entry_id', 'journal_entries.entry_number', 'journal_entries.entry_date', 'journal_entries.description as entry_description',
                    'journal_lines.description', 'journal_lines.debit', 'journal_lines.credit')->get();
            $truncated = $lines->count() > self::ROW_LIMIT;
            $running = $opening;
            $rows = $lines->take(self::ROW_LIMIT)->map(function ($line) use (&$running, $sign) {
                $running = round($running + $sign * ((float) $line->debit - (float) $line->credit), 2);
                $line->balance = $running;

                return $line;
            });
        }

        return view('ledger.index', [
            'accounts' => $accounts, 'account' => $account, 'from' => $from, 'to' => $to, 'opening' => $opening, 'rows' => $rows,
            'totalDebit' => (float) $rows->sum('debit'), 'totalCredit' => (float) $rows->sum('credit'), 'closing' => $rows->isEmpty() ? $opening : (float) $rows->last()->balance, 'truncated' => $truncated,
        ]);
    }
}
