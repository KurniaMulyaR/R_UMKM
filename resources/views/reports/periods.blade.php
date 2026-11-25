@extends('layouts.app')
@section('title','Tutup periode')
@section('crumb','Tutup periode')
@section('content')
@php
    $isOwner = ($currentRole->key ?? '') === 'owner';
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->locale('id')->translatedFormat('d F Y');
@endphp
<div class="page-heading"><div><div class="eyebrow">LAPORAN KEUANGAN</div><h1>Tutup periode</h1><p>Setelah sebuah bulan ditutup, transaksi, biaya, aset, dan pembatalan bertanggal di dalamnya tidak bisa diubah lagi.</p></div></div>
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<div class="feature-summary">
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">✓</span><div><small>Ditutup sampai</small><b>{{ $closedThrough ? $fmt($closedThrough) : 'Belum ada' }}</b></div></div>
</div>
@if($isOwner)
<div class="detail-grid">
    <div class="card section-card"><div class="section-title"><h2>Tutup bulan</h2></div>
        <form method="post" action="{{ route('reports.periods.close') }}" class="form-stack">@csrf
            <label>Bulan<input type="month" name="period" value="{{ old('period', $suggested) }}" max="{{ $lastClosable }}" required></label>
            <label>Catatan<input name="note" maxlength="300" placeholder="Opsional"></label>
            <button class="btn btn-primary">Tutup bulan ini</button>
        </form>
    </div>
    <div class="card section-card"><div class="section-title"><h2>Buka kembali</h2></div>
        <p class="muted">Hanya bulan terakhir yang sudah ditutup yang bisa dibuka. Tindakan ini dicatat di audit log.</p>
        <form method="post" action="{{ route('reports.periods.reopen') }}" class="form-stack">@csrf
            <label>Alasan<input name="reason" required minlength="3" maxlength="300"></label>
            <button class="btn btn-danger" @disabled(! $closedThrough)>Buka kembali periode terakhir</button>
        </form>
    </div>
</div>
@endif
<div class="card section-card"><div class="section-title"><h2>Riwayat</h2></div>
    <table class="table"><thead><tr><th>Bulan</th><th>Status</th><th>Ditutup oleh</th><th>Catatan</th></tr></thead><tbody>
    @forelse($history as $row)
        <tr><td>{{ \Illuminate\Support\Carbon::parse($row->starts_on)->locale('id')->translatedFormat('F Y') }}</td><td>{{ $row->status === 'closed' ? 'Ditutup' : 'Dibuka kembali' }}</td><td>{{ $row->closed_by_name ?? '-' }}</td><td>{{ $row->close_note }}</td></tr>
    @empty
        <tr><td colspan="4" class="muted">Belum ada periode yang ditutup.</td></tr>
    @endforelse
    </tbody></table>
</div>
@endsection
