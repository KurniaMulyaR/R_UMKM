@extends('layouts.app')
@section('title', $ticket->subject)
@section('crumb','Bantuan')
@section('content')
<div class="page-heading"><div><div class="eyebrow">TIKET</div><h1>{{ $ticket->subject }}</h1><p>{{ $categories[$ticket->category] ?? $ticket->category }} · status {{ ['open' => 'Menunggu', 'in_progress' => 'Diproses', 'closed' => 'Selesai'][$ticket->status] ?? $ticket->status }}</p></div>
    <a class="btn btn-secondary" href="{{ route('support.index') }}">Kembali</a></div>
@foreach($messages as $m)
    <div class="ticket-msg {{ $m->is_platform_reply ? 'platform' : '' }}"><small>{{ $m->is_platform_reply ? 'Tim R Kasir' : ($m->author_name ?: 'Anda') }} · {{ \Illuminate\Support\Carbon::parse($m->created_at)->format('d M Y H:i') }}</small>{{ $m->body }}</div>
@endforeach
<div class="card form-card"><form method="post" action="{{ route('support.reply', $ticket->id) }}">@csrf
    <label>Balas<textarea name="body" rows="3" required maxlength="4000"></textarea></label>
    <div class="form-actions"><button class="btn btn-primary">Kirim</button></div></form></div>
@endsection
