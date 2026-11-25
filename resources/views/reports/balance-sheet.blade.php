@extends('layouts.app')
@section('title','Neraca')
@section('crumb','Neraca')
@section('content')
@php
    $rp = fn ($n) => ($n < 0 ? '− ' : '').'Rp '.number_format(abs($n), 0, ',', '.');
    $isOwner = ($currentRole->key ?? '') === 'owner';
@endphp
<div class="page-heading"><div><div class="eyebrow">LAPORAN KEUANGAN</div><h1>Neraca</h1><p>Posisi keuangan per {{ \Illuminate\Support\Carbon::parse($asOf)->locale('id')->translatedFormat('d F Y') }}, dihitung dari jurnal.</p></div>
    <form method="get" class="toolbar" style="margin:0"><input type="date" name="as_of" value="{{ $asOf }}" aria-label="Per tanggal" style="max-width:170px"><button class="btn btn-secondary">Tampilkan</button></form></div>
<div class="feature-summary">
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">Rp</span><div><small>Total aset</small><b>{{ $rp($totalAssets) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon partial-icon">Rp</span><div><small>Total kewajiban</small><b>{{ $rp($totalLiabilities) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon planned-icon">Rp</span><div><small>Total ekuitas</small><b>{{ $rp($totalEquity) }}</b></div></div>
</div>
@if(abs($difference) > 0.01)<div class="alert error">Neraca tidak seimbang, selisih {{ $rp($difference) }}. Periksa jurnal yang diposting manual atau data lama.</div>@endif
<div class="detail-grid">
    <div class="card section-card"><div class="section-title"><h2>Aset</h2></div>
        <div class="detail-list">
            @forelse($assets as $row)<div class="detail-row"><span>{{ $row->code }} · {{ $row->name }}</span><b>{{ $rp($row->amount) }}</b></div>@empty<span class="muted">Belum ada saldo aset.</span>@endforelse
            <div class="detail-row" style="background:var(--primary-soft);padding:11px 10px;border-radius:10px"><b>Total aset</b><b>{{ $rp($totalAssets) }}</b></div>
        </div>
    </div>
    <div class="card section-card"><div class="section-title"><h2>Kewajiban &amp; ekuitas</h2></div>
        <div class="detail-list">
            @forelse($liabilities as $row)<div class="detail-row"><span>{{ $row->code }} · {{ $row->name }}</span><b>{{ $rp($row->amount) }}</b></div>@empty<div class="detail-row"><span class="muted">Tidak ada kewajiban.</span><b>Rp 0</b></div>@endforelse
            <div class="detail-row"><b>Total kewajiban</b><b>{{ $rp($totalLiabilities) }}</b></div>
            @foreach($equity as $row)<div class="detail-row"><span>{{ $row->code }} · {{ $row->name }}</span><b>{{ $rp($row->amount) }}</b></div>@endforeach
            <div class="detail-row"><span>Laba ditahan (laba bersih sampai tanggal ini)</span><b>{{ $rp($earnings) }}</b></div>
            <div class="detail-row"><b>Total ekuitas</b><b>{{ $rp($totalEquity) }}</b></div>
            <div class="detail-row" style="background:var(--primary-soft);padding:11px 10px;border-radius:10px"><b>Kewajiban + ekuitas</b><b>{{ $rp($totalLiabilities + $totalEquity) }}</b></div>
        </div>
    </div>
</div>
@if($isOwner)
<div class="card section-card"><div class="section-title"><h2>Modal &amp; prive pemilik</h2></div>
    <p class="muted" style="margin-bottom:12px">Catat modal awal agar saldo kas dan bank di neraca sesuai kenyataan. Prive adalah uang usaha yang diambil pemilik.</p>
    <form method="post" action="{{ route('reports.equity.store') }}" class="form-grid" style="align-items:end">@csrf
        <label>Jenis<select name="kind"><option value="capital">Setoran modal</option><option value="draw">Prive (pengambilan pemilik)</option></select></label>
        <label>Ke / dari<select name="method"><option value="cash">Kas tunai</option><option value="bank_transfer">Rekening bank</option></select></label>
        <label>Nominal<input type="number" name="amount" min="1" step="1" required></label>
        <label>Tanggal<input type="date" name="entry_date" required max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}"></label>
        <label>Catatan <span class="optional">opsional</span><input name="note" maxlength="300"></label>
        <button class="btn btn-primary">Catat</button>
    </form>
</div>
@endif
@endsection
