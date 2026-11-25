@extends('layouts.app')
@section('title','Opname stok')
@section('crumb','Opname stok')
@section('content')
@php $rp = fn ($n) => ($n < 0 ? '− ' : '').'Rp '.number_format(abs($n), 0, ',', '.'); $labels = ['submitted' => 'Menunggu persetujuan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'draft' => 'Draf']; @endphp
<div class="page-heading"><div><div class="eyebrow">PERSEDIAAN</div><h1>Opname stok</h1><p>Hitung stok fisik, bandingkan dengan sistem, lalu setujui agar selisih masuk ke stok dan jurnal.</p></div>
    <a class="btn btn-primary" href="{{ route('stock-counts.create') }}">Opname baru</a></div>
<div class="card section-card"><div class="section-title"><h2>Riwayat opname</h2></div>
    <table class="table"><thead><tr><th>Nomor</th><th>Tanggal</th><th>Status</th><th>Produk dihitung</th><th>Produk selisih</th><th>Nilai selisih</th><th></th></tr></thead><tbody>
    @forelse($counts as $count)
        <tr><td>{{ $count->count_number }}</td><td>{{ \Illuminate\Support\Carbon::parse($count->created_at)->format('d/m/Y H:i') }}</td><td>{{ $labels[$count->status] ?? $count->status }}</td>
            <td>{{ $count->item_count }}</td><td>{{ $count->variance_items }}</td><td>{{ $rp($count->variance_value) }}</td><td><a class="small-link" href="{{ route('stock-counts.show', $count->id) }}">Lihat</a></td></tr>
    @empty
        <tr><td colspan="7" class="muted">Belum ada opname.</td></tr>
    @endforelse
    </tbody></table>
</div>
@endsection
