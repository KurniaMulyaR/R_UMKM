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

class AssetController extends Controller
{
    private const CATEGORIES = [
        'equipment' => 'Peralatan', 'vehicle' => 'Kendaraan', 'electronics' => 'Elektronik',
        'furniture' => 'Perabot', 'building' => 'Bangunan', 'other' => 'Lainnya',
    ];

    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $today = Carbon::now($tenant->timezone)->startOfDay();
        $assets = DB::table('business_assets')->where('tenant_id', $tenant->id)
            ->orderByRaw("CASE status WHEN 'disposed' THEN 1 ELSE 0 END")->orderBy('name')->get()
            ->map(fn ($asset) => $this->present($asset, $today));
        $active = $assets->where('status', '!=', 'disposed');
        $summary = [
            'count' => $active->count(),
            'cost' => (float) $active->sum('purchase_cost'),
            'bookValue' => (float) $active->sum('book_value'),
            'dueSoon' => $active->filter(fn ($asset) => $asset->maintenance_state !== 'ok')->count(),
        ];

        return view('assets.index', ['assets' => $assets, 'summary' => $summary, 'categories' => self::CATEGORIES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'category' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'serial_number' => ['nullable', 'string', 'max:128'], 'location' => ['nullable', 'string', 'max:160'],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'], 'purchase_cost' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:600'], 'salvage_value' => ['nullable', 'numeric', 'min:0', 'lte:purchase_cost'],
            'maintenance_interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'notes' => ['nullable', 'string', 'max:2000'],
            'funding' => ['required', 'in:none,cash,bank_transfer,owner_equity'],
        ]);
        $now = now($tenant->timezone);
        $interval = $data['maintenance_interval_days'] ?? null;
        $assetId = (string) Str::uuid();
        DB::table('business_assets')->insert([
            'id' => $assetId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'code' => $this->nextCode($tenant->id),
            'name' => $data['name'], 'category' => $data['category'], 'serial_number' => $data['serial_number'] ?? null,
            'location' => $data['location'] ?? null, 'purchase_date' => $data['purchase_date'], 'purchase_cost' => $data['purchase_cost'],
            'useful_life_months' => $data['useful_life_months'], 'salvage_value' => $data['salvage_value'] ?? 0,
            'maintenance_interval_days' => $interval,
            'next_maintenance_due' => $interval ? Carbon::parse($data['purchase_date'])->addDays($interval)->toDateString() : null,
            'status' => 'active', 'notes' => $data['notes'] ?? null, 'created_by_user_id' => auth()->id(),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($data['funding'] !== 'none') {
            DB::transaction(fn () => $this->postAcquisition($tenant, $outlet, $assetId, $data['funding'], $now), attempts: 3);
        }

        return redirect()->route('assets.index')->with('success', $data['funding'] === 'none' ? 'Aset berhasil dicatat tanpa jurnal perolehan.' : 'Aset dicatat dan jurnal perolehan diposting.');
    }

    public function acquisition(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate(['funding' => ['required', 'in:cash,bank_transfer,owner_equity']]);
        DB::transaction(fn () => $this->postAcquisition($tenant, $outlet, $id, $data['funding'], now($tenant->timezone)), attempts: 3);

        return back()->with('success', 'Jurnal perolehan aset diposting.');
    }

    /**
     * Dr Aset Tetap (1500); Cr kas, bank, atau Modal Pemilik (aset disetor pemilik).
     */
    private function postAcquisition(object $tenant, object $outlet, string $assetId, string $funding, $now): void
    {
        $asset = DB::table('business_assets')->where('tenant_id', $tenant->id)->where('id', $assetId)->lockForUpdate()->first();
        abort_unless($asset, 404);
        abort_if($asset->acquisition_journal_entry_id, 422, 'Jurnal perolehan aset ini sudah diposting.');
        $cost = round((float) $asset->purchase_cost, 2);
        if ($cost <= 0) {
            throw ValidationException::withMessages(['funding' => 'Harga beli nol tidak perlu dijurnal.']);
        }
        AccountingPeriod::assertOpen($tenant->id, $asset->purchase_date);
        if ($funding === 'owner_equity') {
            $creditAccount = $this->systemAccount($tenant->id, '3010', 'Modal Pemilik', 'equity', 'credit', $now);
        } else {
            $cashAccount = DB::table('cash_accounts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                ->where('type', $funding === 'cash' ? 'cash' : 'bank')->where('is_active', true)->first();
            if (! $cashAccount || ! $cashAccount->chart_account_id) {
                throw ValidationException::withMessages(['funding' => 'Akun kas atau bank belum disiapkan.']);
            }
            $creditAccount = (object) ['id' => $cashAccount->chart_account_id];
        }
        $fixedAssets = $this->systemAccount($tenant->id, '1500', 'Aset Tetap', 'asset', 'debit', $now);
        $entryId = (string) Str::uuid();
        $openShift = $funding === 'cash'
            ? DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('cashier_user_id', auth()->id())->where('status', 'open')->first()
            : null;
        DB::table('journal_entries')->insert([
            'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => 'ACQ-'.$asset->code,
            'entry_date' => $asset->purchase_date, 'status' => 'posted', 'description' => 'Perolehan aset '.$asset->name,
            'source_type' => 'asset_acquisition', 'source_id' => $asset->id, 'idempotency_key' => 'asset-acquisition:'.$asset->id, 'cash_shift_id' => $openShift?->id,
            'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([[$fixedAssets->id, $cost, 0], [$creditAccount->id, 0, $cost]] as [$accountId, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'account_id' => $accountId,
                'description' => 'Perolehan '.$asset->code, 'debit' => $debit, 'credit' => $credit, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('business_assets')->where('id', $asset->id)->update(['acquisition_journal_entry_id' => $entryId, 'updated_at' => $now]);
    }

    /**
     * Posting penyusutan garis lurus per bulan (Dr Beban Penyusutan, Cr Akumulasi Penyusutan).
     * Mulai bulan setelah pembelian, berhenti di bulan pelepasan, dan bulan yang terlewat ikut disusulkan.
     */
    public function postDepreciation(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        $target = Carbon::createFromFormat('Y-m-d', $data['period'].'-01', $tenant->timezone)->startOfMonth();
        if ($target->gt(Carbon::now($tenant->timezone)->startOfMonth())) {
            throw ValidationException::withMessages(['period' => 'Penyusutan tidak bisa diposting untuk bulan yang belum berjalan.']);
        }

        $posted = DB::transaction(function () use ($tenant, $outlet, $target) {
            $now = now($tenant->timezone);
            $expense = $this->systemAccount($tenant->id, '6080', 'Beban Penyusutan', 'expense', 'debit', $now);
            $accumulated = $this->systemAccount($tenant->id, '1590', 'Akumulasi Penyusutan', 'asset', 'credit', $now);
            $count = 0;
            $assets = DB::table('business_assets')->where('tenant_id', $tenant->id)->lockForUpdate()->get();
            foreach ($assets as $asset) {
                $depreciable = round(max(0.0, (float) $asset->purchase_cost - (float) $asset->salvage_value), 2);
                if ($depreciable <= 0) {
                    continue;
                }
                $monthly = round($depreciable / max(1, (int) $asset->useful_life_months), 2);
                $postedTotal = (float) DB::table('asset_depreciation_entries')->where('business_asset_id', $asset->id)->sum('amount');
                $postedPeriods = DB::table('asset_depreciation_entries')->where('business_asset_id', $asset->id)->pluck('period')->all();
                $cursor = Carbon::parse($asset->purchase_date)->startOfMonth()->addMonth();
                $last = $asset->disposed_at ? Carbon::parse($asset->disposed_at)->startOfMonth() : $target->copy();
                $last = $last->min($target);
                for (; $cursor->lte($last); $cursor->addMonth()) {
                    $period = $cursor->format('Y-m');
                    if (in_array($period, $postedPeriods, true)) {
                        continue;
                    }
                    $amount = round(min($monthly, $depreciable - $postedTotal), 2);
                    if ($amount <= 0) {
                        break;
                    }
                    $entryId = (string) Str::uuid();
                    $entryDate = $cursor->copy()->endOfMonth()->min($now)->toDateString();
                    AccountingPeriod::assertOpen($tenant->id, $entryDate);
                    DB::table('journal_entries')->insert([
                        'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => 'DEP-'.$asset->code.'-'.$period,
                        'entry_date' => $entryDate, 'status' => 'posted', 'description' => 'Penyusutan '.$asset->name.' '.$period,
                        'source_type' => 'asset_depreciation', 'source_id' => $asset->id, 'idempotency_key' => 'asset-depreciation:'.$asset->id.':'.$period,
                        'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    foreach ([[$expense->id, $amount, 0], [$accumulated->id, 0, $amount]] as [$accountId, $debit, $credit]) {
                        DB::table('journal_lines')->insert([
                            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'account_id' => $accountId,
                            'description' => 'Penyusutan '.$asset->code.' '.$period, 'debit' => $debit, 'credit' => $credit, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                    DB::table('asset_depreciation_entries')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'business_asset_id' => $asset->id, 'period' => $period,
                        'amount' => $amount, 'journal_entry_id' => $entryId, 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $postedTotal += $amount;
                    $count++;
                }
            }

            return $count;
        }, attempts: 3);

        return back()->with('success', $posted > 0 ? $posted.' jurnal penyusutan diposting.' : 'Tidak ada penyusutan baru untuk diposting.');
    }

    public function show(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $asset = DB::table('business_assets')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($asset, 404);
        $records = DB::table('asset_maintenance_records')->where('tenant_id', $tenant->id)->where('business_asset_id', $id)
            ->orderByDesc('performed_at')->orderByDesc('created_at')->get();

        return view('assets.show', [
            'asset' => $this->present($asset, Carbon::now($tenant->timezone)->startOfDay()), 'records' => $records,
            'depreciations' => DB::table('asset_depreciation_entries')->where('business_asset_id', $id)->orderByDesc('period')->get(),
            'categories' => self::CATEGORIES, 'maintenanceCost' => (float) $records->sum('cost'),
        ]);
    }

    public function storeMaintenance(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $asset = DB::table('business_assets')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($asset && $asset->status !== 'disposed', 404);
        $data = $request->validate([
            'performed_at' => ['required', 'date', 'before_or_equal:today'], 'description' => ['required', 'string', 'max:500'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'], 'performed_by' => ['nullable', 'string', 'max:160'],
        ]);
        $now = now($tenant->timezone);
        DB::transaction(function () use ($tenant, $asset, $data, $now) {
            DB::table('asset_maintenance_records')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'business_asset_id' => $asset->id, 'performed_at' => $data['performed_at'],
                'description' => $data['description'], 'cost' => $data['cost'] ?? 0, 'performed_by' => $data['performed_by'] ?? null,
                'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($asset->maintenance_interval_days) {
                $next = Carbon::parse($data['performed_at'])->addDays($asset->maintenance_interval_days)->toDateString();
                if (! $asset->next_maintenance_due || $next > $asset->next_maintenance_due || $asset->next_maintenance_due <= $data['performed_at']) {
                    DB::table('business_assets')->where('id', $asset->id)->update(['next_maintenance_due' => $next, 'updated_at' => $now]);
                }
            }
        });

        return back()->with('success', 'Riwayat perawatan dicatat.');
    }

    public function dispose(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $updated = DB::table('business_assets')->where('tenant_id', $tenant->id)->where('id', $id)->where('status', '!=', 'disposed')->update([
            'status' => 'disposed', 'disposed_at' => now($tenant->timezone)->toDateString(), 'disposal_reason' => $data['reason'],
            'next_maintenance_due' => null, 'updated_at' => now(),
        ]);
        abort_unless($updated, 422, 'Aset ini sudah dilepas.');

        return back()->with('success', 'Aset ditandai sudah dilepas.');
    }

    /**
     * Umur dan nilai buku garis lurus dihitung saat ditampilkan; belum ada jurnal penyusutan.
     */
    private function present(object $asset, Carbon $today): object
    {
        $purchased = Carbon::parse($asset->purchase_date)->startOfDay();
        $end = $asset->disposed_at ? Carbon::parse($asset->disposed_at)->startOfDay() : $today;
        $months = max(0, (int) $purchased->diffInMonths($end));
        $depreciable = max(0.0, (float) $asset->purchase_cost - (float) $asset->salvage_value);
        $ratio = min(1.0, $months / max(1, (int) $asset->useful_life_months));
        $asset->age_months = $months;
        $asset->age_label = intdiv($months, 12) > 0 ? intdiv($months, 12).' th '.($months % 12).' bln' : $months.' bln';
        $asset->book_value = round((float) $asset->purchase_cost - $depreciable * $ratio, 2);
        $asset->accumulated = round($depreciable * $ratio, 2);
        $asset->life_used_percent = (int) round($ratio * 100);
        $asset->maintenance_state = 'ok';
        if ($asset->status !== 'disposed' && $asset->next_maintenance_due) {
            $due = Carbon::parse($asset->next_maintenance_due)->startOfDay();
            $asset->maintenance_state = $due->lt($today) ? 'overdue' : ($due->lte($today->copy()->addDays(14)) ? 'soon' : 'ok');
        }

        return $asset;
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

    private function nextCode(string $tenantId): string
    {
        $next = (int) DB::table('business_assets')->where('tenant_id', $tenantId)->count() + 1;
        do {
            $code = 'AST-'.str_pad((string) $next++, 4, '0', STR_PAD_LEFT);
        } while (DB::table('business_assets')->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }
}
