@extends('layouts.app')
@section('title', $transfer->transfer_number)
@section('crumb', $transfer->transfer_number)
@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ',');
    $labels = ['in_transit' => 'Dalam perjalanan', 'received' => 'Diterima', 'cancelled' => 'Dibatalkan'];
    $canReceive = $transfer->status === 'in_transit' && $transfer->to_outlet_id === $outlet->id;
    $canCancel = $transfer->status === 'in_transit' && $transfer->from_outlet_id === $outlet->id;
@endphp
<div class="page-heading"><div><div class="eyebrow">TRANSFER STOK</div><h1>{{ $transfer->transfer_number }}</h1>
    <p>{{ $names[$transfer->from_outlet_id] ?? '-' }} → {{ $names[$transfer->to_outlet_id] ?? '-' }} · {{ $labels[$transfer->status] ?? $transfer->status }} · Nilai {{ $rp($transfer->total_value) }}</p></div>
    <a class="btn btn-secondary" href="{{ route('stock-transfers.index') }}">Kembali</a></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
@if($transfer->notes)<p class="muted">Catatan: {{ $transfer->notes }}</p>@endif
@if($transfer->status === 'received' && (float) $transfer->shortage_value > 0)<div class="alert error">Ada selisih kurang diterima senilai {{ $rp($transfer->shortage_value) }}; dibukukan ke Selisih Persediaan.</div>@endif
<form method="post" action="{{ route('stock-transfers.receive', $transfer->id) }}" class="card section-card">@csrf
    <table class="table"><thead><tr><th>Produk</th><th>SKU</th><th>Dikirim</th><th>Biaya/unit</th><th>Diterima</th></tr></thead><tbody>
    @foreach($items as $item)
        <tr><td>{{ $item->name }}</td><td>{{ $item->sku }}</td><td>{{ $qty($item->quantity) }}</td><td>{{ $rp($item->unit_cost) }}</td>
            <td>@if($canReceive)<input type="number" name="received[{{ $item->id }}]" min="0" max="{{ $item->quantity }}" step="any" value="{{ $qty($item->quantity) }}" style="max-width:140px">@else{{ $item->received_quantity === null ? '-' : $qty($item->received_quantity) }}@endif</td></tr>
    @endforeach
    </tbody></table>
    @if($canReceive)<div class="form-actions"><button class="btn btn-primary">Terima barang</button></div>@endif
</form>
@if($canCancel)
<form method="post" action="{{ route('stock-transfers.cancel', $transfer->id) }}" style="margin-top:12px" onsubmit="return confirm('Batalkan transfer dan kembalikan stok ke outlet ini?')">@csrf<button class="btn btn-secondary">Batalkan transfer</button></form>
@endif
@endsection
