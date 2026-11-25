@extends('documents.layout')
@section('title','Invoice '.$number)
@section('doc_name','INVOICE')
@section('body')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); $date = fn ($d) => \Illuminate\Support\Carbon::parse($d)->locale('id')->translatedFormat('d F Y'); @endphp
<section class="meta">
    <div><h3>Ditagihkan kepada</h3><b>{{ $sale->customer_name ?: 'Pelanggan umum' }}</b>@if($sale->customer_address)<div>{{ $sale->customer_address }}</div>@endif @if($sale->customer_phone)<div>{{ $sale->customer_phone }}</div>@endif</div>
    <div><h3>Rincian</h3><div>Tanggal: {{ $date($sale->sold_at) }}</div><div>Ref. transaksi: {{ $sale->sale_number }}</div></div>
</section>
<table><thead><tr><th>#</th><th>Barang</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Diskon</th><th class="num">Jumlah</th></tr></thead><tbody>
@foreach($items as $i => $item)
    <tr><td>{{ $i + 1 }}</td><td>{{ $item->name_snapshot }}@if($item->sku_snapshot)<div class="note" style="margin:0">{{ $item->sku_snapshot }}</div>@endif</td>
        <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 4, ',', '.'), '0'), ',') }} {{ $item->unit_symbol }}</td><td class="num">{{ $rp($item->unit_price) }}</td>
        <td class="num">{{ (float) $item->discount_amount > 0 ? $rp($item->discount_amount) : '-' }}</td><td class="num">{{ $rp($item->line_total) }}</td></tr>
@endforeach
</tbody></table>
<table class="totals"><tbody>
    <tr><td>Subtotal</td><td class="num">{{ $rp($sale->subtotal) }}</td></tr>
    @if((float) $sale->discount_total > 0)<tr><td>Diskon</td><td class="num">− {{ $rp($sale->discount_total) }}</td></tr>@endif
    <tr class="grand"><td>Total</td><td class="num">{{ $rp($sale->grand_total) }}</td></tr>
    @if((float) $sale->returned_total > 0)<tr><td>Retur</td><td class="num">− {{ $rp($sale->returned_total) }}</td></tr>@endif
    <tr><td>Sudah dibayar</td><td class="num">{{ $rp($payments->sum('amount')) }}</td></tr>
    <tr><td><b>Sisa tagihan</b></td><td class="num"><b>{{ $rp($balanceDue) }}</b></td></tr>
</tbody></table>
<span class="stamp {{ $balanceDue > 0 ? 'unpaid' : 'paid' }}">{{ $balanceDue > 0 ? 'BELUM LUNAS' : 'LUNAS' }}</span>
@if($sale->notes)<p class="note">Catatan: {{ $sale->notes }}</p>@endif
<p class="note">Terima kasih atas kepercayaan Anda.</p>
@endsection
