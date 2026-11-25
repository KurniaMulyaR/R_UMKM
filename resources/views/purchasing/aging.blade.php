@extends('layouts.app')
@section('title','Umur hutang')
@section('crumb','Umur hutang')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); @endphp
<div class="page-heading"><div><div class="eyebrow">PEMBELIAN</div><h1>Umur hutang</h1><p>Tagihan supplier yang belum lunas, dikelompokkan menurut keterlambatan dari jatuh tempo.</p></div><a class="btn btn-secondary" href="{{ route('purchasing.index') }}">Pesanan beli</a></div>
<div class="feature-summary" style="grid-template-columns:repeat(5,minmax(0,1fr))">
    @foreach($buckets as $key => $label)
        <div class="card feature-stat"><span class="feature-stat-icon {{ $key === 'current' ? 'ready-icon' : ($key === 'd30' ? 'partial-icon' : 'planned-icon') }}">{{ $key === 'current' ? '✓' : '!' }}</span><div><small>{{ $label }}</small><b style="font-size:16px">{{ $rp($totals[$key]) }}</b></div></div>
    @endforeach
</div>
<div class="card section-card"><div class="section-title"><h2>Per supplier</h2><span class="status pending">Total {{ $rp($grandTotal) }}</span></div>
    <div class="table-wrap"><table>
        <thead><tr><th>SUPPLIER</th>@foreach($buckets as $label)<th class="text-right">{{ strtoupper($label) }}</th>@endforeach<th class="text-right">TOTAL</th></tr></thead>
        <tbody>
        @forelse($suppliers as $row)
            <tr><td class="td-main">{{ $row['name'] }}</td>@foreach($buckets as $key => $label)<td class="money text-right">{{ $row['buckets'][$key] > 0 ? $rp($row['buckets'][$key]) : '—' }}</td>@endforeach<td class="money text-right"><b>{{ $rp($row['total']) }}</b></td></tr>
        @empty
            <tr><td colspan="{{ count($buckets) + 2 }}"><div class="empty-state"><div class="empty-icon">✓</div><b>Tidak ada hutang berjalan</b><span>Semua tagihan supplier sudah lunas.</span></div></td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@if($bills->isNotEmpty())
<div class="card section-card"><div class="section-title"><h2>Rincian tagihan</h2></div>
    <div class="table-wrap"><table>
        <thead><tr><th>TAGIHAN</th><th>SUPPLIER</th><th>JATUH TEMPO</th><th>TERLAMBAT</th><th class="text-right">SISA</th></tr></thead>
        <tbody>@foreach($bills as $bill)<tr><td class="td-main">@if($bill->purchase_order_id)<a href="{{ route('purchasing.show',$bill->purchase_order_id) }}">{{ $bill->bill_number }}</a>@else{{ $bill->bill_number }}@endif</td><td>{{ $bill->supplier_name }}</td><td>{{ $bill->due_at ? \Illuminate\Support\Carbon::parse($bill->due_at)->format('d M Y') : '—' }}</td><td>@if($bill->days_late > 0)<span class="status pending">{{ $bill->days_late }} hari</span>@else<span class="status">Belum</span>@endif</td><td class="money text-right">{{ $rp($bill->balance_due) }}</td></tr>@endforeach</tbody>
    </table></div>
</div>
@endif
@endsection
