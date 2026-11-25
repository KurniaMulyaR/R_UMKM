@extends('layouts.app')
@section('title','Transfer stok')
@section('crumb','Transfer stok')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); $labels = ['in_transit' => 'Dalam perjalanan', 'received' => 'Diterima', 'cancelled' => 'Dibatalkan']; @endphp
<div class="page-heading"><div><div class="eyebrow">PERSEDIAAN</div><h1>Transfer stok</h1><p>Kirim barang dari outlet aktif ({{ $outlet->name }}) ke outlet lain. Outlet tujuan harus menerima agar stok bertambah.</p></div>
    <a class="btn btn-primary" href="{{ route('stock-transfers.create') }}">Transfer baru</a></div>
<div class="card section-card"><div class="section-title"><h2>Riwayat transfer</h2></div>
    <table class="table"><thead><tr><th>Nomor</th><th>Arah</th><th>Dari</th><th>Ke</th><th>Status</th><th>Nilai</th><th></th></tr></thead><tbody>
    @forelse($transfers as $transfer)
        <tr><td>{{ $transfer->transfer_number }}</td><td>{{ $transfer->from_outlet_id === $outlet->id ? 'Keluar' : 'Masuk' }}</td><td>{{ $transfer->from_name }}</td><td>{{ $transfer->to_name }}</td>
            <td>{{ $labels[$transfer->status] ?? $transfer->status }}</td><td>{{ $rp($transfer->total_value) }}</td><td><a class="small-link" href="{{ route('stock-transfers.show', $transfer->id) }}">Lihat</a></td></tr>
    @empty
        <tr><td colspan="7" class="muted">Belum ada transfer.</td></tr>
    @endforelse
    </tbody></table>
</div>
@endsection
