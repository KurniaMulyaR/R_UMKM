@extends('layouts.app')
@section('title','Audit log')
@section('crumb','Audit log')
@section('content')
@php
    $links = ['sale' => 'sales.show', 'stock_transfer' => 'stock-transfers.show', 'stock_count' => 'stock-counts.show', 'journal_entry' => 'journals.show'];
    $pretty = fn ($json) => $json ? json_encode(json_decode($json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
@endphp
<div class="page-heading"><div><div class="eyebrow">KEAMANAN</div><h1>Audit log</h1><p>Jejak siapa mengubah apa dan kapan. Catatan hanya bisa dibaca, tidak bisa diubah dari aplikasi.</p></div></div>
<form method="get" class="card section-card toolbar" style="gap:10px;flex-wrap:wrap">
    <select name="action"><option value="">Semua aksi</option>@foreach($actions as $name)<option value="{{ $name }}" @selected($action === $name)>{{ $name }}</option>@endforeach</select>
    <select name="actor"><option value="">Semua pengguna</option>@foreach($actors as $person)<option value="{{ $person->id }}" @selected($actor === $person->id)>{{ $person->name }}</option>@endforeach</select>
    <input type="date" name="from" value="{{ $from }}" aria-label="Dari" style="max-width:160px"><input type="date" name="to" value="{{ $to }}" aria-label="Sampai" style="max-width:160px">
    <button class="btn btn-secondary">Terapkan</button>
</form>
<div class="card section-card"><table class="table"><thead><tr><th>Waktu</th><th>Pengguna</th><th>Outlet</th><th>Aksi</th><th>Objek</th><th>Detail</th></tr></thead><tbody>
@forelse($logs as $log)
    <tr><td>{{ \Illuminate\Support\Carbon::parse($log->occurred_at)->timezone(request()->attributes->get('tenant')->timezone)->format('d/m/Y H:i') }}</td><td>{{ $log->actor_name ?? 'Sistem' }}</td><td>{{ $log->outlet_name ?? '—' }}</td><td>{{ $log->action }}</td>
        <td>@if($log->subject_id && isset($links[$log->subject_type]) && \Illuminate\Support\Facades\Route::has($links[$log->subject_type]))<a class="small-link" href="{{ route($links[$log->subject_type], $log->subject_id) }}">{{ $log->subject_type }}</a>@else{{ $log->subject_type }}@endif</td>
        <td>@if($log->reason)<div>{{ $log->reason }}</div>@endif @if($log->before || $log->after)<details><summary class="small-link">Lihat</summary><pre style="white-space:pre-wrap;font-size:12px">@if($log->before)Sebelum: {{ $pretty($log->before) }}
@endif @if($log->after)Sesudah: {{ $pretty($log->after) }}@endif</pre></details>@endif</td></tr>
@empty
    <tr><td colspan="6" class="muted">Belum ada catatan.</td></tr>
@endforelse
</tbody></table><div class="pagination">{{ $logs->links() }}</div></div>
@endsection
