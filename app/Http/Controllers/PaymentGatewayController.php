<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PaymentGatewayController extends Controller
{
    public function edit(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $setting = DB::table('payment_gateway_settings')->where('tenant_id', $tenant->id)->where('provider', 'xendit')->first();

        return view('payment-gateway.edit', [
            'setting' => $setting, 'hasKeys' => $setting && $setting->secret_key && $setting->callback_token,
            'webhookUrl' => route('webhooks.xendit', $tenant->id),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate([
            'secret_key' => ['nullable', 'string', 'max:200'], 'callback_token' => ['nullable', 'string', 'max:200'], 'is_active' => ['nullable', 'boolean'],
        ]);
        $now = now($tenant->timezone);
        $existing = DB::table('payment_gateway_settings')->where('tenant_id', $tenant->id)->where('provider', 'xendit')->first();
        $secret = ! empty($data['secret_key']) ? Crypt::encryptString($data['secret_key']) : ($existing->secret_key ?? null);
        $token = ! empty($data['callback_token']) ? Crypt::encryptString($data['callback_token']) : ($existing->callback_token ?? null);
        $active = ! empty($data['is_active']) && $secret && $token;

        DB::table('payment_gateway_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'provider' => 'xendit'],
            ['id' => $existing->id ?? (string) Str::uuid(), 'secret_key' => $secret, 'callback_token' => $token, 'is_active' => $active, 'created_at' => $existing->created_at ?? $now, 'updated_at' => $now],
        );

        return back()->with('success', $active ? 'Pengaturan QRIS Xendit disimpan dan diaktifkan.' : 'Pengaturan disimpan. QRIS Xendit belum aktif (lengkapi kunci dan centang aktif).');
    }
}
