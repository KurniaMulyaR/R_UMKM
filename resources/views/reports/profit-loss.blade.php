@extends('layouts.app')
@section('title','Laporan laba rugi')
@section('crumb','Laba rugi')
@section('content')
@php
    $rp = fn ($n) => ($n < 0 ? '− ' : '').'Rp '.number_format(abs($n), 0, ',', '.');
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->locale('id')->translatedFormat('d F Y');
@endphp
<div class="page-heading"><div><div class="eyebrow">LAPORAN KEUANGAN</div><h1>Laba rugi</h1><p>{{ $fmt($from) }} – {{ $fmt($to) }} · dihitung dari jurnal yang sudah diposting.</p></div>
    <form method="get" class="toolbar" style="margin:0"><input type="date" name="from" value="{{ $from }}" aria-label="Dari tanggal" style="max-width:165px"><input type="date" name="to" value="{{ $to }}" aria-label="Sampai tanggal" style="max-width:165px"><button class="btn btn-secondary">Tampilkan</button></form></div>
<div class="feature-summary">
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">Rp</span><div><small>Pendapatan</small><b>{{ $rp($totalRevenue) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon planned-icon">Rp</span><div><small>Laba kotor</small><b>{{ $rp($grossProfit) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon {{ $netProfit >= 0 ? 'ready-icon' : 'partial-icon' }}">=</span><div><small>Laba bersih</small><b>{{ $rp($netProfit) }}</b></div></div>
</div>
<div class="card section-card">
    <div class="table-wrap"><table>
        <tbody>
        <tr><th colspan="3">PENDAPATAN</th></tr>
        @forelse($revenue as $row)<tr><td>{{ $row->code }}</td><td class="td-main">{{ $row->name }}</td><td class="money text-right">{{ $rp($row->amount) }}</td></tr>@empty<tr><td colspan="3" class="muted">Belum ada pendapatan pada periode ini.</td></tr>@endforelse
        <tr><td></td><td><b>Total pendapatan</b></td><td class="money text-right"><b>{{ $rp($totalRevenue) }}</b></td></tr>
        <tr><th colspan="3">HARGA POKOK</th></tr>
        @forelse($costOfSales as $row)<tr><td>{{ $row->code }}</td><td class="td-main">{{ $row->name }}</td><td class="money text-right">{{ $rp($row->amount) }}</td></tr>@empty<tr><td colspan="3" class="muted">Tidak ada harga pokok.</td></tr>@endforelse
        <tr><td></td><td><b>Laba kotor</b></td><td class="money text-right"><b>{{ $rp($grossProfit) }}</b></td></tr>
        <tr><th colspan="3">BIAYA OPERASIONAL</th></tr>
        @forelse($operating as $row)<tr><td>{{ $row->code }}</td><td class="td-main">{{ $row->name }}</td><td class="money text-right">{{ $rp($row->amount) }}</td></tr>@empty<tr><td colspan="3" class="muted">Belum ada biaya operasional.</td></tr>@endforelse
        <tr><td></td><td><b>Total biaya operasional</b></td><td class="money text-right"><b>{{ $rp($totalOperating) }}</b></td></tr>
        <tr style="background:var(--primary-soft)"><td></td><td><b>Laba bersih</b></td><td class="money text-right"><b>{{ $rp($netProfit) }}</b></td></tr>
        </tbody>
    </table></div>
    <p class="muted" style="margin-top:12px;font-size:12px">Penyusutan aset masuk laporan setelah diposting dari menu Aset usaha.</p>
</div>
@endsection
