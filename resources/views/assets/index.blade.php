@extends('layouts.app')
@section('title','Aset usaha')
@section('crumb','Aset usaha')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); $stateLabel = ['overdue' => 'Terlambat', 'soon' => 'Segera', 'ok' => 'Aman']; @endphp
<div class="page-heading"><div><div class="eyebrow">DATA USAHA</div><h1>Aset usaha</h1><p>Catat peralatan, umur pakai, nilai buku, dan jadwal perawatannya.</p></div><button class="btn btn-primary" type="button" onclick="document.querySelector('#asset-form').classList.toggle('open')">＋ Tambah aset</button></div>
<div class="feature-summary" style="grid-template-columns:repeat(4,minmax(0,1fr))">
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
<div id="asset-form" class="card form-card product-form">
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
            <label>Lokasi <span class="optional">opsional</span><input name="location" maxlength="160" value="{{ old('location') }}"></label>
            <label>Nomor seri <span class="optional">opsional</span><input name="serial_number" maxlength="128" value="{{ old('serial_number') }}"></label>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Simpan aset</button></div>
    </form>
</div>
<div class="card section-card">
    <div class="table-wrap"><table>
        <thead><tr><th>ASET</th><th>UMUR</th><th class="text-right">HARGA BELI</th><th class="text-right">NILAI BUKU</th><th>PERAWATAN</th></tr></thead>
        <tbody>
        @forelse($assets as $asset)
            <tr>
                <td><a class="td-main" href="{{ route('assets.show',$asset->id) }}">{{ $asset->name }}</a><span class="td-sub">{{ $asset->code }} · {{ $categories[$asset->category] ?? $asset->category }}{{ $asset->location ? ' · '.$asset->location : '' }}</span></td>
                <td>{{ $asset->age_label }}<span class="td-sub">{{ $asset->life_used_percent }}% masa pakai</span></td>
                <td class="money text-right">{{ $rp($asset->purchase_cost) }}</td>
                <td class="money text-right">{{ $rp($asset->book_value) }}</td>
                <td>@if($asset->status === 'disposed')<span class="status voided">Dilepas</span>@elseif($asset->next_maintenance_due)<span class="status {{ $asset->maintenance_state === 'ok' ? '' : 'pending' }}">{{ $stateLabel[$asset->maintenance_state] }}</span><span class="td-sub">{{ \Illuminate\Support\Carbon::parse($asset->next_maintenance_due)->format('d M Y') }}</span>@else<span class="muted">Tanpa jadwal</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="5"><div class="empty-state"><div class="empty-icon">▤</div><b>Belum ada aset</b><span>Catat peralatan usaha agar umur dan perawatannya terpantau.</span></div></td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
