<?php

namespace Tests\Feature;

use App\Services\Terbilang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSale(): string
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('products.store'), ['name' => 'Teh', 'unit_id' => DB::table('units')->value('id'), 'price' => 5000, 'cost_price' => 3000, 'initial_stock' => 10]);
        $this->post(route('shifts.open'), ['opening_cash' => 100000]);
        $this->post(route('pos.checkout'), [
            'items' => [['variant_id' => DB::table('product_variants')->value('id'), 'quantity' => 2]], 'cash_received' => 10000,
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ]);

        return DB::table('sales')->value('id');
    }

    public function test_invoice_number_is_assigned_once_and_stays_stable(): void
    {
        $saleId = $this->makeSale();
        $first = $this->get(route('documents.invoice', $saleId))->assertOk()->assertSee('INVOICE')->assertSee('LUNAS');
        $number = DB::table('sales')->where('id', $saleId)->value('invoice_number');
        $this->assertMatchesRegularExpression('#^INV/\d{4}/00001$#', $number);
        $first->assertSee($number);

        $this->get(route('documents.invoice', $saleId))->assertOk();
        $this->assertSame($number, DB::table('sales')->where('id', $saleId)->value('invoice_number'));
    }

    public function test_delivery_note_hides_prices_and_receipt_shows_words(): void
    {
        $saleId = $this->makeSale();
        $this->get(route('documents.delivery-note', $saleId))->assertOk()->assertSee('SURAT JALAN')->assertSee('Teh')->assertDontSee('Rp 5.000');
        $this->assertMatchesRegularExpression('#^SJ/\d{4}/00001$#', DB::table('sales')->where('id', $saleId)->value('delivery_note_number'));

        $paymentId = DB::table('payments')->value('id');
        $this->get(route('documents.receipt', [$saleId, $paymentId]))->assertOk()->assertSee('KWITANSI')->assertSee('Sepuluh ribu rupiah');
    }

    public function test_voided_sale_cannot_print_documents(): void
    {
        $saleId = $this->makeSale();
        DB::table('sales')->where('id', $saleId)->update(['status' => 'voided']);
        $this->get(route('documents.invoice', $saleId))->assertStatus(422);
    }

    public function test_terbilang_converts_common_amounts(): void
    {
        $this->assertSame('Seribu lima ratus rupiah', Terbilang::rupiah(1500));
        $this->assertSame('Dua belas ribu tiga ratus empat puluh lima rupiah', Terbilang::rupiah(12345));
        $this->assertSame('Satu juta dua ratus lima puluh ribu rupiah', Terbilang::rupiah(1250000));
    }
}
