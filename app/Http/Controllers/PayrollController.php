<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PayrollController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $runs = DB::table('payroll_runs')->where('tenant_id', $tenant->id)->orderByDesc('period_key')->get()->map(function ($run) {
            $run->employee_count = DB::table('payroll_items')->where('payroll_run_id', $run->id)->count();

            return $run;
        });
        $activeCount = DB::table('employees')->where('tenant_id', $tenant->id)->where('status', 'active')->count();

        return view('payroll.index', compact('runs', 'activeCount'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        if (DB::table('payroll_runs')->where('tenant_id', $tenant->id)->where('period_key', $data['period'])->exists()) {
            throw ValidationException::withMessages(['period' => 'Payroll periode ini sudah dibuat.']);
        }
        $employees = DB::table('employees')->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get();
        if ($employees->isEmpty()) {
            throw ValidationException::withMessages(['period' => 'Belum ada karyawan aktif.']);
        }
        $runId = (string) Str::uuid();
        DB::transaction(function () use ($tenant, $outlet, $data, $employees, $runId) {
            $total = 0.0;
            DB::table('payroll_runs')->insert([
                'id' => $runId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'period_key' => $data['period'], 'status' => 'draft', 'total_net' => 0,
                'created_by_user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($employees as $employee) {
                $net = round((float) $employee->base_salary + (float) $employee->fixed_allowance, 2);
                $total += $net;
                DB::table('payroll_items')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'payroll_run_id' => $runId, 'employee_id' => $employee->id, 'employee_name' => $employee->name,
                    'position' => $employee->position, 'base_salary' => $employee->base_salary, 'allowance' => $employee->fixed_allowance, 'bonus' => 0, 'deduction' => 0,
                    'net_pay' => $net, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('payroll_runs')->where('id', $runId)->update(['total_net' => round($total, 2)]);
        });

        return redirect()->route('payroll.show', $runId)->with('success', 'Draft payroll dibuat. Isi bonus/potongan lalu posting.');
    }

    public function show(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $run = $this->findRun($tenant->id, $id);
        $items = DB::table('payroll_items')->where('payroll_run_id', $id)->orderBy('employee_name')->get();

        return view('payroll.show', compact('run', 'items'));
    }

    public function updateItems(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $run = $this->findRun($tenant->id, $id);
        $this->assertStatus($run, 'draft', 'Payroll yang sudah diposting tidak bisa diubah.');
        $data = $request->validate([
            'items' => ['required', 'array'], 'items.*.bonus' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'items.*.deduction' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'], 'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($id, $data) {
            $total = 0.0;
            foreach (DB::table('payroll_items')->where('payroll_run_id', $id)->get() as $item) {
                $row = $data['items'][$item->id] ?? [];
                $bonus = round((float) ($row['bonus'] ?? 0), 2);
                $deduction = round((float) ($row['deduction'] ?? 0), 2);
                $gross = (float) $item->base_salary + (float) $item->allowance + $bonus;
                if ($deduction > $gross) {
                    throw ValidationException::withMessages(['items' => 'Potongan '.$item->employee_name.' melebihi penghasilan.']);
                }
                $net = round($gross - $deduction, 2);
                $total += $net;
                DB::table('payroll_items')->where('id', $item->id)->update(['bonus' => $bonus, 'deduction' => $deduction, 'net_pay' => $net, 'notes' => $row['notes'] ?? null, 'updated_at' => now()]);
            }
            DB::table('payroll_runs')->where('id', $id)->update(['total_net' => round($total, 2), 'updated_at' => now()]);
        });

        return back()->with('success', 'Rincian payroll disimpan.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $run = $this->findRun($tenant->id, $id);
        $this->assertStatus($run, 'draft', 'Hanya draft yang bisa dihapus.');
        DB::table('payroll_runs')->where('id', $id)->delete();

        return redirect()->route('payroll.index')->with('success', 'Draft payroll dihapus.');
    }

    /**
     * Posting: Dr Gaji & upah (6010) / Cr Hutang Gaji (2020), sebesar gaji bersih.
     */
    public function post(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        DB::transaction(function () use ($tenant, $outlet, $id) {
            $run = DB::table('payroll_runs')->where('tenant_id', $tenant->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($run, 404);
            $this->assertStatus($run, 'draft', 'Payroll ini sudah diposting.');
            $total = round((float) DB::table('payroll_items')->where('payroll_run_id', $id)->sum('net_pay'), 2);
            if ($total <= 0) {
                throw ValidationException::withMessages(['payroll' => 'Total gaji bersih nol; tidak ada yang diposting.']);
            }
            $now = now($tenant->timezone);
            $entryDate = Carbon::createFromFormat('Y-m-d', $run->period_key.'-01', $tenant->timezone)->endOfMonth()->min($now)->toDateString();
            AccountingPeriod::assertOpen($tenant->id, $entryDate);
            $expense = $this->systemAccount($tenant->id, '6010', 'Gaji & upah', 'expense', 'debit', $now);
            $payable = $this->systemAccount($tenant->id, '2020', 'Hutang Gaji', 'liability', 'credit', $now);
            $entryId = $this->journal($tenant->id, $outlet->id, 'PAY-'.$run->period_key, $entryDate, 'Gaji karyawan '.$run->period_key, 'payroll', $id, 'payroll:'.$id, null, [[$expense->id, $total, 0], [$payable->id, 0, $total]], $now);
            DB::table('payroll_runs')->where('id', $id)->update(['status' => 'posted', 'total_net' => $total, 'posted_at' => $now, 'journal_entry_id' => $entryId, 'updated_at' => $now]);
        });

        return back()->with('success', 'Payroll diposting ke jurnal.');
    }

    /**
     * Pembayaran: Dr Hutang Gaji / Cr Kas atau Bank outlet aktif.
     */
    public function pay(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate(['method' => ['required', 'in:cash,bank_transfer']]);
        DB::transaction(function () use ($tenant, $outlet, $id, $data) {
            $run = DB::table('payroll_runs')->where('tenant_id', $tenant->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($run, 404);
            $this->assertStatus($run, 'posted', 'Payroll harus diposting dan belum dibayar.');
            $cashAccount = DB::table('cash_accounts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                ->where('type', $data['method'] === 'cash' ? 'cash' : 'bank')->where('is_active', true)->first();
            if (! $cashAccount || ! $cashAccount->chart_account_id) {
                throw ValidationException::withMessages(['method' => 'Akun kas atau bank belum disiapkan.']);
            }
            $now = now($tenant->timezone);
            $entryDate = $now->toDateString();
            AccountingPeriod::assertOpen($tenant->id, $entryDate);
            $payable = $this->systemAccount($tenant->id, '2020', 'Hutang Gaji', 'liability', 'credit', $now);
            $openShift = $data['method'] === 'cash'
                ? DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('cashier_user_id', auth()->id())->where('status', 'open')->first()
                : null;
            $total = (float) $run->total_net;
            $entryId = $this->journal($tenant->id, $outlet->id, 'PAYP-'.$run->period_key, $entryDate, 'Pembayaran gaji '.$run->period_key, 'payroll_payment', $id, 'payroll-payment:'.$id, $openShift?->id, [[$payable->id, $total, 0], [$cashAccount->chart_account_id, 0, $total]], $now);
            DB::table('payroll_runs')->where('id', $id)->update(['status' => 'paid', 'paid_at' => $now, 'payment_method' => $data['method'], 'payment_journal_entry_id' => $entryId, 'updated_at' => $now]);
        });

        return back()->with('success', 'Pembayaran gaji dicatat.');
    }

    public function slip(Request $request, string $id, string $itemId): View
    {
        $tenant = $request->attributes->get('tenant');
        $run = $this->findRun($tenant->id, $id);
        abort_if($run->status === 'draft', 404);
        $item = DB::table('payroll_items')->where('payroll_run_id', $id)->where('id', $itemId)->first();
        abort_unless($item, 404);

        return view('payroll.slip', compact('run', 'item', 'tenant'));
    }

    private function findRun(string $tenantId, string $id): object
    {
        $run = DB::table('payroll_runs')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($run, 404);

        return $run;
    }

    private function assertStatus(object $run, string $status, string $message): void
    {
        if ($run->status !== $status) {
            throw ValidationException::withMessages(['payroll' => $message]);
        }
    }

    /**
     * @param  array<int, array{0: string, 1: float, 2: float}>  $lines
     */
    private function journal(string $tenantId, string $outletId, string $number, string $date, string $description, string $sourceType, string $sourceId, string $key, ?string $shiftId, array $lines, $now): string
    {
        $entryId = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'id' => $entryId, 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'entry_number' => $number, 'entry_date' => $date, 'status' => 'posted', 'description' => $description,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'idempotency_key' => $key, 'cash_shift_id' => $shiftId,
            'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($lines as [$accountId, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $entryId, 'account_id' => $accountId,
                'description' => $description, 'debit' => $debit, 'credit' => $credit, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $entryId;
    }

    private function systemAccount(string $tenantId, string $code, string $name, string $type, string $normal, $now): object
    {
        $account = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', $code)->first();
        if ($account) {
            return $account;
        }
        DB::table('chart_of_accounts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'account_type' => $type,
            'normal_balance' => $normal, 'is_system' => true, 'allow_manual_posting' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', $code)->first();
    }
}
