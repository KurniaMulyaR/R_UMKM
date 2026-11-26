<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Struk {{ $sale->sale_number }}</title>
<style>
    @page { size: {{ $width }}mm auto; margin: 2mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font: {{ $width === 58 ? 11 : 12 }}px/1.35 "Courier New", monospace; color: #000; background: #fff; }
    .paper { width: {{ $width === 58 ? 54 : 76 }}mm; margin: 0 auto; padding: 4px 0; }
    .c { text-align: center; } .r { text-align: right; } .b { font-weight: 700; }
    hr { border: 0; border-top: 1px dashed #000; margin: 5px 0; }
    .row { display: flex; justify-content: space-between; gap: 6px; } .row span:first-child { flex: 1; }
    .tools { padding: 10px; text-align: center; font-family: sans-serif; }
    @media print { .tools { display: none; } }
</style></head>
<body>
@php
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ',');
    $methods = ['cash' => 'Tunai', 'bank_transfer' => 'Transfer', 'qris_external' => 'QRIS'];
@endphp
<div class="tools"><button type="button" onclick="window.print()">Cetak</button> <a href="{{ route('documents.thermal', ['id' => $sale->id, 'w' => 58]) }}">58 mm</a> · <a href="{{ route('documents.thermal', ['id' => $sale->id, 'w' => 80]) }}">80 mm</a> · <a href="{{ route('pos.index') }}">Kembali ke kasir</a> · <a href="{{ route('sales.show', $sale->id) }}">Detail</a></div>
<div class="paper">
    <div class="c b">{{ $tenant->legal_name ?: $tenant->name }}</div>
    <div class="c">{{ $outlet->name }}</div>
    @if($outlet->address)<div class="c">{{ $outlet->address }}</div>@endif
    @if($outlet->phone ?: $tenant->phone)<div class="c">Telp {{ $outlet->phone ?: $tenant->phone }}</div>@endif
    <hr>
    <div>No  : {{ $sale->sale_number }}</div>
    <div>Tgl : {{ \Illuminate\Support\Carbon::parse($sale->sold_at)->timezone($tenant->timezone)->format('d/m/Y H:i') }}</div>
    <div>Kasir: {{ $cashier }}</div>
    @if($sale->customer_name)<div>Pelanggan: {{ $sale->customer_name }}</div>@endif
    <hr>
    @foreach($items as $item)
        <div>{{ $item->name_snapshot }}@if($item->variant_snapshot && $item->variant_snapshot !== 'Default') - {{ $item->variant_snapshot }}@endif</div>
        <div class="row"><span>{{ $item->note ?: $qty($item->quantity).' '.($item->unit_symbol ?? '').' x '.$rp($item->unit_price) }}</span><span>{{ $rp($item->line_subtotal) }}</span></div>
    @endforeach
    <hr>
    <div class="row"><span>Subtotal</span><span>{{ $rp($sale->subtotal) }}</span></div>
    @if((float) $sale->discount_total > 0)<div class="row"><span>Diskon</span><span>-{{ $rp($sale->discount_total) }}</span></div>@endif
    @if((float) $sale->returned_total > 0)<div class="row"><span>Retur</span><span>-{{ $rp($sale->returned_total) }}</span></div>@endif
    <div class="row b"><span>TOTAL</span><span>{{ $rp((float) $sale->grand_total - (float) $sale->returned_total) }}</span></div>
    @foreach($payments as $payment)<div class="row"><span>{{ $methods[$payment->method] ?? $payment->method }}</span><span>{{ $rp($payment->amount) }}</span></div>@endforeach
    @if((float) $sale->change_total > 0)<div class="row"><span>Kembali</span><span>{{ $rp($sale->change_total) }}</span></div>@endif
    @if($creditAmount > 0)<div class="row b"><span>Kasbon (belum lunas)</span><span>{{ $rp($creditAmount) }}</span></div>@endif
    <hr>
    <div class="c">{{ $footer ?: 'Terima kasih' }}</div>
</div>
@if($auto)<script>window.addEventListener('load', function () { window.print(); }); window.addEventListener('afterprint', function () { window.location.href = @json(route('pos.index')); });</script>@endif
</body></html>
