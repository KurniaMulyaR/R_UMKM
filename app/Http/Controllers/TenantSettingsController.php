<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TenantSettingsController extends Controller
{
    public const TIMEZONES = ['Asia/Jakarta' => 'WIB (Asia/Jakarta)', 'Asia/Makassar' => 'WITA (Asia/Makassar)', 'Asia/Jayapura' => 'WIT (Asia/Jayapura)'];

    public function edit(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $settings = json_decode((string) $tenant->settings, true) ?: [];

        return view('settings.edit', ['tenant' => $tenant, 'settings' => $settings, 'timezones' => self::TIMEZONES]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'legal_name' => ['nullable', 'string', 'max:200'], 'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'], 'timezone' => ['required', 'in:'.implode(',', array_keys(self::TIMEZONES))], 'document_footer' => ['nullable', 'string', 'max:300'], 'receipt_width' => ['required', 'in:58,80'],
            'ppn_enabled' => ['nullable', 'boolean'], 'ppn_rate' => ['nullable', 'numeric', 'gt:0', 'max:100'], 'ppn_mode' => ['nullable', 'in:exclusive,inclusive'],
        ]);
        $settings = json_decode((string) $tenant->settings, true) ?: [];
        $settings['document_footer'] = $data['document_footer'] ?? null;
        $settings['receipt_width'] = (int) $data['receipt_width'];
        $settings['ppn_enabled'] = $request->boolean('ppn_enabled');
        $settings['ppn_rate'] = (float) ($data['ppn_rate'] ?? 11);
        $settings['ppn_mode'] = $data['ppn_mode'] ?? 'exclusive';
        $now = now();
        DB::transaction(function () use ($tenant, $data, $settings, $request, $now) {
            DB::table('tenants')->where('id', $tenant->id)->update([
                'name' => $data['name'], 'legal_name' => $data['legal_name'] ?? null, 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null,
                'timezone' => $data['timezone'], 'settings' => json_encode($settings), 'updated_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $request->attributes->get('outlet')->id ?? null, 'actor_user_id' => auth()->id(),
                'action' => 'tenant.updated', 'subject_type' => 'tenant', 'subject_id' => $tenant->id, 'before' => json_encode(['name' => $tenant->name, 'timezone' => $tenant->timezone]),
                'after' => json_encode(['name' => $data['name'], 'timezone' => $data['timezone']]), 'ip_address' => $request->ip(), 'occurred_at' => $now,
            ]);
        });

        return back()->with('success', 'Pengaturan usaha disimpan.');
    }
}
