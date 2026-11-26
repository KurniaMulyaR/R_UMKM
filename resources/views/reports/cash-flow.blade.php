@extends('layouts.app')
@section('title','Arus kas')
@section('crumb','Arus kas')
@section('content')
@php $rp = fn ($n) => ($n < 0 ? '− ' : '').'Rp '.number_format(abs($n), 0, ',', '.'); @endphp
<div class="page-heading"><div><div class="eyebrow">LAPORAN KEUANGAN</div><h1>Arus kas</h1><p>Uang masuk dan keluar di Kas, Bank, dan QRIS (semua outlet), dihitung dari jurnal.</p></div>
    <form method="get" class="toolbar" style="margin:0"><input type="date" name="from" value="{{ $from }}" aria-label="Dari" style="max-width:160px"><input type="date" name="to" value="{{ $to }}" aria-label="Sampai" style="max-width:160px"><button class="btn btn-secondary">Tampilkan</button>
        <a class="btn btn-secondary" href="{{ route('reports.cash-flow', ['from' => $from, 'to' => $to, 'format' => 'csv']) }}">Unduh CSV</a></form></div>
<div class="feature-summary">
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">Rp</span><div><small>Saldo kas awal</small><b>{{ $rp($opening) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon partial-icon">Rp</span><div><small>Kenaikan (penurunan) bersih</small><b>{{ $rp($net) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon planned-icon">Rp</span><div><small>Saldo kas akhir</small><b>{{ $rp($closing) }}</b></div></div>
</div>
@if(abs($opening + $net - $closing) > 0.01)<div class="alert error">Saldo awal + arus bersih tidak sama dengan saldo akhir. Periksa jurnal bertanggal ganda atau data lama.</div>@endif
@foreach($sectionLabels as $key => $label)
<div class="card section-card"><div class="section-title"><h2>{{ $label }}</h2></div>
    <table class="table"><thead><tr><th>Akun lawan</th><th>Masuk</th><th>Keluar</th><th>Bersih</th></tr></thead><tbody>
    @forelse($sections[$key] as $row)
        <tr><td>{{ $row['name'] }}</td><td>{{ $rp($row['in']) }}</td><td>{{ $rp($row['out']) }}</td><td>{{ $rp($row['in'] - $row['out']) }}</td></tr>
    @empty
        <tr><td colspan="4" class="muted">Tidak ada pergerakan kas.</td></tr>
    @endforelse
        <tr><td><b>Total</b></td><td colspan="2"></td><td><b>{{ $rp($totals[$key]) }}</b></td></tr>
    </tbody></table>
</div>
@endforeach
<div class="card section-card"><div class="section-title"><h2>Saldo per akun kas</h2></div>
    <table class="table"><thead><tr><th>Akun</th><th>Awal</th><th>Akhir</th></tr></thead><tbody>
    @foreach($byAccount as $account)<tr><td>{{ $account['name'] }}</td><td>{{ $rp($account['opening']) }}</td><td>{{ $rp($account['closing']) }}</td></tr>@endforeach
    </tbody></table>
</div>
@endsection
