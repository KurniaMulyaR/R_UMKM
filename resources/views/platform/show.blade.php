@extends('layouts.platform')
@section('title', $tenant->name)
@section('content')
<div class="page-heading"><div><div class="eyebrow">USAHA</div><h1>{{ $tenant->name }}</h1>
    <p>{{ $tenant->business_type ?: 'Jenis usaha belum diisi' }} · terdaftar {{ \Illuminate\Support\Carbon::parse($tenant->created_at)->format('d M Y') }}</p></div>
    <span class="status {{ $tenant->status === 'active' ? '' : 'voided' }}">{{ $tenant->status === 'active' ? 'Aktif' : 'Ditangguhkan' }}</span></div>
<div class="platform-kpis">
    <div class="card"><small>Outlet</small><b>{{ $usage['outlets'] }}</b></div>
    <div class="card"><small>Anggota tim aktif</small><b>{{ $usage['members'] }}</b></div>
    <div class="card"><small>Produk</small><b>{{ $usage['products'] }}</b></div>
    <div class="card"><small>Transaksi 30 hari (jumlah)</small><b>{{ $usage['sales30d'] }}</b></div>
    <div class="card"><small>Transaksi terakhir</small><b style="font-size:16px">{{ $usage['lastSaleAt'] ? \Illuminate\Support\Carbon::parse($usage['lastSaleAt'])->format('d M Y H:i') : 'Belum ada' }}</b></div>
</div>
<div class="platform-two">
    <div class="card form-card"><h2>Kontak pemilik</h2>
        <p>{{ $tenant->owner_name ?: '-' }}<br>{{ $tenant->owner_email ?: '-' }}<br>{{ $tenant->owner_phone ?: ($tenant->phone ?: '-') }}</p>
        @if($tenant->status !== 'active' && $tenant->suspended_reason)<p><b>Alasan penangguhan:</b> {{ $tenant->suspended_reason }}</p>@endif
        <p class="optional">Isi transaksi dan data pelanggan toko tidak dapat dilihat dari panel ini. Setiap perubahan di bawah tercatat dan tampil di audit log toko.</p>
    </div>
    <div class="card form-card"><h2>Paket</h2>
        <form method="post" action="{{ route('platform.tenants.plan', $tenant->id) }}">@csrf
            <div class="form-grid">
                <label>Paket<select name="plan">@foreach($plans as $k => $l)<option value="{{ $k }}" @selected($tenant->plan === $k)>{{ $l }}</option>@endforeach</select></label>
                <label>Berlaku sampai <span class="optional">opsional</span><input type="date" name="plan_expires_at" value="{{ $tenant->plan_expires_at ? substr((string) $tenant->plan_expires_at, 0, 10) : '' }}"></label>
                <label>Alasan perubahan<input name="reason" required maxlength="500"></label>
            </div><div class="form-actions"><button class="btn btn-primary">Simpan paket</button></div>
        </form>
    </div>
</div>
<div class="card form-card" style="margin-bottom:24px"><h2>{{ $tenant->status === 'active' ? 'Tangguhkan usaha' : 'Aktifkan kembali' }}</h2>
    <form method="post" action="{{ $tenant->status === 'active' ? route('platform.tenants.suspend', $tenant->id) : route('platform.tenants.activate', $tenant->id) }}">@csrf
        <div class="form-grid"><label>Alasan (wajib, tercatat)<input name="reason" required maxlength="500"></label></div>
        <div class="form-actions"><button class="btn {{ $tenant->status === 'active' ? 'btn-danger' : 'btn-primary' }}">{{ $tenant->status === 'active' ? 'Tangguhkan' : 'Aktifkan' }}</button></div>
    </form></div>
<div class="platform-two">
    <div class="card section-card"><div class="table-wrap"><table><thead><tr><th>TIKET</th><th>STATUS</th></tr></thead><tbody>
        @forelse($tickets as $tk)<tr><td><a class="td-main" href="{{ route('platform.tickets.show', $tk->id) }}">{{ $tk->subject }}</a></td><td>{{ $tk->status }}</td></tr>@empty<tr><td colspan="2">Belum ada tiket.</td></tr>@endforelse
    </tbody></table></div></div>
    <div class="card section-card"><div class="table-wrap"><table><thead><tr><th>LOG PLATFORM</th><th>ALASAN</th></tr></thead><tbody>
        @forelse($logs as $l)<tr><td>{{ $l->action }}<span class="td-sub">{{ $l->actor_name }} · {{ \Illuminate\Support\Carbon::parse($l->occurred_at)->format('d M Y H:i') }}</span></td><td>{{ $l->reason }}</td></tr>@empty<tr><td colspan="2">Belum ada aksi.</td></tr>@endforelse
    </tbody></table></div></div>
</div>
@endsection
