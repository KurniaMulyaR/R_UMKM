@extends('layouts.app')
@section('title','Umur piutang')
@section('crumb','Umur piutang')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); @endphp
<div class="page-heading"><div><div class="eyebrow">PELANGGAN</div><h1>Umur piutang</h1><p>Sisa kasbon per pelanggan, dikelompokkan menurut usia sejak tanggal transaksi (semua outlet). Total piutang: {{ $rp($grandTotal) }}.</p></div></div>
<div class="card section-card"><table class="table"><thead><tr><th>Pelanggan</th>@foreach($buckets as $label)<th>{{ $label }}</th>@endforeach<th>Total</th><th>Batas kredit</th></tr></thead><tbody>
@forelse($customers as $customer)
    <tr><td><b>{{ $customer['name'] }}</b></td>@foreach($buckets as $key => $label)<td>{{ $customer['buckets'][$key] > 0 ? $rp($customer['buckets'][$key]) : '—' }}</td>@endforeach
        <td><b>{{ $rp($customer['total']) }}</b></td><td>{{ $customer['limit'] !== null ? $rp($customer['limit']) : 'Tanpa batas' }}</td></tr>
    @foreach($customer['sales'] as $sale)
        <tr class="muted"><td colspan="{{ count($buckets) + 3 }}">↳ <a class="small-link" href="{{ route('sales.show', $sale->id) }}">{{ $sale->sale_number }}</a> · {{ \Illuminate\Support\Carbon::parse($sale->sold_at)->format('d/m/Y') }} · {{ $sale->age_days }} hari · sisa {{ $rp($sale->outstanding) }}</td></tr>
    @endforeach
@empty
    <tr><td colspan="{{ count($buckets) + 3 }}" class="muted">Tidak ada piutang berjalan.</td></tr>
@endforelse
</tbody>@if($customers)<tfoot><tr><th>Total</th>@foreach($buckets as $key => $label)<th>{{ $rp($totals[$key]) }}</th>@endforeach<th>{{ $rp($grandTotal) }}</th><th></th></tr></tfoot>@endif
</table></div>
@endsection
