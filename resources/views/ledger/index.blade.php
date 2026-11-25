@extends('layouts.app')
@section('title','Buku besar')
@section('crumb','Buku besar')
@section('content')
@php $rp = fn ($n) => ($n < 0 ? '− ' : '').'Rp '.number_format(abs($n), 0, ',', '.'); $dt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d/m/Y'); @endphp
<div class="page-heading"><div><div class="eyebrow">LAPORAN KEUANGAN</div><h1>Buku besar</h1><p>Mutasi per akun dengan saldo berjalan, dihitung dari jurnal yang sudah diposting.</p></div>
    <form method="get" class="toolbar" style="margin:0;flex-wrap:wrap">
        <select name="account" aria-label="Akun" style="max-width:260px">@foreach($accounts as $item)<option value="{{ $item->id }}" @selected($account && $account->id === $item->id)>{{ $item->code }} · {{ $item->name }}</option>@endforeach</select>
        <input type="date" name="from" value="{{ $from }}" aria-label="Dari tanggal" style="max-width:165px"><input type="date" name="to" value="{{ $to }}" aria-label="Sampai tanggal" style="max-width:165px">
        <button class="btn btn-secondary">Tampilkan</button></form></div>
@if($account)
<div class="feature-summary">
    <div class="card feature-stat"><span class="feature-stat-icon planned-icon">Rp</span><div><small>Saldo awal</small><b>{{ $rp($opening) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">+</span><div><small>Debit / Kredit</small><b>{{ $rp($totalDebit) }} / {{ $rp($totalCredit) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon partial-icon">=</span><div><small>Saldo akhir</small><b>{{ $rp($closing) }}</b></div></div>
</div>
@if($truncated)<div class="alert error">Hanya 1.000 baris pertama yang ditampilkan. Persempit rentang tanggal.</div>@endif
<div class="card section-card">
    <table class="table"><thead><tr><th>Tanggal</th><th>Nomor</th><th>Keterangan</th><th>Debit</th><th>Kredit</th><th>Saldo</th></tr></thead><tbody>
        <tr><td colspan="5"><b>Saldo awal</b></td><td><b>{{ $rp($opening) }}</b></td></tr>
        @forelse($rows as $row)
        <tr><td>{{ $dt($row->entry_date) }}</td><td><a class="small-link" href="{{ route('journals.show', $row->entry_id) }}">{{ $row->entry_number }}</a></td><td>{{ $row->description ?: $row->entry_description }}</td>
            <td>{{ (float) $row->debit > 0 ? $rp($row->debit) : '' }}</td><td>{{ (float) $row->credit > 0 ? $rp($row->credit) : '' }}</td><td>{{ $rp($row->balance) }}</td></tr>
        @empty
        <tr><td colspan="6" class="muted">Tidak ada mutasi pada rentang ini.</td></tr>
        @endforelse
    </tbody></table>
</div>
@else<div class="card section-card"><span class="muted">Belum ada akun.</span></div>@endif
@endsection
