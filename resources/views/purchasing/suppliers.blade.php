@extends('layouts.app')
@section('title','Supplier')
@section('crumb','Supplier')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PEMBELIAN</div><h1>Supplier</h1><p>Simpan data pemasok, termin bayar, dan hutang yang masih berjalan.</p></div><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">@if($deletedCount > 0)<a class="btn btn-secondary" href="{{ route('purchasing.suppliers', $showDeleted ? [] : ['show_deleted' => 1]) }}">{{ $showDeleted ? '← Kembali ke supplier' : 'Supplier dihapus ('.$deletedCount.')' }}</a>@endif @unless($showDeleted)<button class="btn btn-primary" type="button" onclick="document.querySelector('#supplier-form').classList.toggle('open')">＋ Tambah supplier</button>@endunless</div></div>
@unless($showDeleted)<div id="supplier-form" class="card form-card product-form">
    <h2>Supplier baru</h2>
    <form method="post" action="{{ route('purchasing.suppliers.store') }}">@csrf
        <div class="form-grid">
            <label>Nama supplier<input name="name" required maxlength="160" value="{{ old('name') }}"></label>
            <label>Nama kontak<input name="contact_name" maxlength="160" value="{{ old('contact_name') }}"></label>
            <label>Nomor HP<input name="phone" maxlength="32" value="{{ old('phone') }}"></label>
            <label>Email<input type="email" name="email" maxlength="190" value="{{ old('email') }}"></label>
            <label>Termin bayar (hari)<input type="number" name="payment_terms_days" min="0" max="365" value="{{ old('payment_terms_days') }}" placeholder="0 = bayar langsung"></label>
            <label>Alamat<input name="address" maxlength="1000" value="{{ old('address') }}"></label>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Simpan supplier</button></div>
    </form>
</div>@endunless
<div class="card section-card">
    <div class="toolbar"><span class="status">{{ $suppliers->total() }} supplier{{ $showDeleted ? ' dihapus' : '' }}</span></div>
    <div class="table-wrap"><table>
        <thead><tr><th>SUPPLIER</th><th>KONTAK</th><th>TERMIN</th><th class="text-right">HUTANG BERJALAN</th><th>STATUS</th><th class="text-right">AKSI</th></tr></thead>
        <tbody>
        @forelse($suppliers as $supplier)
            <tr>
                <td class="td-main">{{ $supplier->name }}</td>
                <td>{{ $supplier->contact_name ?: '—' }}<span class="td-sub">{{ $supplier->phone ?: ($supplier->email ?: '') }}</span></td>
                <td>{{ $supplier->payment_terms_days ? $supplier->payment_terms_days.' hari' : 'Langsung' }}</td>
                <td class="money text-right">Rp {{ number_format($supplier->payable,0,',','.') }}</td>
                <td>
                    @if($showDeleted)<span class="status inactive">Dihapus</span>
                    @else<div class="product-status-cell"><span class="status {{ $supplier->is_active ? '' : 'inactive' }}">{{ $supplier->is_active ? 'Aktif' : 'Nonaktif' }}</span><form method="post" action="{{ route('purchasing.suppliers.status',$supplier->id) }}">@csrf @method('PATCH')<button class="product-status-switch {{ $supplier->is_active ? 'is-on' : '' }}" type="submit" role="switch" aria-checked="{{ $supplier->is_active ? 'true' : 'false' }}" aria-label="{{ $supplier->is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ $supplier->name }}"><span></span></button></form></div>@endif
                </td>
                <td><div class="product-row-actions">
                    @if($showDeleted)
                        <form method="post" action="{{ route('purchasing.suppliers.restore',$supplier->id) }}" data-swal-confirm data-swal-title="Pulihkan supplier?" data-swal-text="Supplier akan kembali dalam status nonaktif." data-swal-icon="question" data-swal-confirm-text="Ya, pulihkan"><input type="hidden" name="_token" value="{{ csrf_token() }}"><button class="table-icon-action" title="Pulihkan supplier" aria-label="Pulihkan supplier"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 2.64-6.36L3 8"/><path d="M3 3v5h5M12 8v5l3 2"/></svg></button></form>
                    @else
                        <button class="table-icon-action" type="button" data-open-supplier="edit-supplier-{{ $supplier->id }}" title="Edit supplier" aria-label="Edit supplier {{ $supplier->name }}"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L8 20l-5 1 1-5Z"/></svg></button>
                        <form method="post" action="{{ route('purchasing.suppliers.destroy',$supplier->id) }}" data-swal-confirm data-swal-title="Hapus supplier?" data-swal-text="Supplier dikeluarkan dari daftar aktif. Histori pembelian dan hutang tetap disimpan." data-swal-icon="warning" data-swal-confirm-text="Ya, hapus supplier">@csrf @method('DELETE')<button class="table-icon-action action-danger" title="Hapus supplier" aria-label="Hapus supplier {{ $supplier->name }}"><svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6"/></svg></button></form>
                    @endif
                </div></td>
            </tr>
        @empty<tr><td colspan="6"><div class="empty-state"><div class="empty-icon">▤</div><b>{{ $showDeleted ? 'Belum ada supplier terhapus' : 'Belum ada supplier' }}</b><span>{{ $showDeleted ? 'Supplier yang dihapus akan muncul di sini.' : 'Tambahkan supplier pertama untuk mulai membuat pesanan beli.' }}</span></div></td></tr>@endforelse
        </tbody>
    </table></div>
    <div class="pagination">{{ $suppliers->links() }}</div>
</div>
@unless($showDeleted)
@foreach($suppliers as $supplier)
    <dialog id="edit-supplier-{{ $supplier->id }}" class="sale-action-dialog supplier-edit-dialog"><div class="sale-dialog-heading"><div><span class="eyebrow">DATA PEMASOK</span><h2>Edit supplier</h2><p>Perbarui kontak dan termin pembayaran supplier.</p></div><button type="button" class="sale-dialog-close" data-close-supplier aria-label="Tutup">×</button></div>
        <form method="post" action="{{ route('purchasing.suppliers.update',$supplier->id) }}">@csrf @method('PUT')<div class="form-grid">
            <label>Nama supplier<input name="name" required maxlength="160" value="{{ $supplier->name }}"></label>
            <label>Nama kontak<input name="contact_name" maxlength="160" value="{{ $supplier->contact_name }}"></label>
            <label>Nomor HP<input name="phone" maxlength="32" value="{{ $supplier->phone }}"></label>
            <label>Email<input type="email" name="email" maxlength="190" value="{{ $supplier->email }}"></label>
            <label>Termin bayar (hari)<input type="number" name="payment_terms_days" min="0" max="365" value="{{ $supplier->payment_terms_days }}" placeholder="0 = bayar langsung"></label>
            <label>Alamat<input name="address" maxlength="1000" value="{{ $supplier->address }}"></label>
            <label class="wide-field">Catatan<textarea name="notes" maxlength="2000">{{ $supplier->notes }}</textarea></label>
        </div><div class="sale-dialog-actions"><button type="button" class="btn btn-secondary" data-close-supplier>Batal</button><button class="btn btn-primary">Simpan perubahan</button></div></form>
    </dialog>
@endforeach
@endunless
@endsection
@push('scripts')<script>
document.querySelectorAll('[data-open-supplier]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.openSupplier)?.showModal()));
document.querySelectorAll('[data-close-supplier]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
</script>@endpush
