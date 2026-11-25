@extends('layouts.app')
@section('title','Import jurnal')
@section('crumb','Import jurnal')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PEMBUKUAN</div><h1>Import jurnal</h1><p>Unggah CSV. Jika satu jurnal bermasalah, tidak ada yang diimpor.</p></div></div>
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<div class="detail-grid">
    <form method="post" action="{{ route('journals.import.store') }}" enctype="multipart/form-data" class="card section-card form-stack">@csrf
        <label>File CSV<input type="file" name="file" accept=".csv,.txt" required></label>
        <button class="btn btn-primary">Impor jurnal</button>
    </form>
    <div class="card section-card"><div class="section-title"><h2>Format</h2></div>
        <p class="muted">Kolom: <code>tanggal</code> (YYYY-MM-DD), <code>nomor_jurnal</code> (pengelompok baris), <code>keterangan</code>, <code>kode_akun</code>, <code>debit</code>, <code>kredit</code>, <code>memo</code>. Pemisah koma atau titik koma.</p>
        <p class="muted">Setiap nomor_jurnal harus seimbang dan bertanggal sama. Nomor yang sama tidak bisa diimpor dua kali (disimpan sebagai IMP-nomor). Maksimal 2.000 baris.</p>
        <a class="btn btn-secondary" href="{{ route('journals.template') }}">Unduh template</a>
    </div>
</div>
@endsection
