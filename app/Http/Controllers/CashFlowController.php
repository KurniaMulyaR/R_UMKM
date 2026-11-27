<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashFlowController extends Controller
{
    private const CASH_CODES = ['1010', '1020', '1030'];

    private const COUNTED_STATUSES = ['posted', 'reversed'];

    private const SECTIONS = ['operating' => 'Arus kas dari operasional', 'investing' => 'Arus kas dari investasi', 'financing' => 'Arus kas dari pendanaan'];

    public function index(Request $request): mixed
    {
        $tenant = $request->attributes->get('tenant');
        $tz = $tenant->timezone;
        $valid = fn (?string $value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
        $from = $valid($request->query('from')) ? $request->query('from') : Carbon::now($tz)->startOfMonth()->toDateString();
        $to = $valid($request->query('to')) ? $request->query('to') : Carbon::now($tz)->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $report = $this->build($tenant->id, $from, $to);

        if ($request->query('format') === 'csv') {
            return $this->csv($report, $from, $to);
        }

        return view('reports.cash-flow', $report + ['from' => $from, 'to' => $to, 'sectionLabels' => self::SECTIONS]);
    }

    /**
     * Metode langsung: setiap jurnal yang menyentuh Kas/Bank/QRIS dikelompokkan menurut akun lawan terbesarnya.
     *
     * @return array{sections: array<string, array<string, array{name: string, in: float, out: float}>>, totals: array<string, float>, opening: float, closing: float, net: float, byAccount: array<int, array{name: string, opening: float, closing: float}>}
     */
    public function build(string $tenantId, string $from, string $to): array
    {
        $cashLines = fn () => DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.tenant_id', $tenantId)->whereIn('journal_entries.status', self::COUNTED_STATUSES)
            ->whereIn('chart_of_accounts.code', self::CASH_CODES);

        $deltas = $cashLines()->whereBetween('journal_entries.entry_date', [$from, $to])->groupBy('journal_lines.journal_entry_id')
            ->selectRaw('journal_lines.journal_entry_id as entry_id, SUM(journal_lines.debit - journal_lines.credit) as delta')->pluck('delta', 'entry_id');

        $sections = array_fill_keys(array_keys(self::SECTIONS), []);
        foreach ($deltas->filter(fn ($delta) => abs((float) $delta) > 0.004)->keys()->chunk(400) as $chunk) {
            $others = DB::table('journal_lines')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
                ->whereIn('journal_lines.journal_entry_id', $chunk->all())->whereNotIn('chart_of_accounts.code', self::CASH_CODES)
                ->get(['journal_lines.journal_entry_id as entry_id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.account_type', 'journal_lines.debit', 'journal_lines.credit'])
                ->groupBy('entry_id');
            foreach ($chunk as $entryId) {
                $delta = (float) $deltas[$entryId];
                $counter = ($others[$entryId] ?? collect())->groupBy('code')->map(fn ($lines) => [
                    'code' => $lines->first()->code, 'name' => $lines->first()->name, 'type' => $lines->first()->account_type,
                    'size' => abs((float) $lines->sum('debit') - (float) $lines->sum('credit')),
                ])->sortByDesc('size')->first() ?? ['code' => '-', 'name' => 'Tanpa akun lawan', 'type' => 'asset'];
                $section = match (true) {
                    $counter['type'] === 'equity' => 'financing',
                    str_starts_with($counter['code'], '15') => 'investing',
                    default => 'operating',
                };
                $key = $counter['code'];
                $row = $sections[$section][$key] ?? ['name' => $counter['code'].' · '.$counter['name'], 'in' => 0.0, 'out' => 0.0];
                $delta >= 0 ? $row['in'] += $delta : $row['out'] += -$delta;
                $sections[$section][$key] = $row;
            }
        }
        foreach ($sections as $name => $rows) {
            ksort($rows);
            $sections[$name] = $rows;
        }
        $totals = array_map(fn ($rows) => round(array_sum(array_map(fn ($row) => $row['in'] - $row['out'], $rows)), 2), $sections);
        $net = round(array_sum($totals), 2);

        $balanceBefore = fn (string $date, string $op) => $cashLines()->where('journal_entries.entry_date', $op, $date)->groupBy('chart_of_accounts.code', 'chart_of_accounts.name')
            ->selectRaw('chart_of_accounts.code as code, chart_of_accounts.name as name, SUM(journal_lines.debit - journal_lines.credit) as balance')->get()->keyBy('code');
        $opening = $balanceBefore($from, '<');
        $closing = $balanceBefore($to, '<=');
        $accountNames = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)
            ->whereIn('code', self::CASH_CODES)->pluck('name', 'code');
        $byAccount = [];
        foreach (self::CASH_CODES as $code) {
            $label = ($closing[$code] ?? $opening[$code] ?? null)?->name ?? $accountNames[$code] ?? $code;
            $byAccount[] = ['name' => $code.' · '.$label, 'opening' => round((float) ($opening[$code]->balance ?? 0), 2), 'closing' => round((float) ($closing[$code]->balance ?? 0), 2)];
        }

        return [
            'sections' => $sections, 'totals' => $totals, 'net' => $net, 'byAccount' => $byAccount,
            'opening' => round(array_sum(array_column($byAccount, 'opening')), 2), 'closing' => round(array_sum(array_column($byAccount, 'closing')), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function csv(array $report, string $from, string $to): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Bagian', 'Akun lawan', 'Masuk', 'Keluar', 'Bersih']);
            foreach (self::SECTIONS as $key => $label) {
                foreach ($report['sections'][$key] as $row) {
                    fputcsv($out, [$label, $row['name'], $row['in'], $row['out'], round($row['in'] - $row['out'], 2)]);
                }
                fputcsv($out, [$label, 'Total', '', '', $report['totals'][$key]]);
            }
            fputcsv($out, ['Kenaikan (penurunan) kas bersih', '', '', '', $report['net']]);
            fputcsv($out, ['Saldo kas awal', '', '', '', $report['opening']]);
            fputcsv($out, ['Saldo kas akhir', '', '', '', $report['closing']]);
            fclose($out);
        }, "arus-kas-{$from}-sd-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
