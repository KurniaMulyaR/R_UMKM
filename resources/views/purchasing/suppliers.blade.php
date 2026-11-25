@extends('layouts.app')
@section('title','Supplier')
@section('crumb','Supplier')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PEMBELIAN</div><h1>Supplier</h1><p>Simpan data pemasok, termin bayar, dan hutang yang masih berjalan.</p></div><button class="btn btn-primary" type="button" onclick="document.querySelector('#supplier-form').classList.toggle('open')">＋ Tambah supplier</button></div>
<div id="supplier-form" class="card form-card product-form">
    <h2>Supplier baru</h2>
    <form method="post" action="{{ route('purchasing.suppliers.store') }}">@csrf
        <div class="form-grid">
            <label>Nama supplier<input name="name" required maxlength="160" value="{{ old('name') }}"></label>
            <label>Nama kontak<input name="contact_name" maxlength="160" value="{{ old('contact_name') }}"></label>
            <label>Nomor HP<input name="phone" maxlength="32" value="{{ old('phone') }}"></label>
            <label>Email<input type="email" name="email" maxlength="190" value="{{ old('email') }}"></label>
            <label>Termin bayar (hari)<input type="number" name="payment_terms_days" min="0" max="365" value="{{ old('payment_terms_days') }}" placeholder="0 = bayar langsung"></label>
            <label>Alamat<input name="address" maxlength="1000" value="{{ old('address') }}"></label>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Simpan supplier</button></div>
    </form>
</div>
<div class="card section-card">
    <div class="toolbar"><span class="status">{{ $suppliers->total() }} supplier</span></div>
    <div class="table-wrap"><table>
        <thead><tr><th>SUPPLIER</th><th>KONTAK</th><th>TERMIN</th><th class="text-right">HUTANG BERJALAN</th></tr></thead>
        <tbody>
        @forelse($suppliers as $supplier)
            <tr><td class="td-main">{{ $supplier->name }}</td><td>{{ $supplier->contact_name ?: '—' }}<span class="td-sub">{{ $supplier->phone ?: '' }}</span></td><td>{{ $supplier->payment_terms_days ? $supplier->payment_terms_days.' hari' : 'Langsung' }}</td><td class="money text-right">Rp {{ number_format($supplier->payable,0,',','.') }}</td></tr>
        @empty
            <tr><td colspan="4"><div class="empty-state"><div class="empty-icon">▤</div><b>Belum ada supplier</b><span>Tambahkan supplier pertama untuk mulai membuat pesanan beli.</span></div></td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="pagination">{{ $suppliers->links() }}</div>
</div>
@endsection
