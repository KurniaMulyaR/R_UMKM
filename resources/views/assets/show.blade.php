@extends('layouts.app')
@section('title',$asset->name)
@section('crumb','Aset usaha')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); @endphp
<div class="page-heading"><div><div class="eyebrow">{{ $asset->code }}</div><h1>{{ $asset->name }}</h1><p>{{ $categories[$asset->category] ?? $asset->category }}{{ $asset->location ? ' · '.$asset->location : '' }}{{ $asset->serial_number ? ' · SN '.$asset->serial_number : '' }}</p></div><span class="status {{ $asset->status === 'disposed' ? 'voided' : '' }}">{{ $asset->status === 'disposed' ? 'Dilepas' : 'Aktif' }}</span></div>
<div class="detail-grid">
    <div class="card section-card"><div class="section-title"><h2>Nilai &amp; umur</h2></div><div class="detail-list">
        <div class="detail-row"><span>Tanggal beli</span><b>{{ \Illuminate\Support\Carbon::parse($asset->purchase_date)->format('d M Y') }}</b></div>
        <div class="detail-row"><span>Umur</span><b>{{ $asset->age_label }} dari {{ $asset->useful_life_months }} bulan</b></div>
        <div class="detail-row"><span>Harga beli</span><b>{{ $rp($asset->purchase_cost) }}</b></div>
        <div class="detail-row"><span>Penyusutan terkumpul</span><b>{{ $rp($asset->accumulated) }}</b></div>
        <div class="detail-row"><span>Nilai buku</span><b>{{ $rp($asset->book_value) }}</b></div>
        <div class="detail-row"><span>Total biaya perawatan</span><b>{{ $rp($maintenanceCost) }}</b></div>
        @if($asset->next_maintenance_due)<div class="detail-row"><span>Perawatan berikutnya</span><b>{{ \Illuminate\Support\Carbon::parse($asset->next_maintenance_due)->format('d M Y') }}</b></div>@endif
        @if($asset->status === 'disposed')<div class="detail-row"><span>Dilepas</span><b>{{ \Illuminate\Support\Carbon::parse($asset->disposed_at)->format('d M Y') }} · {{ $asset->disposal_reason }}</b></div>@endif
    </div><p class="muted" style="margin-top:12px;font-size:12px">Nilai buku dihitung garis lurus. Jurnal penyusutan diposting dari halaman daftar aset.</p></div>
    @if($asset->status !== 'disposed')
    <div class="card section-card"><div class="section-title"><h2>Catat perawatan</h2></div>
        <form method="post" action="{{ route('assets.maintenance.store',$asset->id) }}" class="form-stack">@csrf
            <label>Tanggal<input type="date" name="performed_at" required max="{{ now()->toDateString() }}" value="{{ old('performed_at', now()->toDateString()) }}"></label>
            <label>Pekerjaan<input name="description" required maxlength="500" value="{{ old('description') }}"></label>
            <div class="form-grid"><label>Biaya<input type="number" name="cost" min="0" step="1" value="{{ old('cost', 0) }}"></label><label>Dikerjakan oleh<input name="performed_by" maxlength="160" value="{{ old('performed_by') }}"></label></div>
            <button class="btn btn-primary">Simpan perawatan</button>
        </form>
    </div>
    @endif
</div>
<div class="card section-card"><div class="section-title"><h2>Riwayat perawatan</h2></div>
    @forelse($records as $record)
        <div class="detail-row"><span><b>{{ $record->description }}</b><span class="td-sub">{{ \Illuminate\Support\Carbon::parse($record->performed_at)->format('d M Y') }}{{ $record->performed_by ? ' · '.$record->performed_by : '' }}</span></span><b>{{ $rp($record->cost) }}</b></div>
    @empty
        <span class="muted">Belum ada riwayat perawatan.</span>
    @endforelse
</div>
@if(! $asset->acquisition_journal_entry_id)
<div class="card section-card"><div class="section-title"><h2>Jurnal perolehan</h2></div>
    <p class="muted" style="margin-bottom:12px">Aset ini belum masuk jurnal, sehingga belum tampil di neraca.</p>
    <form method="post" action="{{ route('assets.acquisition.store',$asset->id) }}" class="form-grid" style="align-items:end">@csrf
        <label>Dibayar dari<select name="funding"><option value="bank_transfer">Rekening bank</option><option value="cash">Kas tunai</option><option value="owner_equity">Modal pemilik (aset disetor)</option></select></label>
        <button class="btn btn-secondary">Posting jurnal perolehan</button>
    </form>
</div>
@endif
<div class="card section-card"><div class="section-title"><h2>Jurnal penyusutan</h2></div>
    @forelse($depreciations as $row)<div class="detail-row"><span>{{ $row->period }}</span><b>{{ $rp($row->amount) }}</b></div>@empty<span class="muted">Belum ada penyusutan yang diposting.</span>@endforelse
</div>
@if($asset->status !== 'disposed')
<details class="card section-card"><summary class="small-link">Lepas aset (dijual, rusak, atau hilang)</summary>
    <form method="post" action="{{ route('assets.dispose',$asset->id) }}" class="form-stack" style="margin-top:14px">@csrf
        <label>Alasan<input name="reason" required minlength="3" maxlength="500"></label>
        <button class="btn btn-danger" data-confirm="Tandai aset ini sudah dilepas?">Lepas aset</button>
    </form>
</details>
@endif
@endsection
