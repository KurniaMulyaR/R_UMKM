<?php

namespace App\Http\Controllers;

use App\Services\Terbilang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DocumentController extends Controller
{
    public function invoice(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $sale = $this->loadSale($tenant->id, $outlet->id, $id);
        $number = $this->documentNumber($tenant, $outlet, $sale, 'invoice_number', 'invoice', 'INV');
        $payments = $this->confirmedPayments($tenant->id, $sale->id);

        return view('documents.invoice', [
            'tenant' => $tenant, 'outlet' => $outlet, 'sale' => $sale, 'number' => $number, 'items' => $this->items($tenant->id, $sale->id),
            'payments' => $payments, 'balanceDue' => max(0.0, round((float) $sale->grand_total - (float) $sale->returned_total - (float) $payments->sum('amount'), 2)),
        ]);
    }

    public function deliveryNote(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $sale = $this->loadSale($tenant->id, $outlet->id, $id);
        $number = $this->documentNumber($tenant, $outlet, $sale, 'delivery_note_number', 'delivery_note', 'SJ');

        return view('documents.delivery-note', ['tenant' => $tenant, 'outlet' => $outlet, 'sale' => $sale, 'number' => $number, 'items' => $this->items($tenant->id, $sale->id)]);
    }

    public function receipt(Request $request, string $id, string $paymentId): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $sale = $this->loadSale($tenant->id, $outlet->id, $id);
        $payment = DB::table('payments')->join('payment_allocations', 'payment_allocations.payment_id', '=', 'payments.id')
            ->where('payments.tenant_id', $tenant->id)->where('payment_allocations.sale_id', $sale->id)->where('payments.id', $paymentId)
            ->where('payments.status', 'confirmed')->whereNotNull('payments.confirmed_at')
            ->select('payments.payment_number', 'payments.method', 'payments.received_at', 'payments.confirmed_at', 'payment_allocations.amount')->first();
        abort_unless($payment, 404);
        $invoiceNumber = $sale->invoice_number;

        return view('documents.receipt', [
            'tenant' => $tenant, 'outlet' => $outlet, 'sale' => $sale, 'payment' => $payment, 'invoiceNumber' => $invoiceNumber,
            'words' => Terbilang::rupiah((float) $payment->amount),
        ]);
    }

    /**
     * Struk thermal 58/80 mm. Dengan ?auto=1 halaman langsung membuka dialog cetak lalu kembali ke kasir.
     */
    public function thermal(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $sale = $this->loadSale($tenant->id, $outlet->id, $id);
        $cashier = DB::table('users')->where('id', $sale->cashier_user_id)->value('name');
        $settings = json_decode((string) $tenant->settings, true) ?: [];
        $width = (int) $request->query('w', $settings['receipt_width'] ?? 80);
        $payments = $this->confirmedPayments($tenant->id, $sale->id);

        return view('documents.thermal', [
            'tenant' => $tenant, 'outlet' => $outlet, 'sale' => $sale, 'items' => $this->items($tenant->id, $sale->id), 'payments' => $payments, 'cashier' => $cashier,
            'width' => in_array($width, [58, 80], true) ? $width : 80, 'auto' => $request->boolean('auto'), 'footer' => $settings['document_footer'] ?? null,
            'embed' => $request->boolean('embed'),
            'creditAmount' => max(0.0, round((float) $sale->grand_total - (float) $sale->returned_total - (float) $payments->sum('amount'), 2)),
        ]);
    }

    private function loadSale(string $tenantId, string $outletId, string $id): object
    {
        $sale = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.tenant_id', $tenantId)->where('sales.outlet_id', $outletId)->where('sales.id', $id)
            ->select('sales.*', 'customers.name as customer_name', 'customers.phone as customer_phone', 'customers.address as customer_address')->first();
        abort_unless($sale, 404);
        abort_if($sale->status === 'voided', 422, 'Transaksi ini sudah dibatalkan, dokumen tidak bisa dicetak.');

        return $sale;
    }

    private function items(string $tenantId, string $saleId)
    {
        return DB::table('sale_items')->leftJoin('units', 'units.id', '=', 'sale_items.unit_id')
            ->where('sale_items.tenant_id', $tenantId)->where('sale_items.sale_id', $saleId)->orderBy('sale_items.created_at')
            ->select('sale_items.*', 'units.symbol as unit_symbol')->get();
    }

    private function confirmedPayments(string $tenantId, string $saleId)
    {
        return DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.tenant_id', $tenantId)->where('payment_allocations.sale_id', $saleId)->where('payments.status', 'confirmed')
            ->orderBy('payments.received_at')->select('payments.method', 'payments.received_at', 'payment_allocations.amount')->get();
    }

    /**
     * Assign a document number on first print and keep it stable afterwards.
     */
    private function documentNumber(object $tenant, object $outlet, object $sale, string $column, string $type, string $prefix): string
    {
        if ($sale->{$column}) {
            return $sale->{$column};
        }

        return DB::transaction(function () use ($tenant, $outlet, $sale, $column, $type, $prefix) {
            $current = DB::table('sales')->where('id', $sale->id)->lockForUpdate()->value($column);
            if ($current) {
                return $current;
            }
            $now = now($tenant->timezone);
            $scope = ['tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'document_type' => $type, 'period_key' => $now->format('Y')];
            $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
            if (! $counter) {
                DB::table('document_counters')->insert($scope + ['id' => (string) Str::uuid(), 'prefix' => $prefix, 'next_number' => 1, 'padding' => 5, 'created_at' => $now, 'updated_at' => $now]);
                $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
            }
            DB::table('document_counters')->where('id', $counter->id)->update(['next_number' => $counter->next_number + 1, 'updated_at' => $now]);
            $number = $counter->prefix.'/'.$scope['period_key'].'/'.str_pad((string) $counter->next_number, $counter->padding, '0', STR_PAD_LEFT);
            DB::table('sales')->where('id', $sale->id)->update([$column => $number, 'updated_at' => $now]);

            return $number;
        });
    }
}
