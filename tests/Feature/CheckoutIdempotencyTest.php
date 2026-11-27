<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrying_the_same_checkout_returns_the_original_sale_without_double_charging_or_deducting_stock(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertRedirect(route('dashboard'));

        $this->post(route('products.store'), [
            'name' => 'Teh', 'unit_id' => DB::table('units')->value('id'), 'price' => 5000,
            'cost_price' => 3000, 'initial_stock' => 10,
        ])->assertRedirect(route('products.index'));
        $this->post(route('shifts.open'), ['opening_cash' => 100000])->assertRedirect(route('pos.index'));

        $variantId = DB::table('product_variants')->value('id');
        $outletId = DB::table('outlets')->value('id');
        $tenantId = DB::table('tenants')->value('id');
        $clientTransactionId = (string) Str::uuid();
        $checkout = [
            'client_transaction_id' => $clientTransactionId,
            'items' => [['variant_id' => $variantId, 'quantity' => 2]],
            'cash_received' => 10000,
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ];

        $firstResponse = $this->postJson(route('pos.checkout'), $checkout)->assertCreated();
        $saleId = $firstResponse->json('receipt.id') ?? DB::table('sales')->value('id');
        $this->assertNotEmpty($saleId);

        $retryResponse = $this->postJson(route('pos.checkout'), $checkout)->assertOk();
        $this->assertSame($firstResponse->json('receipt.sale_number'), $retryResponse->json('receipt.sale_number'));

        $this->assertSame(1, DB::table('sales')->where('tenant_id', $tenantId)->count());
        $this->assertSame(1, DB::table('sale_items')->where('sale_id', $saleId)->count());
        $this->assertSame(1, DB::table('payments')->where('tenant_id', $tenantId)->count());
        $this->assertSame(1, DB::table('stock_movements')->where('tenant_id', $tenantId)->where('movement_type', 'sale')->count());
        $this->assertSame(1, DB::table('journal_entries')->where('tenant_id', $tenantId)->where('source_type', 'sale')->count());
        $this->assertSame(8.0, (float) DB::table('stock_balances')->where('tenant_id', $tenantId)->where('outlet_id', $outletId)->where('product_variant_id', $variantId)->value('quantity_on_hand'));
    }
}
