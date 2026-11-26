@extends('layouts.app')
@section('title','Edit pesanan')
@section('crumb')<a href="{{ route('sales.index') }}">Penjualan</a> <span>/</span> <a href="{{ route('sales.show',$sale->id) }}">{{ $sale->sale_number }}</a> <span>/</span> Koreksi pesanan@endsection
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ','); @endphp
<div class="page-heading"><div><div class="eyebrow">EDIT / KOREKSI PESANAN</div><h1>{{ $sale->sale_number }}</h1><p>Transaksi tersimpan sebagai histori tetap. Koreksi item dicatat sebagai retur agar stok dan laporan keuangan tetap sesuai. {{ $sale->customer_name ?: 'Pelanggan umum' }} · total {{ $rp($sale->grand_total) }}@if((float) $sale->returned_total > 0) · sudah diretur {{ $rp($sale->returned_total) }}@endif</p></div>
    <a class="btn btn-secondary" href="{{ route('sales.show',$sale->id) }}">← Kembali</a></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('sales.return.store',$sale->id) }}" class="card section-card">@csrf
    <div class="section-title"><h2>Item yang dikoreksi</h2></div>
    <div class="table-wrap"><table>
        <thead><tr><th>BARANG</th><th>DIBELI</th><th>SUDAH DIRETUR</th><th>HARGA NET/UNIT</th><th>RETUR SEKARANG</th><th>KONDISI</th></tr></thead>
        <tbody>@foreach($items as $item)
            <tr><td class="td-main">{{ $item->name_snapshot }}</td><td>{{ $qty($item->quantity) }}</td><td>{{ $qty($item->returned) }}</td><td>{{ $rp((float) $item->line_total / max((float) $item->quantity, 0.0001)) }}</td>
            @if($item->remaining > 0)
                <td><input type="number" name="items[{{ $item->id }}][quantity]" min="0" max="{{ $item->remaining }}" step="any" value="{{ old('items.'.$item->id.'.quantity') }}" style="max-width:110px"></td>
                <td><select name="items[{{ $item->id }}][condition]"><option value="good">Baik (masuk stok)</option><option value="damaged">Rusak (tidak masuk stok)</option></select></td>
            @else<td colspan="2" class="muted">Sudah diretur penuh</td>@endif</tr>
        @endforeach</tbody>
    </table></div>
    <div class="form-grid" style="margin-top:14px">
        <label>Alasan retur<input name="reason" required minlength="3" maxlength="255" value="{{ old('reason') }}" placeholder="Misal: barang cacat"></label>
        <label>Uang dikembalikan lewat<select name="refund_method"><option value="">—</option><option value="cash">Tunai (dari laci)</option><option value="bank_transfer">Transfer bank</option><option value="qris_external">QRIS / e-wallet</option></select></label>
    </div>
    <p class="muted" style="font-size:12px;margin-top:10px">Sisa kasbon transaksi ini {{ $rp($outstanding) }}. Nilai retur dipakai dulu untuk mengurangi kasbon, sisanya dikembalikan sebagai uang lewat metode di atas. Refund pembayaran QRIS Xendit tetap diproses manual lewat Dashboard Xendit.</p>
    <div class="form-actions"><button class="btn btn-primary">Catat retur</button></div>
</form>
<div class="card section-card"><div class="section-title"><h2>Riwayat retur</h2></div>
    @forelse($returns as $return)
        <div class="detail-row"><span><b>{{ $return->return_number }}</b><span class="td-sub">{{ \Illuminate\Support\Carbon::parse($return->returned_at)->format('d M Y H:i') }} · {{ $return->reason }}</span></span>
            <span style="text-align:right"><b>{{ $rp($return->refund_total) }}</b><span class="td-sub">kasbon −{{ $rp($return->receivable_reduction) }} · uang kembali {{ $rp($return->cash_refund_amount) }}</span></span></div>
    @empty<span class="muted">Belum ada retur untuk transaksi ini.</span>@endforelse
</div>
@endsection
