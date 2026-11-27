@extends('layouts.platform')
@section('title','Tiket dukungan')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PANEL MASTER</div><h1>Tiket dukungan</h1><p>Permintaan bantuan dari usaha terdaftar.</p></div>
    <form method="get"><select name="status" onchange="this.form.submit()">@foreach(['active' => 'Perlu ditangani', 'open' => 'Baru', 'in_progress' => 'Diproses', 'closed' => 'Selesai', 'all' => 'Semua'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select></form></div>
<div class="card section-card"><div class="table-wrap"><table>
    <thead><tr><th>TIKET</th><th>USAHA</th><th>PRIORITAS</th><th>STATUS</th><th>PESAN TERAKHIR</th></tr></thead><tbody>
    @forelse($tickets as $t)<tr><td><a class="td-main" href="{{ route('platform.tickets.show', $t->id) }}">{{ $t->subject }}</a></td><td>{{ $t->tenant_name }}</td><td>{{ $t->priority }}</td><td>{{ $t->status }}</td>
        <td>{{ $t->last_message_at ? \Illuminate\Support\Carbon::parse($t->last_message_at)->format('d M Y H:i') : '-' }}</td></tr>
    @empty<tr><td colspan="5"><div class="empty-state"><b>Tidak ada tiket</b></div></td></tr>@endforelse
    </tbody></table></div></div>
<div style="margin-top:16px">{{ $tickets->links() }}</div>
@endsection
