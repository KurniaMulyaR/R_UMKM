<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductVariantUnitTest extends TestCase
{
    use RefreshDatabase;

    private string $productId;

    private string $variantId;

    private function prepare(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('products.store'), ['name' => 'Teh', 'unit_id' => DB::table('units')->value('id'), 'price' => 10000, 'cost_price' => 6000, 'initial_stock' => 50]);
        $this->post(route('shifts.open'), ['opening_cash' => 0]);
        $this->productId = DB::table('products')->value('id');
        $this->variantId = DB::table('product_variants')->value('id');
    }

    private function inventory(): float
    {
        return (float) DB::table('journal_lines')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')->where('code', '1100')->sum(DB::raw('debit - credit'));
    }

    private function addDus(): void
    {
        $this->post(route('products.units.store', $this->productId), ['new_unit_name' => 'Dus', 'new_unit_symbol' => 'dus', 'base_quantity' => 12, 'prices' => [$this->variantId => 100000]])->assertSessionHasNoErrors();
    }

    public function test_new_variant_has_own_stock_price_and_journaled_opening_stock(): void
    {
        $this->prepare();
        $this->post(route('products.variants.store', $this->productId), ['name' => 'Besar', 'price' => 15000, 'cost_price' => 9000, 'initial_stock' => 10, 'barcode' => '899123'])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('product_variants')->where('product_id', $this->productId)->count());
        $this->assertEquals(300000.0 + 90000.0, $this->inventory());
        $this->post(route('products.variants.store', $this->productId), ['name' => 'besar', 'price' => 1])->assertSessionHasErrors('name');

        $this->get(route('pos.index'))->assertOk()->assertSee('Teh — Besar', false);
        $this->get(route('products.index'))->assertOk();
    }

    public function test_cannot_archive_last_active_variant(): void
    {
        $this->prepare();
        $this->post(route('products.variants.archive', [$this->productId, $this->variantId]))->assertStatus(422);
        $this->post(route('products.variants.store', $this->productId), ['name' => 'Besar', 'price' => 15000]);
        $this->post(route('products.variants.archive', [$this->productId, $this->variantId]))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('product_variants')->where('product_id', $this->productId)->where('is_active', true)->count());
    }

    public function test_selling_in_a_larger_unit_deducts_base_stock_and_uses_unit_price(): void
    {
        $this->prepare();
        $this->addDus();
        $dus = DB::table('units')->where('symbol', 'dus')->value('id');
        $this->post(route('pos.checkout'), [
            'items' => [['variant_id' => $this->variantId, 'unit_id' => $dus, 'quantity' => 2]], 'cash_received' => 200000,
            'payments' => [['method' => 'cash', 'amount' => 200000]],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(26.0, (float) DB::table('stock_balances')->where('product_variant_id', $this->variantId)->value('quantity_on_hand'));
        $sale = DB::table('sales')->first();
        $this->assertEquals(200000.0, (float) $sale->grand_total);
        $item = DB::table('sale_items')->first();
        $this->assertEquals(24.0, (float) $item->quantity);
        $this->assertEquals(24.0, (float) $item->base_quantity);
        $this->assertEquals(200000.0, (float) $item->line_subtotal);
        $this->assertNotNull($item->note);
        $this->assertEquals(26 * 6000.0, $this->inventory());
    }

    public function test_unit_sale_cannot_exceed_stock_or_mix_units_for_one_variant(): void
    {
        $this->prepare();
        $this->addDus();
        $dus = DB::table('units')->where('symbol', 'dus')->value('id');
        $this->post(route('pos.checkout'), ['items' => [['variant_id' => $this->variantId, 'unit_id' => $dus, 'quantity' => 5]], 'cash_received' => 500000, 'payments' => [['method' => 'cash', 'amount' => 500000]]])->assertSessionHasErrors('items');
        $this->post(route('pos.checkout'), ['items' => [['variant_id' => $this->variantId, 'quantity' => 1], ['variant_id' => $this->variantId, 'unit_id' => $dus, 'quantity' => 1]], 'cash_received' => 110000, 'payments' => [['method' => 'cash', 'amount' => 110000]]])->assertSessionHasErrors('items');
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertEquals(50.0, (float) DB::table('stock_balances')->where('product_variant_id', $this->variantId)->value('quantity_on_hand'));
    }

    public function test_base_price_is_not_confused_with_unit_price(): void
    {
        $this->prepare();
        $this->addDus();
        $this->post(route('pos.checkout'), ['items' => [['variant_id' => $this->variantId, 'quantity' => 1]], 'cash_received' => 10000, 'payments' => [['method' => 'cash', 'amount' => 10000]]])->assertSessionHasNoErrors();
        $this->assertEquals(10000.0, (float) DB::table('sales')->value('grand_total'));
    }
}
