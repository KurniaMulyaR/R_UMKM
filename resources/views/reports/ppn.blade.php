@extends('layouts.app')
@section('title','Laporan PPN')
@section('crumb','Laporan PPN')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); @endphp
<div class="page-heading"><div><div class="eyebrow">LAPORAN</div><h1>Laporan PPN</h1><p>PPN keluaran dari penjualan, pembatalan/retur, dan setoran ke negara.@if(! $settings['enabled']) PPN sedang tidak dipungut di kasir.@endif</p></div>
    <form method="get" style="display:flex;gap:8px;align-items:end"><label>Bulan<input type="month" name="month" value="{{ $month }}"></label><button class="btn btn-secondary">Tampilkan</button></form></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<div class="feature-summary" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <div class="card feature-stat"><div><small>Penjualan kena PPN (DPP)</small><b>{{ $rp($taxable) }}</b></div></div>
    <div class="card feature-stat"><div><small>PPN dipungut</small><b>{{ $rp($collected) }}</b></div></div>
    <div class="card feature-stat"><div><small>Dikurangi batal/retur</small><b>{{ $rp($reversed) }}</b></div></div>
    <div class="card feature-stat"><div><small>PPN keluaran bersih</small><b>{{ $rp($net) }}</b></div></div>
</div>
<div class="card section-card"><h2>{{ $monthLabel }}</h2>
    <p>Setoran bulan ini: <b>{{ $rp($paid) }}</b> · Saldo Hutang PPN keseluruhan: <b>{{ $rp($balance) }}</b></p></div>
@if(($currentRole->key ?? '') === 'owner')
<form method="post" action="{{ route('reports.ppn.pay') }}" class="card form-card">@csrf
    <h2>Catat setoran PPN</h2>
    <div class="form-grid">
        <label>Nominal<input type="number" name="amount" required min="1" step="1" value="{{ old('amount', max(0, (int) $balance)) }}"></label>
        <label>Tanggal<input type="date" name="paid_on" required max="{{ now()->toDateString() }}" value="{{ old('paid_on', now()->toDateString()) }}"></label>
        <label>Dibayar dari<select name="method"><option value="bank_transfer">Rekening bank</option><option value="cash">Kas tunai</option></select></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Catat setoran</button></div>
</form>
@endif
<div class="card section-card"><h2>Setoran terakhir</h2><div class="table-wrap"><table><thead><tr><th>NO</th><th>TANGGAL</th></tr></thead><tbody>
@forelse($payments as $p)<tr><td>{{ $p->entry_number }}</td><td>{{ \Illuminate\Support\Carbon::parse($p->entry_date)->format('d M Y') }}</td></tr>@empty<tr><td colspan="2" class="muted">Belum ada setoran.</td></tr>@endforelse
</tbody></table></div></div>
@endsection
