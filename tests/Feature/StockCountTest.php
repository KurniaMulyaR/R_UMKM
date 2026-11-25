<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockCountTest extends TestCase
{
    use RefreshDatabase;

    private function setupProduct(): string
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('products.store'), ['name' => 'Gula 1kg', 'unit_id' => DB::table('units')->value('id'), 'price' => 20000, 'cost_price' => 10000, 'initial_stock' => 20]);

        return DB::table('product_variants')->value('id');
    }

    private function inventoryBalance(): float
    {
        return (float) DB::table('journal_lines')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('code', '1100')->sum(DB::raw('debit - credit'));
    }

    public function test_shortage_is_applied_only_after_approval_and_journaled(): void
    {
        $variantId = $this->setupProduct();
        $this->post(route('stock-counts.store'), ['counted' => [$variantId => 18]])->assertSessionHasNoErrors();
        $countId = DB::table('stock_counts')->value('id');
        $this->assertSame(20.0, (float) DB::table('stock_balances')->value('quantity_on_hand'));

        $this->post(route('stock-counts.approve', $countId))->assertSessionHasNoErrors();
        $this->assertSame(18.0, (float) DB::table('stock_balances')->value('quantity_on_hand'));
        $this->assertEquals(180000.0, (float) DB::table('stock_balances')->value('inventory_value'));
        $this->assertEquals(180000.0, $this->inventoryBalance());
        $loss = (float) DB::table('journal_lines')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('code', '5020')->sum(DB::raw('debit - credit'));
        $this->assertEquals(20000.0, $loss);
        $this->assertSame('approved', DB::table('stock_counts')->value('status'));

        $this->post(route('stock-counts.approve', $countId))->assertStatus(422);
        $this->assertSame(18.0, (float) DB::table('stock_balances')->value('quantity_on_hand'));
    }

    public function test_surplus_increases_stock_and_reject_changes_nothing(): void
    {
        $variantId = $this->setupProduct();
        $this->post(route('stock-counts.store'), ['counted' => [$variantId => 23]]);
        $this->post(route('stock-counts.approve', DB::table('stock_counts')->value('id')))->assertSessionHasNoErrors();
        $this->assertSame(23.0, (float) DB::table('stock_balances')->value('quantity_on_hand'));
        $this->assertEquals(230000.0, $this->inventoryBalance());

        $this->post(route('stock-counts.store'), ['counted' => [$variantId => 1]]);
        $second = DB::table('stock_counts')->where('status', 'submitted')->value('id');
        $this->post(route('stock-counts.reject', $second))->assertSessionHasNoErrors();
        $this->assertSame(23.0, (float) DB::table('stock_balances')->value('quantity_on_hand'));
        $this->assertSame('rejected', DB::table('stock_counts')->where('id', $second)->value('status'));
    }

    public function test_empty_count_is_rejected(): void
    {
        $variantId = $this->setupProduct();
        $this->post(route('stock-counts.store'), ['counted' => [$variantId => '']])->assertSessionHasErrors('counted');
        $this->assertSame(0, DB::table('stock_counts')->count());
    }
}
