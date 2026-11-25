@extends('layouts.app')
@section('title','Pesanan beli baru')
@section('crumb','Pesanan beli')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PEMBELIAN</div><h1>Pesanan beli baru</h1><p>Pilih supplier dan barang yang akan dipesan.</p></div></div>
@if($suppliers->isEmpty())
    <div class="card empty-state"><div class="empty-icon">▤</div><b>Belum ada supplier</b><span>Tambahkan supplier terlebih dahulu.</span><a class="btn btn-primary" href="{{ route('purchasing.suppliers') }}">Kelola supplier</a></div>
@else
<form method="post" action="{{ route('purchasing.store') }}" class="card form-card">@csrf
    <div class="form-grid">
        <label>Supplier<select name="supplier_id" required>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id') === $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></label>
        <label>Dibutuhkan pada <span class="optional">opsional</span><input type="date" name="needed_at" value="{{ old('needed_at') }}"></label>
    </div>
    <div class="divider"></div>
    <div class="section-title"><h2>Barang</h2><button type="button" class="small-link" id="add-line">＋ Tambah baris</button></div>
    <div id="po-lines" class="form-stack"></div>
    <div class="total-row grand" style="margin-top:16px"><span>Total pesanan</span><b id="po-total">Rp 0</b></div>
    <div class="form-actions"><a class="btn btn-secondary" href="{{ route('purchasing.index') }}">Batal</a><button class="btn btn-primary">Simpan sebagai draf</button></div>
</form>
@endif
@endsection
@push('scripts')
<script>
(function () {
    const variants = @json($variants->map(fn ($v) => ['id' => $v->id, 'label' => $v->name.($v->sku ? ' · '.$v->sku : ''), 'cost' => (float) $v->cost_price])->values());
    const host = document.getElementById('po-lines');
    if (!host) return;
    let index = 0;
    const fmt = (n) => 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(n));
    function recalc() {
        let total = 0;
        host.querySelectorAll('.payment-row').forEach((row) => {
            total += (parseFloat(row.querySelector('.q').value) || 0) * (parseFloat(row.querySelector('.c').value) || 0);
        });
        document.getElementById('po-total').textContent = fmt(total);
    }
    function addLine() {
        const i = index++;
        const row = document.createElement('div');
        row.className = 'payment-row';
        row.style.gridTemplateColumns = '2fr 1fr 1fr auto';
        row.innerHTML = '<select name="items[' + i + '][variant_id]" required>' + variants.map((v) => '<option value="' + v.id + '" data-cost="' + v.cost + '">' + v.label.replace(/</g, '&lt;') + '</option>').join('') + '</select>'
            + '<input class="q" type="number" name="items[' + i + '][quantity]" min="0.0001" step="0.0001" value="1" required aria-label="Jumlah">'
            + '<input class="c" type="number" name="items[' + i + '][unit_cost]" min="0" step="1" required aria-label="Harga beli">'
            + '<button type="button" aria-label="Hapus baris">×</button>';
        const select = row.querySelector('select');
        const cost = row.querySelector('.c');
        cost.value = variants.length ? variants[0].cost : 0;
        select.addEventListener('change', () => { cost.value = select.selectedOptions[0].dataset.cost; recalc(); });
        row.addEventListener('input', recalc);
        row.querySelector('button').addEventListener('click', () => { if (host.children.length > 1) { row.remove(); recalc(); } });
        host.appendChild(row);
        recalc();
    }
    document.getElementById('add-line').addEventListener('click', addLine);
    addLine();
})();
</script>
@endpush
