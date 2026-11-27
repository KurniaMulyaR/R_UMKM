@extends('layouts.platform')
@section('title','Usaha terdaftar')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PANEL MASTER</div><h1>Usaha terdaftar</h1><p>Daftar usaha, paket, dan status langganan.</p></div></div>
<form class="card form-card" method="get" style="margin-bottom:16px"><div class="form-grid">
    <label>Cari<input name="q" value="{{ $search }}" placeholder="Nama, HP, atau email usaha"></label>
    <label>Status<select name="status"><option value="">Semua</option><option value="active" @selected($status === 'active')>Aktif</option><option value="suspended" @selected($status === 'suspended')>Ditangguhkan</option></select></label>
    <label>Paket<select name="plan"><option value="">Semua</option>@foreach($plans as $k => $l)<option value="{{ $k }}" @selected($plan === $k)>{{ $l }}</option>@endforeach</select></label>
</div><div class="form-actions"><button class="btn btn-primary">Terapkan</button></div></form>
<div class="card section-card"><div class="table-wrap"><table>
    <thead><tr><th>USAHA</th><th>PEMILIK</th><th>PAKET</th><th>STATUS</th><th>DAFTAR</th></tr></thead><tbody>
    @forelse($tenants as $t)
        <tr><td><a class="td-main" href="{{ route('platform.tenants.show', $t->id) }}">{{ $t->name }}</a><span class="td-sub">{{ $t->business_type ?: '-' }}</span></td><td>{{ $t->owner_name ?: '-' }}</td>
            <td>{{ $plans[$t->plan] ?? $t->plan }}</td><td><span class="status {{ $t->status === 'active' ? '' : 'voided' }}">{{ $t->status === 'active' ? 'Aktif' : 'Ditangguhkan' }}</span></td>
            <td>{{ \Illuminate\Support\Carbon::parse($t->created_at)->format('d M Y') }}</td></tr>
    @empty
        <tr><td colspan="5"><div class="empty-state"><b>Tidak ada usaha</b></div></td></tr>
    @endforelse
    </tbody></table></div></div>
<div style="margin-top:16px">{{ $tenants->links() }}</div>
@endsection
