@extends('layouts.app')
@section('title','Aset usaha')
@section('crumb','Aset usaha')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); $stateLabel = ['overdue' => 'Terlambat', 'soon' => 'Segera', 'ok' => 'Aman']; @endphp
<div class="page-heading"><div><div class="eyebrow">DATA USAHA</div><h1>Aset usaha</h1><p>Catat peralatan, umur pakai, nilai buku, dan histori maintenance.</p></div><div class="page-actions">@if($deletedCount > 0)<a class="btn btn-secondary" href="{{ route('assets.index', $showDeleted ? [] : ['show_deleted' => 1]) }}">{{ $showDeleted ? '← Kembali ke aset' : 'Aset dihapus ('.$deletedCount.')' }}</a>@endif @unless($showDeleted)<button class="btn btn-primary" type="button" onclick="document.querySelector('#asset-form').classList.toggle('open')">＋ Tambah aset</button>@endunless</div></div>
@unless($showDeleted)<div class="feature-summary" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">▤</span><div><small>Aset aktif</small><b>{{ $summary['count'] }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon planned-icon">Rp</span><div><small>Harga perolehan</small><b>{{ $rp($summary['cost']) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">Rp</span><div><small>Nilai buku</small><b>{{ $rp($summary['bookValue']) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon partial-icon">!</span><div><small>Perlu perawatan</small><b>{{ $summary['dueSoon'] }}</b></div></div>
</div>
<form method="post" action="{{ route('assets.depreciation.store') }}" class="card section-card" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">@csrf
    <label style="max-width:200px">Posting penyusutan sampai bulan<input type="month" name="period" value="{{ now()->format('Y-m') }}" max="{{ now()->format('Y-m') }}" required></label>
    <button class="btn btn-secondary">Posting penyusutan ke jurnal</button>
    <span class="muted" style="font-size:12px;flex:1;min-width:220px">Bulan yang terlewat ikut disusulkan dan tidak akan diposting dua kali.</span>
</form>
@endunless
@unless($showDeleted)<div id="asset-form" class="card form-card product-form">
    <h2>Aset baru</h2>
    <form method="post" action="{{ route('assets.store') }}">@csrf
        <div class="form-grid">
            <label>Nama aset<input name="name" required maxlength="160" value="{{ old('name') }}"></label>
            <label>Kategori<select name="category">@foreach($categories as $key => $label)<option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Tanggal beli<input type="date" name="purchase_date" required max="{{ now()->toDateString() }}" value="{{ old('purchase_date') }}"></label>
            <label>Harga beli<input type="number" name="purchase_cost" required min="0" step="1" value="{{ old('purchase_cost') }}"></label>
            <label>Umur pakai (bulan)<input type="number" name="useful_life_months" required min="1" max="600" value="{{ old('useful_life_months', 60) }}"></label>
            <label>Nilai sisa <span class="optional">opsional</span><input type="number" name="salvage_value" min="0" step="1" value="{{ old('salvage_value', 0) }}"></label>
            <label>Servis tiap (hari) <span class="optional">opsional</span><input type="number" name="maintenance_interval_days" min="1" max="3650" value="{{ old('maintenance_interval_days') }}"></label>
            <label>Pembelian dibayar dari<select name="funding"><option value="bank_transfer" @selected(old('funding') === 'bank_transfer')>Rekening bank</option><option value="cash" @selected(old('funding') === 'cash')>Kas tunai</option><option value="owner_equity" @selected(old('funding') === 'owner_equity')>Modal pemilik (aset disetor)</option><option value="none" @selected(old('funding') === 'none')>Jangan buat jurnal</option></select></label>
            <label>Lokasi <span class="optional">opsional</span><input name="location" maxlength="160" value="{{ old('location') }}"></label>
            <label>Nomor seri <span class="optional">opsional</span><input name="serial_number" maxlength="128" value="{{ old('serial_number') }}"></label>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Simpan aset</button></div>
    </form>
</div>@endunless
<div class="card section-card">
    <div class="table-wrap"><table>
        <thead><tr><th>ASET</th><th>STATUS</th><th>UMUR</th><th class="text-right">HARGA BELI</th><th class="text-right">NILAI BUKU</th><th>MAINTENANCE</th><th class="text-right">AKSI</th></tr></thead>
        <tbody>
        @forelse($assets as $asset)
            <tr>
                <td><a class="td-main" href="{{ route('assets.show',$asset->id) }}">{{ $asset->name }}</a><span class="td-sub">{{ $asset->code }} · {{ $categories[$asset->category] ?? $asset->category }}{{ $asset->location ? ' · '.$asset->location : '' }}</span></td>
                <td><span class="status {{ in_array($asset->status, ['inactive','deleted','disposed'], true) ? 'voided' : '' }}">{{ ['active' => 'Aktif', 'inactive' => 'Nonaktif', 'disposed' => 'Dilepas', 'deleted' => 'Dihapus'][$asset->status] ?? $asset->status }}</span></td>
                <td>{{ $asset->age_label }}<span class="td-sub">{{ $asset->life_used_percent }}% masa pakai</span></td>
                <td class="money text-right">{{ $rp($asset->purchase_cost) }}</td>
                <td class="money text-right">{{ $rp($asset->book_value) }}</td>
                <td><a class="small-link" href="{{ route('assets.show',$asset->id) }}#maintenance-history">{{ $asset->maintenance_count }} riwayat</a>@if($asset->status === 'disposed')<span class="td-sub">Dilepas</span>@elseif($asset->next_maintenance_due)<span class="status {{ $asset->maintenance_state === 'ok' ? '' : 'pending' }}">{{ $stateLabel[$asset->maintenance_state] }}</span><span class="td-sub">Berikutnya {{ \Illuminate\Support\Carbon::parse($asset->next_maintenance_due)->format('d M Y') }}</span>@else<span class="td-sub">Tanpa jadwal</span>@endif</td>
                <td><div class="asset-row-actions">
                    @if(! $showDeleted && ! in_array($asset->status, ['disposed','deleted'], true))
                        <button class="sale-icon-action" type="button" data-open-asset="edit-asset-{{ $asset->id }}" title="Edit aset" aria-label="Edit aset {{ $asset->name }}"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L8 20l-5 1 1-5Z"/></svg></button>
                        <form method="post" action="{{ route('assets.status',$asset->id) }}" data-swal-confirm data-swal-title="{{ $asset->status === 'active' ? 'Nonaktifkan aset?' : 'Aktifkan aset?' }}" data-swal-text="{{ $asset->status === 'active' ? 'Aset ditandai nonaktif dan tidak masuk ringkasan aset aktif.' : 'Aset aktif kembali.' }}" data-swal-icon="question" data-swal-confirm-text="{{ $asset->status === 'active' ? 'Ya, nonaktifkan' : 'Ya, aktifkan' }}">@csrf @method('PATCH')<button class="sale-icon-action" title="{{ $asset->status === 'active' ? 'Nonaktifkan' : 'Aktifkan' }}" aria-label="{{ $asset->status === 'active' ? 'Nonaktifkan' : 'Aktifkan' }}"><svg viewBox="0 0 24 24"><path d="M12 3v9"/><path d="M6.2 6.2a8 8 0 1 0 11.6 0"/></svg></button></form>
                        <form method="post" action="{{ route('assets.destroy',$asset->id) }}" data-swal-confirm data-swal-title="Hapus aset dari daftar?" data-swal-text="Data maintenance tetap disimpan. Aset dengan jurnal perolehan atau penyusutan tidak bisa dihapus." data-swal-icon="warning" data-swal-confirm-text="Ya, hapus aset">@csrf @method('DELETE')<button class="sale-icon-action sale-icon-danger" title="Hapus aset" aria-label="Hapus aset"><svg viewBox="0 0 24 24"><path d="M4 7h16M10 11v6m4-6v6M6 7l1 14h12l1-14M9 7V4h6v3"/></svg></button></form>
                    @elseif($showDeleted)
                        <form method="post" action="{{ route('assets.restore',$asset->id) }}" data-swal-confirm data-swal-title="Pulihkan aset?" data-swal-text="Aset dipulihkan dalam status nonaktif." data-swal-icon="question" data-swal-confirm-text="Ya, pulihkan">@csrf<button class="sale-icon-action" title="Pulihkan aset" aria-label="Pulihkan aset"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 2.64-6.36L3 8"/><path d="M3 3v5h5M12 8v5l3 2"/></svg></button></form>
                    @else<span class="muted">—</span>@endif
                </div></td>
            </tr>
        @empty
            <tr><td colspan="7"><div class="empty-state"><div class="empty-icon">▤</div><b>{{ $showDeleted ? 'Tidak ada aset yang dihapus' : 'Belum ada aset' }}</b><span>Catat peralatan usaha agar umur dan perawatannya terpantau.</span></div></td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@unless($showDeleted)
@foreach($assets as $asset)
    @if(! in_array($asset->status, ['disposed','deleted'], true))
        @php $financialLocked = $asset->has_financial_history; @endphp
        <dialog id="edit-asset-{{ $asset->id }}" class="sale-action-dialog asset-edit-dialog"><div class="sale-dialog-heading"><div><span class="eyebrow">{{ $asset->code }}</span><h2>Edit aset</h2><p>Perbarui informasi aset dan jadwal maintenance.</p></div><button type="button" class="sale-dialog-close" data-close-asset aria-label="Tutup">×</button></div>
            <form method="post" action="{{ route('assets.update',$asset->id) }}">@csrf @method('PUT')<div class="form-grid">
                <label>Nama aset<input name="name" required maxlength="160" value="{{ $asset->name }}"></label>
                <label>Kategori<select name="category">@foreach($categories as $key => $label)<option value="{{ $key }}" @selected($asset->category === $key)>{{ $label }}</option>@endforeach</select></label>
                <label>Nomor seri<input name="serial_number" maxlength="128" value="{{ $asset->serial_number }}"></label>
                <label>Lokasi<input name="location" maxlength="160" value="{{ $asset->location }}"></label>
                <label>Tanggal beli<input type="date" name="purchase_date" required max="{{ now()->toDateString() }}" value="{{ $asset->purchase_date }}" @readonly($financialLocked)></label>
                <label>Harga beli<input type="number" name="purchase_cost" required min="0" step="1" value="{{ $asset->purchase_cost }}" @readonly($financialLocked)></label>
                <label>Umur pakai (bulan)<input type="number" name="useful_life_months" required min="1" max="600" value="{{ $asset->useful_life_months }}" @readonly($financialLocked)></label>
                <label>Nilai sisa<input type="number" name="salvage_value" min="0" step="1" value="{{ $asset->salvage_value }}" @readonly($financialLocked)></label>
                <label>Servis tiap (hari)<input type="number" name="maintenance_interval_days" min="1" max="3650" value="{{ $asset->maintenance_interval_days }}"></label>
                <label class="wide-field">Catatan<textarea name="notes" maxlength="2000">{{ $asset->notes }}</textarea></label>
            </div>@if($financialLocked)<p class="muted" style="margin:12px 0 0;font-size:11px">Tanggal beli dan nilai perolehan dikunci karena sudah memiliki catatan akuntansi.</p>@endif<div class="sale-dialog-actions"><button type="button" class="btn btn-secondary" data-close-asset>Batal</button><button class="btn btn-primary">Simpan perubahan</button></div></form>
        </dialog>
    @endif
@endforeach
@endunless
@endsection
@push('scripts')<script>
document.querySelectorAll('[data-open-asset]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.openAsset)?.showModal()));
document.querySelectorAll('[data-close-asset]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
</script>@endpush
