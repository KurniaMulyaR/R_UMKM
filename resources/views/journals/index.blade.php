@extends('layouts.app')
@section('title','Jurnal')
@section('crumb','Jurnal')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); $isOwner = ($currentRole->key ?? '') === 'owner'; @endphp
<div class="page-heading"><div><div class="eyebrow">PEMBUKUAN</div><h1>Jurnal</h1><p>100 jurnal terakhir, otomatis dan manual.</p></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><form method="get"><input name="q" value="{{ $q }}" placeholder="Cari nomor/keterangan" aria-label="Cari"></form>
    @if($isOwner)<a class="btn btn-secondary" href="{{ route('journals.import') }}">Import CSV</a><a class="btn btn-primary" href="{{ route('journals.create') }}">Jurnal manual</a>@endif</div></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
<div class="card section-card"><table class="table"><thead><tr><th>Tanggal</th><th>Nomor</th><th>Keterangan</th><th>Sumber</th><th>Status</th><th>Nilai</th></tr></thead><tbody>
    @forelse($entries as $entry)
    <tr><td>{{ \Illuminate\Support\Carbon::parse($entry->entry_date)->format('d/m/Y') }}</td><td><a class="small-link" href="{{ route('journals.show', $entry->id) }}">{{ $entry->entry_number }}</a></td><td>{{ $entry->description }}</td><td>{{ $entry->source_type }}</td><td>{{ $entry->status }}</td><td>{{ $rp($entry->total) }}</td></tr>
    @empty<tr><td colspan="6" class="muted">Belum ada jurnal.</td></tr>@endforelse
</tbody></table></div>
@endsection
