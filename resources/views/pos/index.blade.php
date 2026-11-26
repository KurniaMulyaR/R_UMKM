@extends('layouts.app')
@section('title', 'Kasir')
@section('crumb', 'Kasir')
@section('content')
@php
    $posBootstrap = [
        'catalog' => $catalog,
        'customers' => $customers,
        'checkoutUrl' => route('pos.checkout'),
        'csrfToken' => csrf_token(),
        'shiftOpen' => (bool) $shift,
        'shiftOpenedAt' => $shift ? \Illuminate\Support\Carbon::parse($shift->opened_at)->format('H:i') : '',
        'productsUrl' => route('products.index'),
        'shiftsUrl' => route('shifts.index'),
        'xenditEnabled' => (bool) $xenditEnabled,
        'qrisUrl' => route('pos.qris.store'),
        'initialCustomerId' => $initialCustomerId,
        'initialNotes' => $initialNotes,
        'relatedSaleNumber' => $relatedSale?->sale_number,
    ];
@endphp
@foreach($paidCharges as $charge)
<div class="alert success" style="display:flex;justify-content:space-between;align-items:center;gap:12px">
    <span>Pembayaran QRIS Rp {{ number_format($charge->amount, 0, ',', '.') }} sudah masuk tetapi transaksinya belum diselesaikan.</span>
    <form method="post" action="{{ route('pos.qris.complete', $charge->id) }}">@csrf<button class="btn btn-primary">Selesaikan transaksi</button></form>
</div>
@endforeach
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<div id="pos-app" aria-live="polite"></div>
<script id="pos-bootstrap" type="application/json">@json($posBootstrap)</script>
<noscript>Halaman kasir membutuhkan JavaScript aktif untuk mengelola keranjang.</noscript>
@endsection
