<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesPpnTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(?string $mode = null): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('shifts.open'), ['opening_cash' => 100000]);
        if ($mode) {
            DB::table('tenants')->update(['settings' => json_encode(['ppn_enabled' => true, 'ppn_rate' => 11, 'ppn_mode' => $mode])]);
        }
    }

    private function item(float $price): string
    {
        $unitId = DB::table('units')->value('id');
        $this->post(route('products.store'), ['name' => 'Barang '.$price, 'unit_id' => $unitId, 'price' => $price, 'cost_price' => 0, 'track_stock' => 0])->assertSessionHasNoErrors();

        return (string) DB::table('product_variants')->orderByDesc('created_at')->value('id');
    }

    private function sell(string $variantId, float $quantity, float $pay): void
    {
        $this->post(route('pos.checkout'), [
            'items' => [['variant_id' => $variantId, 'quantity' => $quantity]], 'payments' => [['method' => 'cash', 'amount' => $pay]], 'cash_received' => $pay,
        ])->assertSessionHasNoErrors();
    }

    private function balance(string $code): float
    {
        $row = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')->where('chart_of_accounts.code', $code)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as b')->first();

        return round((float) $row->b, 2);
    }

    public function test_ppn_is_off_by_default(): void
    {
        $this->prepare();
        $this->sell($this->item(10000), 2, 20000);
        $sale = DB::table('sales')->first();
        $this->assertEquals(0.0, (float) $sale->tax_total);
        $this->assertEquals(20000.0, (float) $sale->grand_total);
        $this->assertSame(0, DB::table('chart_of_accounts')->where('code', '2030')->count());
    }

    public function test_exclusive_ppn_adds_tax_and_splits_revenue_in_journal(): void
    {
        $this->prepare('exclusive');
        $this->sell($this->item(10000), 2, 22200);
        $sale = DB::table('sales')->first();
        $this->assertEquals(2200.0, (float) $sale->tax_total);
        $this->assertEquals(22200.0, (float) $sale->grand_total);
        $this->assertEquals(20000.0, (float) DB::table('sale_items')->value('line_total'));
        $this->assertEquals(2200.0, (float) DB::table('sale_items')->value('tax_amount'));
        $this->assertEquals(20000.0, $this->balance('4010'));
        $this->assertEquals(2200.0, $this->balance('2030'));
    }

    public function test_checkout_rejects_payment_that_ignores_ppn(): void
    {
        $this->prepare('exclusive');
        $this->post(route('pos.checkout'), [
            'items' => [['variant_id' => $this->item(10000), 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10000]], 'cash_received' => 10000,
        ])->assertSessionHasErrors('payments');
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_inclusive_ppn_keeps_total_and_extracts_tax(): void
    {
        $this->prepare('inclusive');
        $this->sell($this->item(11000), 1, 11000);
        $sale = DB::table('sales')->first();
        $this->assertEquals(1090.09, (float) $sale->tax_total);
        $this->assertEquals(11000.0, (float) $sale->grand_total);
        $this->assertEquals(9909.91, (float) DB::table('sale_items')->value('line_total'));
        $this->assertEquals(9909.91, $this->balance('4010'));
        $this->assertEquals(1090.09, $this->balance('2030'));
    }

    public function test_return_and_void_reverse_ppn(): void
    {
        $this->prepare('exclusive');
        $this->sell($this->item(10000), 2, 22200);
        $sale = DB::table('sales')->first();
        $itemId = DB::table('sale_items')->value('id');
        $this->post(route('sales.return.store', $sale->id), ['reason' => 'Salah pesan', 'refund_method' => 'cash', 'items' => [$itemId => ['quantity' => 1, 'condition' => 'good']]])->assertSessionHasNoErrors();
        $this->assertEquals(1100.0, $this->balance('2030'));
        $this->assertEquals(10000.0, $this->balance('4010'));
        $sale = DB::table('sales')->first();
        $this->assertEquals(11100.0, (float) $sale->returned_total);
        $this->assertEquals(1100.0, (float) $sale->returned_tax_total);
    }

    public function test_void_zeroes_ppn_liability_and_vat_payment_reduces_it(): void
    {
        $this->prepare('exclusive');
        $this->sell($this->item(10000), 1, 11100);
        $this->post(route('reports.ppn.pay'), ['amount' => 400, 'method' => 'bank_transfer', 'paid_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertEquals(700.0, $this->balance('2030'));
        $this->post(route('reports.ppn.pay'), ['amount' => 5000, 'method' => 'bank_transfer', 'paid_on' => now()->toDateString()])->assertSessionHasErrors('amount');
        $saleId = DB::table('sales')->value('id');
        $this->post(route('sales.void', $saleId), ['reason' => 'Salah input', 'password' => 'rahasia123', 'confirm_void' => '1'])->assertSessionHasNoErrors();
        $this->assertEquals(-400.0, $this->balance('2030'));
    }
}
