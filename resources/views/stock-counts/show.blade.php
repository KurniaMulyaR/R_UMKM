@extends('layouts.app')
@section('title','Opname '.$count->count_number)
@section('crumb','Opname stok')
@section('content')
@php
    $rp = fn ($n) => ($n < 0 ? '− ' : '').'Rp '.number_format(abs($n), 0, ',', '.');
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ',');
    $labels = ['submitted' => 'Menunggu persetujuan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'draft' => 'Draf'];
    $canApprove = in_array($currentRole->key ?? '', ['owner', 'manager'], true);
@endphp
<div class="page-heading"><div><div class="eyebrow">OPNAME STOK</div><h1>{{ $count->count_number }}</h1><p>Status: {{ $labels[$count->status] ?? $count->status }}@if($count->notes) · {{ $count->notes }}@endif</p></div>
    @if($count->status === 'submitted' && $canApprove)
    <div style="display:flex;gap:10px">
        <form method="post" action="{{ route('stock-counts.approve', $count->id) }}">@csrf<button class="btn btn-primary">Setujui &amp; terapkan</button></form>
        <form method="post" action="{{ route('stock-counts.reject', $count->id) }}">@csrf<button class="btn btn-danger">Tolak</button></form>
    </div>@endif</div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<div class="card section-card">
    <table class="table"><thead><tr><th>Produk</th><th>Sistem</th><th>Fisik</th><th>Selisih</th><th>Nilai selisih</th></tr></thead><tbody>
    @foreach($items as $item)
        <tr><td>{{ $item->name }}</td><td>{{ $qty($item->system_quantity_snapshot) }}</td><td>{{ $qty($item->counted_quantity) }}</td>
            <td>{{ (float) $item->variance > 0 ? '+' : '' }}{{ $qty($item->variance) }}</td><td>{{ $rp((float) $item->variance * (float) $item->unit_cost_snapshot) }}</td></tr>
    @endforeach
    </tbody></table>
</div>
@endsection
