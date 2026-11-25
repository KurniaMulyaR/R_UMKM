<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XenditQrisTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): array
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->put(route('payment-gateway.update'), ['secret_key' => 'xnd_development_test', 'callback_token' => 'tok-123', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->post(route('products.store'), ['name' => 'Teh', 'unit_id' => DB::table('units')->value('id'), 'price' => 5000, 'cost_price' => 3000, 'initial_stock' => 10]);
        $this->post(route('shifts.open'), ['opening_cash' => 100000]);
        Http::fake(['api.xendit.co/qr_codes' => Http::response(['qr_id' => 'qr_1', 'qr_string' => '000201010212-test', 'status' => 'ACTIVE'], 200)]);

        return [DB::table('product_variants')->value('id'), DB::table('tenants')->value('id')];
    }

    private function createCharge(string $variantId): array
    {
        $response = $this->postJson(route('pos.qris.store'), [
            'items' => [['variant_id' => $variantId, 'quantity' => 2]], 'payments' => [['method' => 'qris_xendit', 'amount' => 10000]],
        ])->assertOk();

        return $response->json();
    }

    private function webhook(string $tenantId, string $token, array $body)
    {
        return $this->postJson(route('webhooks.xendit', $tenantId), $body, ['x-callback-token' => $token]);
    }

    public function test_sale_is_created_only_after_a_valid_paid_callback(): void
    {
        [$variantId, $tenantId] = $this->prepare();
        $charge = $this->createCharge($variantId);
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame('pending', DB::table('payment_gateway_charges')->value('status'));
        Http::assertSent(fn ($request) => $request->hasHeader('api-version', '2022-07-31') && $request['amount'] === 10000 && $request['type'] === 'DYNAMIC');

        $reference = DB::table('payment_gateway_charges')->value('reference_id');
        $paid = ['event' => 'qr.payment', 'data' => ['id' => 'qrpy_1', 'reference_id' => $reference, 'status' => 'SUCCEEDED', 'amount' => 10000]];
        $this->webhook($tenantId, 'salah', $paid)->assertStatus(401);
        $this->assertSame('pending', DB::table('payment_gateway_charges')->value('status'));

        $this->postJson(route('pos.qris.complete', $charge['id']))->assertStatus(422);
        $this->webhook($tenantId, 'tok-123', $paid)->assertOk();
        $this->getJson($charge['status_url'])->assertJson(['status' => 'paid']);

        $this->post($charge['complete_url'])->assertRedirect();
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame('used', DB::table('payment_gateway_charges')->value('status'));
        $this->assertSame('qris_external', DB::table('payments')->value('method'));
        $qrisBalance = (float) DB::table('journal_lines')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('code', '1030')->sum(DB::raw('debit - credit'));
        $this->assertEquals(10000.0, $qrisBalance);
        $this->assertSame(8.0, (float) DB::table('stock_balances')->value('quantity_on_hand'));

        $this->post($charge['complete_url'])->assertStatus(422);
        $this->assertSame(1, DB::table('sales')->count());
    }

    public function test_callback_with_wrong_amount_is_flagged_not_paid(): void
    {
        [$variantId, $tenantId] = $this->prepare();
        $this->createCharge($variantId);
        $reference = DB::table('payment_gateway_charges')->value('reference_id');

        $this->webhook($tenantId, 'tok-123', ['event' => 'qr.payment', 'data' => ['reference_id' => $reference, 'status' => 'SUCCEEDED', 'amount' => 500]])->assertOk();
        $this->assertSame('amount_mismatch', DB::table('payment_gateway_charges')->value('status'));
    }

    public function test_qris_is_refused_when_gateway_not_active(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->postJson(route('pos.qris.store'), [
            'items' => [['variant_id' => '00000000-0000-0000-0000-000000000000', 'quantity' => 1]], 'payments' => [['method' => 'qris_xendit', 'amount' => 5000]],
        ])->assertStatus(422);
    }

    public function test_xendit_failure_creates_no_charge(): void
    {
        [$variantId] = $this->prepare();
        Http::fake(['api.xendit.co/qr_codes' => Http::response(['message' => 'forbidden'], 403)]);
        $this->postJson(route('pos.qris.store'), [
            'items' => [['variant_id' => $variantId, 'quantity' => 1]], 'payments' => [['method' => 'qris_xendit', 'amount' => 5000]],
        ])->assertStatus(502);
        $this->assertSame(0, DB::table('payment_gateway_charges')->count());
    }
}
