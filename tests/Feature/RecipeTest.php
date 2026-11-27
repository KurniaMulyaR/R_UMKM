<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipeTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'food',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('shifts.open'), ['opening_cash' => 100000]);
    }

    private function product(string $name, bool $track, float $price, float $cost = 0, float $stock = 0): string
    {
        $unitId = DB::table('units')->where('tenant_id', DB::table('tenants')->value('id'))->value('id');
        $this->post(route('products.store'), [
            'name' => $name, 'unit_id' => $unitId, 'price' => $price, 'cost_price' => $cost, 'track_stock' => $track ? 1 : 0, 'initial_stock' => $stock,
        ])->assertSessionHasNoErrors();
        $productId = DB::table('products')->where('name', $name)->value('id');

        return (string) DB::table('product_variants')->where('product_id', $productId)->value('id');
    }

    private function balance(string $code): float
    {
        $row = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')->where('chart_of_accounts.code', $code)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')->first();

        return (float) $row->d - (float) $row->c;
    }

    private function onHand(string $variantId): float
    {
        return (float) DB::table('stock_balances')->where('product_variant_id', $variantId)->value('quantity_on_hand');
    }

    public function test_sale_deducts_ingredients_posts_cogs_and_void_restores(): void
    {
        $this->prepare();
        $beans = $this->product('Biji kopi', true, 1000, 400, 100);
        $milk = $this->product('Susu', true, 1000, 200, 500);
        $latte = $this->product('Latte', false, 20000);
        DB::table('stock_balances')->where('product_variant_id', $beans)->update(['average_cost' => 400]);
        DB::table('stock_balances')->where('product_variant_id', $milk)->update(['average_cost' => 200]);

        $this->put(route('recipes.update', $latte), [
            'yield_quantity' => 1, 'lines' => [['ingredient_variant_id' => $beans, 'quantity' => 18, 'waste_percent' => 0], ['ingredient_variant_id' => $milk, 'quantity' => 100, 'waste_percent' => 0]],
        ])->assertSessionHasNoErrors();

        $cogsBefore = $this->balance('5010');
        $this->post(route('pos.checkout'), [
            'items' => [['variant_id' => $latte, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 40000]], 'cash_received' => 40000,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(64.0, $this->onHand($beans));
        $this->assertEquals(300.0, $this->onHand($milk));
        $this->assertEquals(2 * (18 * 400 + 100 * 200), $this->balance('5010') - $cogsBefore);
        $this->assertEquals(27200.0, (float) DB::table('sale_items')->where('product_variant_id', $latte)->value('unit_cost_snapshot'));

        $saleId = DB::table('sales')->value('id');
        $this->post(route('sales.void', $saleId), ['reason' => 'Salah input', 'password' => 'rahasia123', 'confirm_void' => '1'])->assertSessionHasNoErrors();
        $this->assertEquals(100.0, $this->onHand($beans));
        $this->assertEquals(500.0, $this->onHand($milk));
        $this->assertEquals(0.0, $this->balance('5010'));
    }

    public function test_sale_is_blocked_when_ingredient_is_insufficient(): void
    {
        $this->prepare();
        $beans = $this->product('Biji kopi', true, 1000, 400, 10);
        $latte = $this->product('Latte', false, 20000);
        $this->put(route('recipes.update', $latte), ['yield_quantity' => 1, 'lines' => [['ingredient_variant_id' => $beans, 'quantity' => 18]]])->assertSessionHasNoErrors();
        $this->post(route('pos.checkout'), [
            'items' => [['variant_id' => $latte, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 20000]], 'cash_received' => 20000,
        ])->assertSessionHasErrors('items');
        $this->assertEquals(10.0, $this->onHand($beans));
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_recipe_rejects_stocked_menu_and_recipe_as_ingredient(): void
    {
        $this->prepare();
        $beans = $this->product('Biji kopi', true, 1000, 400, 100);
        $tracked = $this->product('Roti', true, 5000, 2000, 10);
        $this->put(route('recipes.update', $tracked), ['yield_quantity' => 1, 'lines' => [['ingredient_variant_id' => $beans, 'quantity' => 1]]])->assertSessionHasErrors('recipe');
    }
}
