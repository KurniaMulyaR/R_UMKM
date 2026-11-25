@extends('layouts.app')
@section('title','Pesanan beli')
@section('crumb','Pesanan beli')
@section('content')
@php $labels = ['draft'=>'Draf','ordered'=>'Dipesan','partially_received'=>'Sebagian diterima','received'=>'Selesai','cancelled'=>'Dibatalkan']; @endphp
<div class="page-heading"><div><div class="eyebrow">PEMBELIAN</div><h1>Pesanan beli</h1><p>Pesan barang ke supplier, terima stok, dan pantau hutang.</p></div><a class="btn btn-primary" href="{{ route('purchasing.create') }}">＋ Pesanan baru</a></div>
<div class="feature-summary" style="grid-template-columns:repeat(2,minmax(0,1fr))">
    <div class="card feature-stat"><span class="feature-stat-icon partial-icon">Rp</span><div><small>Hutang supplier berjalan</small><b>Rp {{ number_format($payable,0,',','.') }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">▤</span><div><small>Total pesanan</small><b>{{ $orders->total() }}</b></div></div>
</div>
<div class="card section-card">
    <div class="table-wrap"><table>
        <thead><tr><th>NOMOR PO</th><th>SUPPLIER</th><th>DIBUAT</th><th>STATUS</th><th class="text-right">TOTAL</th></tr></thead>
        <tbody>
        @forelse($orders as $order)
            <tr><td><a class="td-main" href="{{ route('purchasing.show',$order->id) }}">{{ $order->po_number }}</a></td><td>{{ $order->supplier_name }}</td><td>{{ \Illuminate\Support\Carbon::parse($order->created_at)->format('d M Y') }}</td><td><span class="status {{ in_array($order->status,['draft','ordered','partially_received']) ? 'pending' : ($order->status === 'cancelled' ? 'voided' : '') }}">{{ $labels[$order->status] ?? $order->status }}</span></td><td class="money text-right">Rp {{ number_format($order->total,0,',','.') }}</td></tr>
        @empty
            <tr><td colspan="5"><div class="empty-state"><div class="empty-icon">▤</div><b>Belum ada pesanan beli</b><span>Buat pesanan pertama setelah menambahkan supplier.</span><a class="btn btn-secondary" href="{{ route('purchasing.suppliers') }}">Kelola supplier</a></div></td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="pagination">{{ $orders->links() }}</div>
</div>
@endsection
