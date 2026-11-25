<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        ]);
        $now = now($tenant->timezone);
        $interval = $data['maintenance_interval_days'] ?? null;
        DB::table('business_assets')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'code' => $this->nextCode($tenant->id),
            'name' => $data['name'], 'category' => $data['category'], 'serial_number' => $data['serial_number'] ?? null,
            'location' => $data['location'] ?? null, 'purchase_date' => $data['purchase_date'], 'purchase_cost' => $data['purchase_cost'],
            'useful_life_months' => $data['useful_life_months'], 'salvage_value' => $data['salvage_value'] ?? 0,
            'maintenance_interval_days' => $interval,
            'next_maintenance_due' => $interval ? Carbon::parse($data['purchase_date'])->addDays($interval)->toDateString() : null,
            'status' => 'active', 'notes' => $data['notes'] ?? null, 'created_by_user_id' => auth()->id(),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return redirect()->route('assets.index')->with('success', 'Aset berhasil dicatat.');
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

    private function nextCode(string $tenantId): string
    {
        $next = (int) DB::table('business_assets')->where('tenant_id', $tenantId)->count() + 1;
        do {
            $code = 'AST-'.str_pad((string) $next++, 4, '0', STR_PAD_LEFT);
        } while (DB::table('business_assets')->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }
}
