<?php

namespace App\Http\Controllers;

use App\Services\XenditClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class QrisChargeController extends Controller
{
    private const EXPIRY_MINUTES = 15;

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $setting = DB::table('payment_gateway_settings')->where('tenant_id', $tenant->id)->where('provider', 'xendit')->where('is_active', true)->first();
        if (! $setting || ! $setting->secret_key) {
            return response()->json(['message' => 'QRIS Xendit belum diaktifkan. Pemilik bisa mengaturnya di menu Pembayaran QRIS.'], 422);
        }
        $data = $request->validate([
            'customer_id' => ['nullable', 'uuid'], 'discount' => ['nullable', 'numeric', 'min:0'], 'cash_received' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.variant_id' => ['required', 'uuid'], 'items.*.unit_id' => ['nullable', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'], 'payments' => ['required', 'array', 'min:1', 'max:4'],
            'payments.*.method' => ['required', 'in:cash,bank_transfer,qris_external,qris_xendit,credit'], 'payments.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);
        $data['items'] = PosController::normalizeItems($data['items']);
        $xenditTenders = collect($data['payments'])->where('method', 'qris_xendit');
        if ($xenditTenders->count() !== 1) {
            throw ValidationException::withMessages(['payments' => 'Pakai satu metode QRIS Xendit per transaksi.']);
        }
        if (collect($data['payments'])->where('method', 'qris_external')->isNotEmpty()) {
            throw ValidationException::withMessages(['payments' => 'Jangan campur QRIS manual dan QRIS Xendit dalam satu transaksi.']);
        }
        $amount = round((float) $xenditTenders->first()['amount'], 2);
        if ($amount < 1 || $amount > 10000000) {
            throw ValidationException::withMessages(['payments' => 'Nominal QRIS harus antara Rp 1 dan Rp 10.000.000.']);
        }
        if (! empty($data['customer_id'])) {
            abort_unless(DB::table('customers')->where('tenant_id', $tenant->id)->where('id', $data['customer_id'])->where('is_active', true)->exists(), 404);
        }
        if (collect($data['payments'])->contains(fn ($payment) => $payment['method'] === 'credit') && empty($data['customer_id'])) {
            throw ValidationException::withMessages(['customer_id' => 'Pilih pelanggan untuk transaksi kasbon.']);
        }
        $cashApplied = collect($data['payments'])->where('method', 'cash')->sum(fn ($payment) => (float) $payment['amount']);
        if ($cashApplied > 0 && (float) ($data['cash_received'] ?? 0) < $cashApplied) {
            throw ValidationException::withMessages(['cash_received' => 'Uang diterima harus sama atau lebih besar dari pembayaran tunai.']);
        }
        abort_unless(DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('cashier_user_id', auth()->id())->where('status', 'open')->exists(), 422, 'Buka shift sebelum memulai transaksi.');

        $data['payments'] = collect($data['payments'])->map(fn ($payment) => $payment['method'] === 'qris_xendit' ? ['method' => 'qris_external', 'amount' => $payment['amount']] : $payment)->all();
        $referenceId = 'rk-'.Str::uuid();
        $expiresAt = now()->addMinutes(self::EXPIRY_MINUTES);
        try {
            $qr = (new XenditClient(Crypt::decryptString($setting->secret_key)))->createDynamicQr($referenceId, $amount, $expiresAt->clone()->utc()->format('Y-m-d\TH:i:s\Z'));
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 502);
        }

        $id = (string) Str::uuid();
        DB::table('payment_gateway_charges')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'cashier_user_id' => auth()->id(), 'provider' => 'xendit', 'reference_id' => $referenceId,
            'provider_qr_id' => $qr['qr_id'], 'amount' => $amount, 'status' => 'pending', 'qr_string' => $qr['qr_string'], 'expires_at' => $expiresAt,
            'payload' => json_encode($data), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $id, 'qr_string' => $qr['qr_string'], 'amount' => $amount, 'expires_at' => $expiresAt->toIso8601String(),
            'status_url' => route('pos.qris.status', $id), 'complete_url' => route('pos.qris.complete', $id), 'cancel_url' => route('pos.qris.cancel', $id),
        ]);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $charge = $this->ownCharge($request, $id);
        if ($charge->status === 'pending' && $charge->expires_at && now()->greaterThan($charge->expires_at)) {
            DB::table('payment_gateway_charges')->where('id', $id)->where('status', 'pending')->update(['status' => 'expired', 'updated_at' => now()]);
            $charge->status = 'expired';
        }

        return response()->json(['status' => $charge->status]);
    }

    public function complete(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $charge = $this->ownCharge($request, $id);
        abort_unless($charge->status === 'paid' && ! $charge->sale_id, 422, 'Pembayaran QRIS ini belum lunas atau sudah dipakai.');
        $data = json_decode($charge->payload, true);
        $cashApplied = collect($data['payments'])->where('method', 'cash')->sum(fn ($payment) => (float) $payment['amount']);

        $saleId = app(PosController::class)->processCheckout($tenant, $outlet, $data, $cashApplied, $charge->id);

        if ($request->expectsJson()) {
            return response()->json(['receipt' => app(PosController::class)->receiptPayload($tenant, $outlet, $saleId)], 201);
        }

        return redirect()->route('sales.show', $saleId)->with('success', 'Pembayaran QRIS diterima dan transaksi disimpan.');
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->ownCharge($request, $id);
        DB::table('payment_gateway_charges')->where('id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);

        return response()->json(['status' => 'cancelled']);
    }

    private function ownCharge(Request $request, string $id): object
    {
        $tenant = $request->attributes->get('tenant');
        $charge = DB::table('payment_gateway_charges')->where('tenant_id', $tenant->id)->where('id', $id)->where('cashier_user_id', auth()->id())->first();
        abort_unless($charge, 404);

        return $charge;
    }
}
