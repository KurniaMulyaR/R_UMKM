@extends('layouts.app')
@section('title','Retur pembelian')
@section('crumb','Pesanan beli')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ','); @endphp
<div class="page-heading"><div><div class="eyebrow">RETUR PEMBELIAN</div><h1>{{ $bill->bill_number }}</h1><p>{{ $bill->supplier_name }} · sisa tagihan {{ $rp($bill->balance_due) }}</p></div>@if($bill->purchase_order_id)<a class="btn btn-secondary" href="{{ route('purchasing.show',$bill->purchase_order_id) }}">Kembali ke pesanan</a>@endif</div>
@if(in_array($bill->status,['open','partial'],true))
<form method="post" action="{{ route('purchasing.return.store',$bill->id) }}" class="card section-card">@csrf
    <div class="section-title"><h2>Barang yang diretur</h2></div>
    <div class="table-wrap"><table>
        <thead><tr><th>BARANG</th><th>DITAGIH</th><th>SUDAH DIRETUR</th><th>HARGA</th><th>RETUR SEKARANG</th></tr></thead>
        <tbody>@foreach($items as $item)<tr><td class="td-main">{{ $item->description_snapshot }}</td><td>{{ $qty($item->quantity) }}</td><td>{{ $qty($item->returned) }}</td><td>{{ $rp($item->unit_cost) }}</td><td>@if($item->remaining > 0)<input type="number" name="quantities[{{ $item->id }}]" min="0" max="{{ $item->remaining }}" step="0.0001" value="0" style="max-width:130px">@else<span class="muted">Habis</span>@endif</td></tr>@endforeach</tbody>
    </table></div>
    <div class="form-grid" style="margin-top:14px"><label>Alasan retur<input name="reason" required minlength="3" maxlength="500" value="{{ old('reason') }}" placeholder="Misal: barang rusak"></label></div>
    <p class="muted" style="font-size:12px;margin-top:10px">Retur mengurangi stok dan sisa tagihan. Nilainya tidak boleh melebihi sisa tagihan.</p>
    <div class="form-actions"><button class="btn btn-primary">Catat retur</button></div>
</form>
@else
<div class="card empty-state"><div class="empty-icon">✓</div><b>Tagihan sudah lunas</b><span>Retur untuk tagihan lunas butuh refund dari supplier dan belum didukung.</span></div>
@endif
<div class="card section-card"><div class="section-title"><h2>Riwayat retur</h2></div>
    @forelse($returns as $return)<div class="detail-row"><span><b>{{ $return->return_number }}</b><span class="td-sub">{{ \Illuminate\Support\Carbon::parse($return->returned_at)->format('d M Y') }} · {{ $return->reason }}</span></span><b>{{ $rp($return->total) }}</b></div>@empty<span class="muted">Belum ada retur untuk tagihan ini.</span>@endforelse
</div>
@endsection
