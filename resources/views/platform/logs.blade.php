@extends('layouts.platform')
@section('title','Log platform')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PANEL MASTER</div><h1>Log aksi platform</h1><p>Semua tindakan pengelola platform terhadap usaha.</p></div></div>
<form class="card form-card" method="get" style="margin-bottom:16px"><div class="form-grid"><label>Aksi<select name="action"><option value="">Semua</option>
    @foreach(['platform.plan_changed' => 'Ubah paket', 'platform.suspended' => 'Tangguhkan', 'platform.activated' => 'Aktifkan'] as $k => $l)<option value="{{ $k }}" @selected($action === $k)>{{ $l }}</option>@endforeach</select></label></div>
    <div class="form-actions"><button class="btn btn-primary">Terapkan</button></div></form>
<div class="card section-card"><div class="table-wrap"><table>
    <thead><tr><th>WAKTU</th><th>PENGELOLA</th><th>USAHA</th><th>AKSI</th><th>ALASAN</th></tr></thead><tbody>
    @forelse($logs as $l)<tr><td>{{ \Illuminate\Support\Carbon::parse($l->occurred_at)->format('d M Y H:i') }}</td><td>{{ $l->actor_name ?: '-' }}</td>
        <td>@if($l->tenant_id)<a href="{{ route('platform.tenants.show', $l->tenant_id) }}">{{ $l->tenant_name }}</a>@else - @endif</td><td>{{ $l->action }}</td><td>{{ $l->reason }}</td></tr>
    @empty<tr><td colspan="5"><div class="empty-state"><b>Belum ada log</b></div></td></tr>@endforelse
    </tbody></table></div></div>
<div style="margin-top:16px">{{ $logs->links() }}</div>
@endsection
