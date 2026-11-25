@extends('layouts.app')
@section('title','Retur pembelian')
@section('crumb','Pesanan beli')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ','); @endphp
<div class="page-heading"><div><div class="eyebrow">RETUR PEMBELIAN</div><h1>{{ $bill->bill_number }}</h1><p>{{ $bill->supplier_name }} · sisa tagihan {{ $rp($bill->balance_due) }}</p></div>@if($bill->purchase_order_id)<a class="btn btn-secondary" href="{{ route('purchasing.show',$bill->purchase_order_id) }}">Kembali ke pesanan</a>@endif</div>
<form method="post" action="{{ route('purchasing.return.store',$bill->id) }}" class="card section-card">@csrf
    <div class="section-title"><h2>Barang yang diretur</h2></div>
    <div class="table-wrap"><table>
        <thead><tr><th>BARANG</th><th>DITAGIH</th><th>SUDAH DIRETUR</th><th>HARGA</th><th>RETUR SEKARANG</th></tr></thead>
        <tbody>@foreach($items as $item)<tr><td class="td-main">{{ $item->description_snapshot }}</td><td>{{ $qty($item->quantity) }}</td><td>{{ $qty($item->returned) }}</td><td>{{ $rp($item->unit_cost) }}</td><td>@if($item->remaining > 0)<input type="number" name="quantities[{{ $item->id }}]" min="0" max="{{ $item->remaining }}" step="0.0001" value="0" style="max-width:130px">@else<span class="muted">Habis</span>@endif</td></tr>@endforeach</tbody>
    </table></div>
    <div class="form-grid" style="margin-top:14px"><label>Alasan retur<input name="reason" required minlength="3" maxlength="500" value="{{ old('reason') }}" placeholder="Misal: barang rusak"></label></div>
    <p class="muted" style="font-size:12px;margin-top:10px">Retur mengurangi stok dan sisa tagihan. Jika nilai retur lebih besar dari sisa tagihan (misalnya sudah dibayar), selisihnya menjadi refund yang menunggu diterima dari supplier.</p>
    <div class="form-actions"><button class="btn btn-primary">Catat retur</button></div>
</form>
<div class="card section-card"><div class="section-title"><h2>Riwayat retur</h2></div>
    @forelse($returns as $return)
        @php $outstanding = (float) $return->refund_due - (float) $return->refunded_total; @endphp
        <div class="detail-row"><span><b>{{ $return->return_number }}</b><span class="td-sub">{{ \Illuminate\Support\Carbon::parse($return->returned_at)->format('d M Y') }} · {{ $return->reason }}</span></span><span style="text-align:right"><b>{{ $rp($return->total) }}</b>@if($return->refund_due > 0)<span class="td-sub">Refund {{ $rp($return->refunded_total) }} dari {{ $rp($return->refund_due) }}</span>@endif</span></div>
        @if($outstanding > 0)
            <form method="post" action="{{ route('purchasing.return.refund',$return->id) }}" class="form-grid" style="align-items:end;margin:6px 0 16px">@csrf
                <label>Diterima ke<select name="method"><option value="cash">Kas tunai</option><option value="bank_transfer">Rekening bank</option></select></label>
                <label>Nominal<input type="number" name="amount" min="1" max="{{ $outstanding }}" step="1" value="{{ (int) $outstanding }}" required></label>
                <button class="btn btn-secondary">Catat refund diterima</button>
            </form>
        @endif
    @empty<span class="muted">Belum ada retur untuk tagihan ini.</span>@endforelse
</div>
@endsection
