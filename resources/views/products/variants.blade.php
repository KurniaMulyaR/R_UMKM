@extends('layouts.app')
@section('title','Varian & satuan')
@section('crumb')<a href="{{ route('products.index') }}">Produk & stok</a> <span>/</span> Varian & satuan@endsection
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ','); @endphp
<div class="page-heading"><div><div class="eyebrow">KATALOG USAHA</div><h1>{{ $product->name }}</h1><p>Varian (ukuran, warna, dll) punya stok dan harga sendiri. Satuan jual (misal dus = 12 {{ $baseUnit->symbol }}) memotong stok dalam satuan dasar {{ $baseUnit->symbol }}.</p></div>
    <a class="btn btn-secondary" href="{{ route('products.edit', $product->id) }}">← Kembali</a></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif

<div class="card section-card"><div class="section-title"><h2>Varian</h2></div>
    <table class="table"><thead><tr><th>Varian</th><th>SKU</th><th>Harga ({{ $baseUnit->symbol }})</th><th>Stok outlet ini</th><th></th></tr></thead><tbody>
    @foreach($variants as $variant)
        <tr><td>{{ $variant->name }}</td><td>{{ $variant->sku }}</td><td>{{ isset($prices[$variant->id.'|'.$product->base_unit_id]) ? $rp($prices[$variant->id.'|'.$product->base_unit_id]->price) : '—' }}</td>
            <td>{{ $qty($stocks[$variant->id] ?? 0) }} {{ $baseUnit->symbol }}</td>
            <td>@if($variants->count() > 1)<form method="post" action="{{ route('products.variants.archive', [$product->id, $variant->id]) }}" onsubmit="return confirm('Arsipkan varian ini?')">@csrf<button class="small-link" style="background:none;border:0;cursor:pointer">Arsipkan</button></form>@endif</td></tr>
    @endforeach
    </tbody></table>
    <form method="post" action="{{ route('products.variants.store', $product->id) }}" style="margin-top:16px">@csrf
        <h3>Tambah varian</h3>
        <div class="form-grid">
            <label>Nama varian<input name="name" required maxlength="120" placeholder="Contoh: Ukuran L" value="{{ old('name') }}"></label>
            <label>SKU <span class="optional">opsional</span><input name="sku" maxlength="80" value="{{ old('sku') }}"></label>
            <label>Harga jual per {{ $baseUnit->symbol }}<input type="number" name="price" min="0" step="any" required value="{{ old('price') }}"></label>
            <label>Harga pokok <span class="optional">opsional</span><input type="number" name="cost_price" min="0" step="any" value="{{ old('cost_price') }}"></label>
            <label>Stok awal (outlet ini) <span class="optional">opsional</span><input type="number" name="initial_stock" min="0" step="any" value="{{ old('initial_stock') }}"></label>
            <label>Barcode <span class="optional">opsional</span><input name="barcode" maxlength="128" value="{{ old('barcode') }}"></label>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Tambah varian</button></div>
    </form>
</div>

<div class="card section-card"><div class="section-title"><h2>Satuan jual</h2></div>
    <p class="muted">Satuan dasar: {{ $baseUnit->name }} ({{ $baseUnit->symbol }}). Harga satuan lain diisi per varian; kosong berarti varian itu tidak dijual dalam satuan tersebut.</p>
    @forelse($conversions as $conversion)
        <form method="post" action="{{ route('products.units.prices', [$product->id, $conversion->unit_id]) }}" style="margin:14px 0;padding:12px;border:1px solid var(--border,#ddd);border-radius:10px">@csrf @method('PUT')
            <b>1 {{ $conversion->unit_name }} ({{ $conversion->symbol }}) = {{ $qty($conversion->base_quantity) }} {{ $baseUnit->symbol }}</b>
            <div class="form-grid" style="margin-top:8px">
                @foreach($variants as $variant)
                    <label>{{ $variant->name }}<input type="number" name="prices[{{ $variant->id }}]" min="0" step="any" value="{{ isset($prices[$variant->id.'|'.$conversion->unit_id]) ? (float) $prices[$variant->id.'|'.$conversion->unit_id]->price : '' }}"></label>
                @endforeach
            </div>
            <div class="form-actions"><button class="btn btn-secondary">Simpan harga</button></div>
        </form>
        <form method="post" action="{{ route('products.units.destroy', [$product->id, $conversion->unit_id]) }}" onsubmit="return confirm('Hapus satuan jual ini?')" style="margin:-6px 0 8px">@csrf @method('DELETE')<button class="small-link" style="background:none;border:0;cursor:pointer">Hapus satuan {{ $conversion->symbol }}</button></form>
    @empty
        <p class="muted">Belum ada satuan jual tambahan.</p>
    @endforelse
    <form method="post" action="{{ route('products.units.store', $product->id) }}" style="margin-top:16px">@csrf
        <h3>Tambah satuan jual</h3>
        <div class="form-grid">
            <label>Satuan<select name="unit_id"><option value="">— pilih / buat baru di bawah —</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(old('unit_id') === $unit->id)>{{ $unit->name }} ({{ $unit->symbol }})</option>@endforeach</select></label>
            <label>Atau satuan baru: nama<input name="new_unit_name" maxlength="64" placeholder="Dus" value="{{ old('new_unit_name') }}"></label>
            <label>Simbol<input name="new_unit_symbol" maxlength="16" placeholder="dus" value="{{ old('new_unit_symbol') }}"></label>
            <label>Isi per satuan (dalam {{ $baseUnit->symbol }})<input type="number" name="base_quantity" min="0" step="any" required value="{{ old('base_quantity') }}"></label>
            @foreach($variants as $variant)<label>Harga {{ $variant->name }}<input type="number" name="prices[{{ $variant->id }}]" min="0" step="any" value="{{ old('prices.'.$variant->id) }}"></label>@endforeach
        </div>
        <div class="form-actions"><button class="btn btn-primary">Tambah satuan</button></div>
    </form>
</div>
@endsection
