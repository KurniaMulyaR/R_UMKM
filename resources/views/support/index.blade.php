@extends('layouts.app')
@section('title','Bantuan')
@section('crumb','Bantuan')
@section('content')
<div class="page-heading"><div><div class="eyebrow">BANTUAN</div><h1>Bantuan & tiket</h1><p>Kirim kendala atau pertanyaan ke tim R Kasir.</p></div>
    <button class="btn btn-primary" type="button" onclick="document.querySelector('#ticket-form').classList.toggle('open')">Tiket baru</button></div>
<div id="ticket-form" class="card form-card product-form">
    <h2>Tiket baru</h2>
    <form method="post" action="{{ route('support.store') }}">@csrf
        <div class="form-grid">
            <label>Judul<input name="subject" required maxlength="190" value="{{ old('subject') }}"></label>
            <label>Kategori<select name="category">@foreach($categories as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
            <label>Prioritas<select name="priority"><option value="normal">Normal</option><option value="low">Rendah</option><option value="high">Mendesak</option></select></label>
        </div>
        <label>Ceritakan kendalanya<textarea name="body" rows="4" required maxlength="4000">{{ old('body') }}</textarea></label>
        <div class="form-actions"><button class="btn btn-primary">Kirim</button></div>
    </form>
</div>
<div class="card section-card"><div class="table-wrap"><table>
    <thead><tr><th>TIKET</th><th>KATEGORI</th><th>STATUS</th><th>PESAN TERAKHIR</th></tr></thead><tbody>
    @forelse($tickets as $t)<tr><td><a class="td-main" href="{{ route('support.show', $t->id) }}">{{ $t->subject }}</a></td><td>{{ $categories[$t->category] ?? $t->category }}</td>
        <td><span class="status {{ $t->status === 'closed' ? 'voided' : '' }}">{{ ['open' => 'Menunggu', 'in_progress' => 'Diproses', 'closed' => 'Selesai'][$t->status] ?? $t->status }}</span></td>
        <td>{{ $t->last_message_at ? \Illuminate\Support\Carbon::parse($t->last_message_at)->format('d M Y H:i') : '-' }}</td></tr>
    @empty<tr><td colspan="4"><div class="empty-state"><div class="empty-icon">?</div><b>Belum ada tiket</b><span>Klik "Tiket baru" bila butuh bantuan.</span></div></td></tr>@endforelse
    </tbody></table></div></div>
@endsection
