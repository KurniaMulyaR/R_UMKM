@extends('layouts.app')
@section('title','Ringkasan')
@section('crumb','Ringkasan')
@section('low_stock', $lowStock->count())
@section('content')
@php
    $dashBootstrap = $overview + [
        'userName' => auth()->user()->name,
        'dateLabel' => now($currentTenant->timezone)->locale('id')->translatedFormat('l, d F Y'),
    ];
@endphp
@php
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
    $ranges = ['today' => 'Hari ini', '7' => '7 hari', '30' => '30 hari', 'month' => 'Bulan ini'];
    $canSeeFinance = in_array($currentRole->key ?? '', ['owner', 'manager'], true);
@endphp
<div class="toolbar" style="gap:8px;margin-bottom:12px">
    @foreach($ranges as $key => $label)<a class="btn {{ $range === $key ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('dashboard', ['range' => $key]) }}">{{ $label }}</a>@endforeach
</div>
<div id="dashboard-app"></div>
@if($canSeeFinance)
<div class="feature-summary" style="margin-top:16px">
    <a class="card feature-stat" href="{{ route('customers.aging') }}"><span class="feature-stat-icon partial-icon">Rp</span><div><small>Piutang pelanggan ({{ $position['receivableCustomers'] }} pelanggan)</small><b>{{ $rp($position['receivable']) }}</b></div></a>
    <div class="card feature-stat"><span class="feature-stat-icon planned-icon">Rp</span><div><small>Hutang ke supplier</small><b>{{ $rp($position['payable']) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">Rp</span><div><small>Nilai persediaan outlet ini</small><b>{{ $rp($position['inventory']) }}</b></div></div>
</div>
@endif
<script id="dashboard-bootstrap" type="application/json">@json($dashBootstrap)</script>
<noscript>Dashboard membutuhkan JavaScript aktif.</noscript>
@endsection
