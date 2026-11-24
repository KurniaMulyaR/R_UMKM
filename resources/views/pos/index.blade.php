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
    ];
@endphp
<div id="pos-app" aria-live="polite"></div>
<script id="pos-bootstrap" type="application/json">@json($posBootstrap)</script>
<noscript>Halaman kasir membutuhkan JavaScript aktif untuk mengelola keranjang.</noscript>
@endsection
