<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class JournalController extends Controller
{
    /** Control accounts are driven by sub-ledgers, so manual postings would desync them. */
    private const PROTECTED_CODES = ['1100', '1200', '2010'];

    private const MAX_IMPORT_ROWS = 2000;

    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $entries = DB::table('journal_entries')->leftJoin('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.tenant_id', $tenant->id)
            ->when($request->query('q'), fn ($query, $term) => $query->where(fn ($inner) => $inner->where('journal_entries.entry_number', 'like', '%'.$term.'%')->orWhere('journal_entries.description', 'like', '%'.$term.'%')))
            ->groupBy('journal_entries.id', 'journal_entries.entry_number', 'journal_entries.entry_date', 'journal_entries.description', 'journal_entries.status', 'journal_entries.source_type', 'journal_entries.created_at')
            ->orderByDesc('journal_entries.entry_date')->orderByDesc('journal_entries.created_at')->limit(100)
            ->select('journal_entries.id', 'journal_entries.entry_number', 'journal_entries.entry_date', 'journal_entries.description', 'journal_entries.status', 'journal_entries.source_type',
                DB::raw('coalesce(sum(journal_lines.debit), 0) as total'))->get();

        return view('journals.index', ['entries' => $entries, 'q' => $request->query('q')]);
    }

    public function show(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $entry = DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($entry, 404);
        $lines = DB::table('journal_lines')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.journal_entry_id', $id)->orderBy('journal_lines.created_at')
            ->select('journal_lines.*', 'chart_of_accounts.code', 'chart_of_accounts.name as account_name')->get();

        return view('journals.show', ['entry' => $entry, 'lines' => $lines]);
    }

    public function create(Request $request): View
    {
        return view('journals.create', ['accounts' => $this->postableAccounts($request->attributes->get('tenant')->id)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'entry_date' => ['required', 'date', 'before_or_equal:today'], 'description' => ['required', 'string', 'max:300'],
            'lines' => ['required', 'array', 'min:2', 'max:50'], 'lines.*.account_id' => ['nullable', 'uuid'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.memo' => ['nullable', 'string', 'max:200'],
        ]);
        $allowed = $this->postableAccounts($tenant->id)->pluck('id')->all();
        $lines = collect($data['lines'])->filter(fn ($line) => ! empty($line['account_id']) && ((float) ($line['debit'] ?? 0) > 0 || (float) ($line['credit'] ?? 0) > 0))->values();
        if ($lines->count() < 2) {
            throw ValidationException::withMessages(['lines' => 'Isi minimal dua baris jurnal dengan akun dan nominal.']);
        }
        foreach ($lines as $line) {
            if (! in_array($line['account_id'], $allowed, true)) {
                throw ValidationException::withMessages(['lines' => 'Ada akun yang tidak boleh diposting manual.']);
            }
            if ((float) ($line['debit'] ?? 0) > 0 && (float) ($line['credit'] ?? 0) > 0) {
                throw ValidationException::withMessages(['lines' => 'Satu baris hanya boleh debit atau kredit, tidak keduanya.']);
            }
        }
        $debit = round($lines->sum(fn ($line) => (float) ($line['debit'] ?? 0)), 2);
        $credit = round($lines->sum(fn ($line) => (float) ($line['credit'] ?? 0)), 2);
        if (abs($debit - $credit) > 0.004) {
            throw ValidationException::withMessages(['lines' => 'Total debit (Rp '.number_format($debit, 0, ',', '.').') harus sama dengan total kredit (Rp '.number_format($credit, 0, ',', '.').').']);
        }
        AccountingPeriod::assertOpen($tenant->id, $data['entry_date']);

        $entryId = DB::transaction(function () use ($tenant, $outlet, $data, $lines) {
            $now = now($tenant->timezone);

            return $this->postEntry($tenant->id, $outlet->id, $this->nextNumber($tenant->id, $outlet->id, $now), $data['entry_date'], $data['description'], 'manual_journal',
                $lines->map(fn ($line) => ['account_id' => $line['account_id'], 'debit' => (float) ($line['debit'] ?? 0), 'credit' => (float) ($line['credit'] ?? 0), 'memo' => $line['memo'] ?? null])->all(), $now);
        });

        return redirect()->route('journals.show', $entryId)->with('success', 'Jurnal diposting.');
    }

    public function importForm(): View
    {
        return view('journals.import');
    }

    public function template(): Response
    {
        $csv = "tanggal,nomor_jurnal,keterangan,kode_akun,debit,kredit,memo\n"
            ."2026-11-01,SALDO-1,Saldo awal kas,1010,5000000,0,\n"
            ."2026-11-01,SALDO-1,Saldo awal kas,3010,0,5000000,\n";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="template-import-jurnal.csv"']);
    }

    public function import(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $rows = $this->readCsv($request->file('file')->getRealPath());
        $accounts = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('is_active', true)->whereNotIn('code', self::PROTECTED_CODES)->pluck('id', 'code');
        $errors = [];
        $groups = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $date = $row['tanggal'] ?? '';
            $key = trim($row['nomor_jurnal'] ?? '');
            $code = trim($row['kode_akun'] ?? '');
            $debit = $this->number($row['debit'] ?? '');
            $credit = $this->number($row['kredit'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
                $errors[] = "Baris $line: tanggal harus berformat YYYY-MM-DD.";
            } elseif ($date > now($tenant->timezone)->toDateString()) {
                $errors[] = "Baris $line: tanggal tidak boleh di masa depan.";
            }
            if ($key === '') {
                $errors[] = "Baris $line: nomor_jurnal wajib diisi.";
            }
            if (! isset($accounts[$code])) {
                $errors[] = "Baris $line: akun $code tidak ada atau tidak boleh diposting manual.";
            }
            if ($debit === null || $credit === null || $debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                $errors[] = "Baris $line: isi salah satu debit atau kredit dengan nominal positif.";
            }
            $groups[$key][] = ['date' => $date, 'description' => trim($row['keterangan'] ?? '') ?: 'Import jurnal '.$key, 'account_id' => $accounts[$code] ?? null,
                'debit' => (float) $debit, 'credit' => (float) $credit, 'memo' => trim($row['memo'] ?? '') ?: null];
        }
        if ($rows === []) {
            $errors[] = 'File kosong atau header tidak sesuai template.';
        }
        foreach ($groups as $key => $group) {
            if (count($group) < 2) {
                $errors[] = "Jurnal $key: minimal dua baris.";
            }
            if (collect($group)->pluck('date')->unique()->count() > 1) {
                $errors[] = "Jurnal $key: semua barisnya harus bertanggal sama.";
            }
            if (abs(collect($group)->sum('debit') - collect($group)->sum('credit')) > 0.004) {
                $errors[] = "Jurnal $key: debit dan kredit tidak seimbang.";
            }
            if (DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('entry_number', 'IMP-'.$key)->exists()) {
                $errors[] = "Jurnal $key: sudah pernah diimpor.";
            }
            try {
                AccountingPeriod::assertOpen($tenant->id, $group[0]['date']);
            } catch (ValidationException $exception) {
                $errors[] = "Jurnal $key: ".$exception->errors()['period'][0];
            }
        }
        if ($errors !== []) {
            return back()->withErrors(['file' => 'Tidak ada jurnal yang diimpor. '.implode(' ', array_slice($errors, 0, 8)).(count($errors) > 8 ? ' (+'.(count($errors) - 8).' masalah lain)' : '')]);
        }

        DB::transaction(function () use ($tenant, $outlet, $groups) {
            $now = now($tenant->timezone);
            foreach ($groups as $key => $group) {
                $this->postEntry($tenant->id, $outlet->id, 'IMP-'.$key, $group[0]['date'], $group[0]['description'], 'manual_import',
                    array_map(fn ($line) => ['account_id' => $line['account_id'], 'debit' => $line['debit'], 'credit' => $line['credit'], 'memo' => $line['memo']], $group), $now);
            }
        });

        return redirect()->route('journals.index')->with('success', count($groups).' jurnal berhasil diimpor.');
    }

    /**
     * @param  array<int, array{account_id: string, debit: float, credit: float, memo: ?string}>  $lines
     */
    private function postEntry(string $tenantId, string $outletId, string $number, string $date, string $description, string $sourceType, array $lines, $now): string
    {
        $entryId = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'id' => $entryId, 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'entry_number' => $number, 'entry_date' => $date, 'status' => 'posted',
            'description' => $description, 'source_type' => $sourceType, 'source_id' => $entryId, 'idempotency_key' => $sourceType.':'.$number,
            'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($lines as $line) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $entryId, 'account_id' => $line['account_id'], 'description' => $line['memo'] ?? $description,
                'debit' => round($line['debit'], 2), 'credit' => round($line['credit'], 2), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'actor_user_id' => auth()->id(), 'action' => 'journal.'.$sourceType,
            'subject_type' => 'journal_entry', 'subject_id' => $entryId, 'after' => json_encode(['entry_number' => $number]), 'occurred_at' => $now, 'created_at' => $now,
        ]);

        return $entryId;
    }

    private function postableAccounts(string $tenantId)
    {
        return DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('is_active', true)->whereNotIn('code', self::PROTECTED_CODES)->orderBy('code')->get(['id', 'code', 'name']);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $first = fgets($handle);
        if ($first === false) {
            return [];
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(fn ($name) => strtolower(trim($name)), str_getcsv(trim($first), $delimiter));
        $rows = [];
        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($cells === [null] || count(array_filter($cells, fn ($cell) => trim((string) $cell) !== '')) === 0) {
                continue;
            }
            if (count($rows) >= self::MAX_IMPORT_ROWS) {
                throw ValidationException::withMessages(['file' => 'Maksimal '.self::MAX_IMPORT_ROWS.' baris per file.']);
            }
            $rows[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
        }
        fclose($handle);
        if (array_diff(['tanggal', 'nomor_jurnal', 'kode_akun', 'debit', 'kredit'], $header) !== []) {
            return [];
        }

        return $rows;
    }

    private function number(string $value): ?float
    {
        $value = trim(str_replace(['Rp', ' '], '', $value));
        if ($value === '') {
            return 0.0;
        }
        if (str_contains($value, '.') && str_contains($value, ',')) {
            $value = str_replace(',', '.', str_replace('.', '', $value));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace('.', '', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function nextNumber(string $tenantId, string $outletId, $now): string
    {
        $scope = ['tenant_id' => $tenantId, 'outlet_id' => $outletId, 'document_type' => 'manual_journal', 'period_key' => $now->format('Y')];
        $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        if (! $counter) {
            DB::table('document_counters')->insert($scope + ['id' => (string) Str::uuid(), 'prefix' => 'JMN', 'next_number' => 1, 'padding' => 5, 'created_at' => $now, 'updated_at' => $now]);
            $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        }
        DB::table('document_counters')->where('id', $counter->id)->update(['next_number' => $counter->next_number + 1, 'updated_at' => $now]);

        return $counter->prefix.'/'.$scope['period_key'].'/'.str_pad((string) $counter->next_number, $counter->padding, '0', STR_PAD_LEFT);
    }
}
