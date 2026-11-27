@extends('layouts.platform')
@section('title', $ticket->subject)
@section('content')
<div class="page-heading"><div><div class="eyebrow">TIKET · {{ $ticket->tenant_name }}</div><h1>{{ $ticket->subject }}</h1><p>Prioritas {{ $ticket->priority }} · status {{ $ticket->status }}</p></div>
    <form method="post" action="{{ route('platform.tickets.status', $ticket->id) }}" style="display:flex;gap:8px">@csrf
        <select name="status">@foreach(['open' => 'Baru', 'in_progress' => 'Diproses', 'closed' => 'Selesai'] as $k => $l)<option value="{{ $k }}" @selected($ticket->status === $k)>{{ $l }}</option>@endforeach</select>
        <button class="btn btn-secondary">Ubah status</button></form></div>
@foreach($messages as $m)
    <div class="ticket-msg {{ $m->is_platform_reply ? 'platform' : '' }}"><small>{{ $m->is_platform_reply ? 'Tim R Kasir' : ($m->author_name ?: 'Pengguna') }} · {{ \Illuminate\Support\Carbon::parse($m->created_at)->format('d M Y H:i') }}</small>{{ $m->body }}</div>
@endforeach
<div class="card form-card"><form method="post" action="{{ route('platform.tickets.reply', $ticket->id) }}">@csrf
    <label>Balasan<textarea name="body" rows="4" required maxlength="4000"></textarea></label>
    <div class="form-actions"><button class="btn btn-primary">Kirim balasan</button></div></form></div>
@endsection
