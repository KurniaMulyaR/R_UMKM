<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoidCreditSaleTest extends TestCase
{
    use RefreshDatabase;

    private function balance(string $code): float
    {
        return (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('chart_of_accounts.code', $code)->where('journal_entries.status', '!=', 'draft')
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));
    }

    public function test_voiding_a_credit_sale_also_reverses_later_receipts(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertRedirect(route('dashboard'));

        $unit = DB::table('units')->first();
        $this->post(route('products.store'), [
            'name' => 'Gula 1kg', 'unit_id' => $unit->id, 'price' => 20000, 'cost_price' => 15000, 'initial_stock' => 10,
        ])->assertRedirect(route('products.index'));
        $variant = DB::table('product_variants')->first();

        $tenantId = DB::table('tenants')->value('id');
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId, 'tenant_id' => $tenantId, 'name' => 'Bu Siti', 'phone' => '081200000001',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post(route('shifts.open'), ['opening_cash' => 100000])->assertRedirect(route('pos.index'));
        $this->post(route('pos.checkout'), [
            'customer_id' => $customerId,
            'items' => [['variant_id' => $variant->id, 'quantity' => 2]],
            'payments' => [['method' => 'credit', 'amount' => 40000]],
        ]);
        $sale = DB::table('sales')->first();
        $this->assertNotNull($sale);
        $this->assertEquals(40000.0, $this->balance('1200'));

        $this->post(route('sales.payments.store', $sale->id), ['method' => 'cash', 'amount' => 15000])
            ->assertSessionHasNoErrors()->assertRedirect(route('sales.show', $sale->id));
        $this->assertEquals(25000.0, $this->balance('1200'));
        $this->assertEquals(15000.0, $this->balance('1010'));

        $this->from(route('sales.show', $sale->id))->post(route('sales.void', $sale->id), [
            'reason' => 'Salah input barang', 'password' => 'rahasia123', 'confirm_void' => '1',
        ])->assertRedirect(route('sales.show', $sale->id));

        $this->assertSame('voided', DB::table('sales')->where('id', $sale->id)->value('status'));
        $this->assertEquals(0.0, $this->balance('1200'), 'Piutang harus nol setelah void.');
        $this->assertEquals(0.0, $this->balance('1010'), 'Kas dari pelunasan ikut dibalik.');
        $this->assertEquals(0.0, $this->balance('4010'));
        $this->assertSame(0, DB::table('journal_entries')->where('status', 'posted')->whereIn('source_type', ['payment'])->count());
    }
}
