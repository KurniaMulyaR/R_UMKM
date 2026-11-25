<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleReturnTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): string
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('products.store'), ['name' => 'Teh', 'unit_id' => DB::table('units')->value('id'), 'price' => 5000, 'cost_price' => 3000, 'initial_stock' => 10]);
        $this->post(route('shifts.open'), ['opening_cash' => 100000]);

        return DB::table('product_variants')->value('id');
    }

    private function sell(string $variantId, int $qty, array $payments, array $extra = []): string
    {
        $this->post(route('pos.checkout'), ['items' => [['variant_id' => $variantId, 'quantity' => $qty]], 'cash_received' => 100000, 'payments' => $payments] + $extra)->assertSessionHasNoErrors();

        return DB::table('sales')->latest('created_at')->value('id');
    }

    private function balance(string $code): float
    {
        return (float) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')->where('code', $code)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])->sum(DB::raw('journal_lines.debit - journal_lines.credit'));
    }

    public function test_cash_sale_return_restocks_refunds_cash_and_balances_journal(): void
    {
        $variantId = $this->prepare();
        $saleId = $this->sell($variantId, 4, [['method' => 'cash', 'amount' => 20000]]);
        $itemId = DB::table('sale_items')->value('id');

        $this->post(route('sales.return.store', $saleId), ['reason' => 'Rusak ringan', 'refund_method' => 'cash', 'items' => [$itemId => ['quantity' => 1, 'condition' => 'good']]])->assertSessionHasNoErrors();

        $this->assertSame(7.0, (float) DB::table('stock_balances')->value('quantity_on_hand'));
        $this->assertEquals(5000.0, (float) DB::table('sale_returns')->value('refund_total'));
        $this->assertEquals(15000.0, $this->balance('4010') * -1);
        $this->assertEquals(21000.0, $this->balance('1100'));
        $this->assertEquals(3000.0, (float) DB::table('sale_returns')->value('restock_value'));
        $this->assertEquals(5000.0, (float) DB::table('sales')->value('returned_total'));
        $debit = (float) DB::table('journal_lines')->sum('debit');
        $credit = (float) DB::table('journal_lines')->sum('credit');
        $this->assertEquals($debit, $credit);

        $this->post(route('shifts.close'), ['counted_cash' => 115000]);
        $this->assertEquals(115000.0, (float) DB::table('cash_shifts')->value('expected_cash'));
    }

    public function test_damaged_return_does_not_restock_and_cannot_exceed_sold_quantity(): void
    {
        $variantId = $this->prepare();
        $saleId = $this->sell($variantId, 2, [['method' => 'cash', 'amount' => 10000]]);
        $itemId = DB::table('sale_items')->value('id');

        $this->post(route('sales.return.store', $saleId), ['reason' => 'Terlalu banyak', 'refund_method' => 'cash', 'items' => [$itemId => ['quantity' => 3]]])->assertSessionHasErrors('items');
        $this->assertSame(0, DB::table('sale_returns')->count());

        $this->post(route('sales.return.store', $saleId), ['reason' => 'Rusak', 'refund_method' => 'cash', 'items' => [$itemId => ['quantity' => 2, 'condition' => 'damaged']]])->assertSessionHasNoErrors();
        $this->assertSame(8.0, (float) DB::table('stock_balances')->value('quantity_on_hand'));
        $this->assertEquals(0.0, (float) DB::table('sale_returns')->value('restock_value'));
        $this->assertEquals(10000.0, (float) DB::table('sale_returns')->value('refund_total'));
    }

    public function test_credit_sale_return_reduces_receivable_before_refunding_cash(): void
    {
        $variantId = $this->prepare();
        $this->post(route('customers.store'), ['name' => 'Bu Sari', 'phone' => '081111111111']);
        $customerId = DB::table('customers')->value('id');
        $saleId = $this->sell($variantId, 4, [['method' => 'credit', 'amount' => 20000]], ['customer_id' => $customerId]);
        $itemId = DB::table('sale_items')->value('id');

        $this->post(route('sales.return.store', $saleId), ['reason' => 'Batal sebagian', 'items' => [$itemId => ['quantity' => 2]]])->assertSessionHasNoErrors();
        $return = DB::table('sale_returns')->first();
        $this->assertEquals(10000.0, (float) $return->receivable_reduction);
        $this->assertEquals(0.0, (float) $return->cash_refund_amount);
        $this->assertEquals(10000.0, $this->balance('1200'));

        $this->post(route('sales.void', $saleId), ['reason' => 'Coba batal', 'password' => 'rahasia123', 'confirm_void' => 1])->assertStatus(422);
    }
}
