@extends('documents.layout')
@section('title','Surat jalan '.$number)
@section('doc_name','SURAT JALAN')
@section('body')
@php $date = fn ($d) => \Illuminate\Support\Carbon::parse($d)->locale('id')->translatedFormat('d F Y'); @endphp
<section class="meta">
    <div><h3>Dikirim kepada</h3><b>{{ $sale->customer_name ?: 'Pelanggan umum' }}</b>@if($sale->customer_address)<div>{{ $sale->customer_address }}</div>@endif @if($sale->customer_phone)<div>{{ $sale->customer_phone }}</div>@endif</div>
    <div><h3>Rincian</h3><div>Tanggal: {{ $date($sale->sold_at) }}</div><div>Ref. transaksi: {{ $sale->sale_number }}</div>@if($sale->invoice_number)<div>Invoice: {{ $sale->invoice_number }}</div>@endif</div>
</section>
<table><thead><tr><th>#</th><th>Nama barang</th><th>SKU</th><th class="num">Jumlah</th><th>Satuan</th></tr></thead><tbody>
@foreach($items as $i => $item)
    <tr><td>{{ $i + 1 }}</td><td>{{ $item->name_snapshot }}</td><td>{{ $item->sku_snapshot }}</td><td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 4, ',', '.'), '0'), ',') }}</td><td>{{ $item->unit_symbol }}</td></tr>
@endforeach
</tbody></table>
<p class="note">Barang diterima dalam kondisi baik dan jumlah sesuai. Periksa saat serah terima.</p>
<div class="sign"><div><span>Pengirim</span></div><div><span>Penerima</span></div><div><span>Mengetahui</span></div></div>
@endsection
