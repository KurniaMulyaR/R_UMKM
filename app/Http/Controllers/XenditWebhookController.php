<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class XenditWebhookController extends Controller
{
    /** Statuses Xendit reports for a completed QR payment (v2 uses SUCCEEDED, legacy COMPLETED). */
    private const PAID_STATUSES = ['SUCCEEDED', 'COMPLETED'];

    public function __invoke(Request $request, string $tenantId): JsonResponse
    {
        $setting = DB::table('payment_gateway_settings')->where('tenant_id', $tenantId)->where('provider', 'xendit')->where('is_active', true)->first();
        $expected = $setting && $setting->callback_token ? Crypt::decryptString($setting->callback_token) : null;
        $received = (string) $request->header('x-callback-token', '');
        if (! $expected || ! hash_equals($expected, $received)) {
            return response()->json(['message' => 'Token callback tidak valid.'], 401);
        }

        $payload = $request->json()->all();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $reference = $data['reference_id'] ?? ($data['qr_code']['external_id'] ?? ($data['external_id'] ?? null));
        $status = strtoupper((string) ($data['status'] ?? ''));
        if (! $reference || ! in_array($status, self::PAID_STATUSES, true)) {
            return response()->json(['message' => 'Diabaikan.']);
        }

        DB::transaction(function () use ($tenantId, $reference, $data, $payload) {
            $charge = DB::table('payment_gateway_charges')->where('tenant_id', $tenantId)->where('reference_id', $reference)->lockForUpdate()->first();
            if (! $charge || in_array($charge->status, ['paid', 'used'], true)) {
                return;
            }
            $paidAmount = $data['amount'] ?? $data['request_amount'] ?? null;
            if ($paidAmount !== null && abs((float) $paidAmount - (float) $charge->amount) > 0.004) {
                DB::table('payment_gateway_charges')->where('id', $charge->id)->update(['status' => 'amount_mismatch', 'raw_callback' => json_encode($payload), 'updated_at' => now()]);

                return;
            }
            DB::table('payment_gateway_charges')->where('id', $charge->id)->update([
                'status' => 'paid', 'paid_at' => now(), 'provider_payment_id' => $data['id'] ?? null, 'raw_callback' => json_encode($payload), 'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'OK']);
    }
}
