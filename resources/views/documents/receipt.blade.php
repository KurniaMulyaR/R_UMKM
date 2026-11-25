@extends('documents.layout')
@section('title','Kwitansi '.$payment->payment_number)
@section('doc_name','KWITANSI')
@section('body')
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $date = fn ($d) => \Illuminate\Support\Carbon::parse($d)->locale('id')->translatedFormat('d F Y');
    $methods = ['cash' => 'Tunai', 'bank_transfer' => 'Transfer bank', 'qris_external' => 'QRIS'];
@endphp
<table style="margin-top:10px"><tbody>
    <tr><td style="width:32%"><b>Telah terima dari</b></td><td>{{ $sale->customer_name ?: 'Pelanggan umum' }}</td></tr>
    <tr><td><b>Uang sejumlah</b></td><td><i>{{ $words }}</i></td></tr>
    <tr><td><b>Untuk pembayaran</b></td><td>Transaksi {{ $sale->sale_number }}@if($invoiceNumber) (Invoice {{ $invoiceNumber }})@endif</td></tr>
    <tr><td><b>Metode</b></td><td>{{ $methods[$payment->method] ?? $payment->method }}</td></tr>
    <tr><td><b>Tanggal</b></td><td>{{ $date($payment->received_at ?? $payment->confirmed_at) }}</td></tr>
</tbody></table>
<p style="font-size:22px;font-weight:800;border:2px solid #111827;display:inline-block;padding:6px 18px;margin:14px 0">{{ $rp($payment->amount) }}</p>
<div class="sign" style="grid-template-columns:1fr 1fr 1fr"><div></div><div></div><div><span>{{ $tenant->legal_name ?: $tenant->name }}</span></div></div>
@endsection
