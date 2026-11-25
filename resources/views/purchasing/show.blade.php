@extends('layouts.app')
@section('title',$order->po_number)
@section('crumb','Pesanan beli')
@section('content')
@php
    $labels = ['draft'=>'Draf','ordered'=>'Dipesan','partially_received'=>'Sebagian diterima','received'=>'Selesai','cancelled'=>'Dibatalkan'];
    $canManage = in_array($currentRole->key ?? '', ['owner','manager'], true);
    $canReceive = in_array($order->status, ['ordered','partially_received'], true);
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ',');
@endphp
<div class="page-heading">
    <div><div class="eyebrow">PESANAN BELI</div><h1>{{ $order->po_number }}</h1><p>{{ $order->supplier_name }} · dibuat {{ \Illuminate\Support\Carbon::parse($order->created_at)->format('d M Y') }}</p></div>
    <div style="display:flex;gap:10px;align-items:center"><span class="status {{ in_array($order->status,['draft','ordered','partially_received']) ? 'pending' : ($order->status === 'cancelled' ? 'voided' : '') }}">{{ $labels[$order->status] ?? $order->status }}</span>
    @if($canManage && $order->status === 'draft')<form method="post" action="{{ route('purchasing.approve',$order->id) }}">@csrf<button class="btn btn-primary">Setujui &amp; pesan</button></form>@endif</div>
</div>

<form method="post" action="{{ route('purchasing.receive',$order->id) }}" class="card section-card">@csrf
    <div class="section-title"><h2>Barang pesanan</h2></div>
    <div class="table-wrap"><table>
        <thead><tr><th>BARANG</th><th>DIPESAN</th><th>DITERIMA</th><th>HARGA BELI</th><th class="text-right">JUMLAH</th>@if($canReceive)<th>TERIMA SEKARANG</th>@endif</tr></thead>
        <tbody>
        @foreach($items as $item)
            @php $remaining = (float) $item->ordered_quantity - (float) $item->received_quantity; @endphp
            <tr><td class="td-main">{{ $item->name_snapshot }}<span class="td-sub">{{ $item->sku_snapshot }}</span></td><td>{{ $qty($item->ordered_quantity) }}</td><td>{{ $qty($item->received_quantity) }}</td><td>Rp {{ number_format($item->unit_cost,0,',','.') }}</td><td class="money text-right">Rp {{ number_format($item->line_total,0,',','.') }}</td>
            @if($canReceive)<td>@if($remaining > 0)<input type="number" name="quantities[{{ $item->id }}]" min="0" max="{{ $remaining }}" step="0.0001" value="{{ $remaining }}" style="max-width:130px">@else<span class="muted">Lengkap</span>@endif</td>@endif</tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="divider"></div>
    <div class="detail-row"><span>Total pesanan</span><b>Rp {{ number_format($order->total,0,',','.') }}</b></div>
    @if($canReceive)
        <div class="form-grid" style="margin-top:14px"><label>Nomor faktur supplier <span class="optional">opsional</span><input name="supplier_invoice_number" maxlength="128"></label></div>
        <div class="form-actions"><button class="btn btn-primary">Terima barang</button></div>
    @endif
</form>

<div class="card section-card">
    <div class="section-title"><h2>Tagihan supplier</h2></div>
    @forelse($bills as $bill)
        <div class="detail-row" style="align-items:center;flex-wrap:wrap">
            <span><b>{{ $bill->bill_number }}</b><span class="td-sub">Jatuh tempo {{ \Illuminate\Support\Carbon::parse($bill->due_at)->format('d M Y') }}{{ $bill->supplier_invoice_number ? ' · Faktur '.$bill->supplier_invoice_number : '' }}</span></span>
            <span style="text-align:right"><b>Rp {{ number_format($bill->total,0,',','.') }}</b><span class="td-sub">Sisa Rp {{ number_format($bill->balance_due,0,',','.') }} · <span class="status {{ $bill->status === 'paid' ? '' : 'pending' }}">{{ ['open'=>'Belum dibayar','partial'=>'Dicicil','paid'=>'Lunas'][$bill->status] ?? $bill->status }}</span></span></span>
        </div>
        @if($canManage && in_array($bill->status,['open','partial'],true))
            <form method="post" action="{{ route('purchasing.pay',$bill->id) }}" class="form-grid" style="align-items:end;margin:6px 0 16px">@csrf
                <label>Metode<select name="method"><option value="cash">Tunai</option><option value="bank_transfer">Transfer bank</option></select></label>
                <label>Nominal<input type="number" name="amount" min="1" max="{{ $bill->balance_due }}" step="1" value="{{ (int) $bill->balance_due }}" required></label>
                <button class="btn btn-secondary">Bayar supplier</button>
            </form>
        @endif
    @empty
        <span class="muted">Tagihan dibuat otomatis setiap barang diterima.</span>
    @endforelse
</div>

@if($canManage && in_array($order->status,['draft','ordered'],true))
<details class="card section-card"><summary class="small-link">Batalkan pesanan</summary>
    <form method="post" action="{{ route('purchasing.cancel',$order->id) }}" class="form-stack" style="margin-top:14px">@csrf
        <label>Alasan pembatalan<input name="reason" required minlength="3" maxlength="500"></label>
        <button class="btn btn-danger">Batalkan pesanan</button>
    </form>
</details>
@endif
@endsection
