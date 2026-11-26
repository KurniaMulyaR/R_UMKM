@extends('layouts.app')
@section('title', $product->name)
@section('crumb')<a href="{{ route('products.index') }}">Produk &amp; stok</a> <span>/</span> {{ $product->name }}@endsection
@section('content')
<div class="page-heading">
    <div>
        <div class="eyebrow">DETAIL PRODUK</div>
        <h1>{{ $product->name }}</h1>
        <p>{{ $product->sku }} · {{ $product->category_name ?: 'Tanpa kategori' }} · {{ $product->unit_name }} ({{ $product->unit_symbol }})</p>
    </div>
    <div class="product-page-actions">
        @if(in_array($currentRole->key ?? '', ['owner', 'manager'], true))
            <a class="btn btn-primary" href="{{ route('products.edit', $product->id) }}">Edit produk</a>
        @endif
        <a class="btn btn-secondary" href="{{ route('products.index') }}">← Kembali</a>
    </div>
</div>

<div class="card section-card">
    <h2>Stok per outlet</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>OUTLET</th><th>KODE</th><th>STOK</th><th>NILAI PERSEDIAAN</th></tr></thead>
            <tbody>
                @forelse($balances as $balance)
                    <tr><td>{{ $balance->outlet_name }}</td><td>{{ $balance->outlet_code }}</td><td>{{ number_format((float) $balance->quantity_on_hand, 2, ',', '.') }}</td><td>Rp {{ number_format((float) $balance->inventory_value, 0, ',', '.') }}</td></tr>
                @empty
                    <tr><td colspan="4">Belum ada saldo stok untuk produk ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card section-card">
    <h2>Varian</h2>
    @if(in_array($currentRole->key ?? '', ['owner', 'manager'], true))<p><a class="small-link" href="{{ route('products.variants', $product->id) }}">Kelola varian &amp; satuan jual</a></p>@endif
    <div class="table-wrap">
        <table>
            <thead><tr><th>NAMA</th><th>SKU</th><th>MODAL</th><th>STATUS</th></tr></thead>
            <tbody>
                @foreach($variants as $variant)
                    <tr><td>{{ $variant->name }}</td><td>{{ $variant->sku }}</td><td>Rp {{ number_format((float) $variant->cost_price, 0, ',', '.') }}</td><td>{{ $variant->is_active ? 'Aktif' : 'Nonaktif' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card section-card">
    <h2>Kartu stok</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>WAKTU</th><th>OUTLET</th><th>VARIAN</th><th>AKTIVITAS</th><th>REFERENSI</th><th>PERUBAHAN</th><th>SALDO</th><th>PETUGAS</th></tr></thead>
            <tbody>
                @forelse($movements as $movement)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($movement->occurred_at)->format('d M Y H:i') }}</td>
                        <td>{{ $movement->outlet_name }}</td>
                        <td>{{ $movement->variant_name }}</td>
                        <td>{{ $movement->activity_label }}</td>
                        <td>{{ $movement->reference_number ?: '—' }}</td>
                        <td>{{ number_format((float) $movement->quantity_delta, 2, ',', '.') }}</td>
                        <td>{{ number_format((float) $movement->balance_after, 2, ',', '.') }}</td>
                        <td>{{ $movement->actor_name ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8">Belum ada histori pergerakan stok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
