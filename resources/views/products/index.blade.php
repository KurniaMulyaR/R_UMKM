@extends('layouts.app')
@section('title','Produk & stok')
@section('crumb','Produk & stok')
@section('content')
@php $canManageProducts = in_array($currentRole->key ?? '', ['owner', 'manager'], true); @endphp
<div class="page-heading">
    <div><div class="eyebrow">KATALOG USAHA</div><h1>{{ $archived ? 'Produk diarsipkan' : 'Produk & stok' }}</h1><p>{{ $archived ? 'Lihat produk yang disimpan di arsip dan pulihkan bila ingin digunakan lagi.' : 'Atur barang, harga jual, kategori, dan jumlah stok yang tersedia.' }}</p></div>
    <div class="product-page-actions">
        <a class="btn {{ $archived ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('products.index', $archived ? [] : ['archived' => 1]) }}">{{ $archived ? '← Produk aktif' : 'Arsip' }} <small>{{ $archivedCount }}</small></a>
        @unless($archived)
        <details class="category-manager" @if($errors->has('category_name')) open @endif>
            <summary class="btn btn-secondary"><span>＋</span> Kelola kategori <small>{{ $categories->count() }}</small></summary>
            <div class="category-manager-panel">
                <div class="category-manager-heading"><div><h2>Kategori produk</h2><p>Buat kategori sesuai kebutuhan tokomu.</p></div></div>
                @if($categories->isNotEmpty())
                    <div class="category-chip-list">@foreach($categories as $category)<span class="category-chip">{{ $category->name }}</span>@endforeach</div>
                @else
                    <div class="category-empty">Belum ada kategori. Buat kategori pertama di bawah.</div>
                @endif
                <form method="post" action="{{ route('products.categories.store') }}" class="category-create-form">
                    @csrf
                    <label for="category_name">Nama kategori<input id="category_name" name="category_name" value="{{ old('category_name') }}" required maxlength="100" placeholder="Contoh: Minuman"></label>
                    <button class="btn btn-primary" type="submit">Simpan kategori</button>
                </form>
            </div>
        </details>
        <button class="btn btn-primary" type="button" onclick="document.querySelector('#product-form').classList.toggle('open')">＋ Tambah produk</button>
        @endunless
    </div>
</div>

@unless($archived)
<div id="product-form" class="card form-card product-form">
    <h2>Produk baru</h2>
    <form method="post" action="{{ route('products.store') }}">
        @csrf
        <div class="form-grid">
            <label>Nama produk<input name="name" value="{{ old('name') }}" required maxlength="160" placeholder="Contoh: Kopi susu"></label>
            <label>SKU <span class="optional">boleh kosong, dibuat otomatis</span><input name="sku" value="{{ old('sku') }}" maxlength="80" placeholder="SKU produk"></label>
            <label>Kategori<select name="category_id"><option value="">Tanpa kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id')===$category->id)>{{ $category->name }}</option>@endforeach</select><span class="optional">Buat kategori baru lewat tombol Kelola kategori.</span></label>
            <label>Satuan<select name="unit_id" required>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->symbol }})</option>@endforeach</select></label>
            <label>Harga jual<input name="price" type="number" min="0" step="1" required placeholder="0"></label>
            <label>Modal per satuan<input name="cost_price" type="number" min="0" step="1" placeholder="0"></label>
            <label>Stok awal<input name="initial_stock" type="number" min="0" step="0.0001" value="0"></label>
            <label>Peringatan stok minimum<input name="min_stock" type="number" min="0" step="0.0001" value="0"></label>
            <label class="wide-field">Barcode <span class="optional">opsional</span><input name="barcode" maxlength="128" placeholder="Scan atau ketik barcode"></label>
            <label class="check-row"><input type="checkbox" name="track_stock" value="1" checked> Pantau stok produk ini</label>
        </div>
        <div class="form-actions"><button type="button" class="btn btn-secondary" onclick="document.querySelector('#product-form').classList.remove('open')">Batal</button><button class="btn btn-primary">Simpan produk</button></div>
    </form>
</div>
@endunless

<div class="card section-card">
    <div class="toolbar"><div class="search-input"><span>⌕</span><input id="productSearch" placeholder="Cari nama produk atau SKU"></div><span class="status">{{ $products->total() }} produk {{ $archived ? 'diarsipkan' : 'aktif' }}</span></div>
    <div class="table-wrap">
        <table id="productsTable">
            <thead><tr><th>PRODUK</th><th>KATEGORI</th><th>HARGA JUAL</th><th>MODAL</th><th>STOK</th><th>{{ $archived ? 'DIARSIPKAN' : 'STATUS' }}</th><th class="text-right">AKSI</th></tr></thead>
            <tbody>
                @forelse($products as $product)
                    <tr>
                        <td>@if($archived)<span class="td-main">{{ $product->name }}@if(($product->variant_name ?? 'Default') !== 'Default') — {{ $product->variant_name }}@endif</span>@else<a class="td-main" href="{{ route('products.show',$product->id) }}">{{ $product->name }}@if(($product->variant_name ?? 'Default') !== 'Default') — {{ $product->variant_name }}@endif</a>@endif<span class="td-sub">{{ $product->sku }}@if(!empty($product->barcode)) · {{ $product->barcode }}@endif</span></td>
                        <td>{{ $product->category_name ?: '—' }}</td>
                        <td class="money">Rp {{ number_format($product->price ?? 0,0,',','.') }}</td>
                        <td>Rp {{ number_format($product->cost_price ?? 0,0,',','.') }}</td>
                        <td>{{ $product->track_stock ? number_format((float)$product->quantity_on_hand,0,',','.') : 'Tidak dilacak' }}</td>
                        <td>
                            @if($archived)
                                <span class="status inactive">{{ \Illuminate\Support\Carbon::parse($product->deleted_at)->format('d M Y, H:i') }}</span>
                            @else
                            <div class="product-status-cell">
                                <span class="status {{ $product->is_active ? '' : 'inactive' }}">{{ $product->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                @if($canManageProducts)
                                    <form method="post" action="{{ route('products.status',$product->id) }}" data-swal-confirm data-swal-title="{{ $product->is_active ? 'Nonaktifkan produk?' : 'Aktifkan produk?' }}" data-swal-text="{{ $product->is_active ? 'Produk tidak akan tampil di kasir sampai diaktifkan kembali.' : 'Produk akan tersedia kembali di kasir.' }}" data-swal-icon="question" data-swal-confirm-text="{{ $product->is_active ? 'Ya, nonaktifkan' : 'Ya, aktifkan' }}">
                                        @csrf @method('PATCH')
                                        <button class="product-status-switch {{ $product->is_active ? 'is-on' : '' }}" type="submit" role="switch" aria-checked="{{ $product->is_active ? 'true' : 'false' }}" aria-label="{{ $product->is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ $product->name }}"><span></span></button>
                                    </form>
                                @endif
                            </div>
                            @endif
                        </td>
                        <td>
                            <div class="product-row-actions">
                                @unless($archived)<a class="table-icon-action" href="{{ route('products.show',$product->id) }}" title="Detail dan kartu stok" aria-label="Detail dan kartu stok"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></a>@endunless
                                @if($canManageProducts)
                                    @if($archived)
                                        <form method="post" action="{{ route('products.restore',$product->id) }}" data-swal-confirm data-swal-title="Pulihkan produk?" data-swal-text="Produk akan keluar dari arsip dan aktif kembali di kasir." data-swal-icon="question" data-swal-confirm-text="Ya, pulihkan">@csrf<button class="table-icon-action" title="Pulihkan produk" aria-label="Pulihkan produk"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 2.64-6.36L3 8"/><path d="M3 3v5h5M12 8v5l3 2"/></svg></button></form>
                                    @else
                                    <a class="table-icon-action" href="{{ route('products.edit',$product->id) }}" title="Edit produk" aria-label="Edit produk"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L8 20l-5 1 1-5Z"/></svg></a>
                                    <form method="post" action="{{ route('products.archive',$product->id) }}" data-swal-confirm data-swal-title="Arsipkan produk?" data-swal-text="Produk tidak akan muncul di katalog aktif. Riwayat stok dan transaksi tetap tersimpan." data-swal-icon="warning" data-swal-confirm-text="Ya, arsipkan">@csrf<button class="table-icon-action action-warn" title="Arsipkan produk" aria-label="Arsipkan produk"><svg viewBox="0 0 24 24"><path d="M3 4h18v4H3z"/><path d="M5 8v11h14V8M10 12h4"/></svg></button></form>
                                    @if(($currentRole->key ?? '') === 'owner')
                                        <form method="post" action="{{ route('products.destroy',$product->id) }}" data-swal-confirm data-swal-title="Hapus produk permanen?" data-swal-text="Produk yang sudah punya histori stok akan ditolak agar catatannya tetap aman. Aksi ini tidak bisa dibatalkan." data-swal-icon="warning" data-swal-confirm-text="Ya, hapus permanen">@csrf @method('DELETE')<button class="table-icon-action action-danger" title="Hapus permanen" aria-label="Hapus permanen"><svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6"/></svg></button></form>
                                    @endif
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><div class="empty-icon">□</div><b>{{ $archived ? 'Belum ada produk di arsip' : 'Belum ada produk' }}</b><span>{{ $archived ? 'Produk yang kamu arsipkan nanti akan muncul di sini.' : 'Tambahkan produk pertama agar kasir bisa dipakai.' }}</span></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination">{{ $products->links() }}</div>
</div>
@endsection
@push('scripts')
<script>
    document.getElementById('productSearch')?.addEventListener('input', function () {
        const query = this.value.toLowerCase();
        document.querySelectorAll('#productsTable tbody tr').forEach((row) => {
            row.style.display = row.innerText.toLowerCase().includes(query) ? '' : 'none';
        });
    });
</script>
@endpush
