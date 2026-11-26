@extends('layouts.app')
@section('title','Penjualan')
@section('crumb','Penjualan')
@section('content')
@php $methodLabels = ['cash' => 'Tunai', 'bank_transfer' => 'Transfer bank', 'qris_external' => 'QRIS', 'qris_xendit' => 'QRIS', 'credit' => 'Kasbon']; @endphp
<div class="page-heading"><div><div class="eyebrow">RIWAYAT TRANSAKSI</div><h1>Penjualan</h1><p>Cari transaksi, lihat cara pembayaran, dan kelola pesanan yang keliru.</p></div><a class="btn btn-primary" href="{{ route('pos.index') }}">＋ Transaksi baru</a></div>
<div class="card section-card">
    <form class="toolbar" method="get"><div class="search-input"><span>⌕</span><input name="q" value="{{ request('q') }}" placeholder="Cari nomor transaksi atau pelanggan"></div><input style="max-width:165px" type="date" name="date" value="{{ request('date') }}"><button class="btn btn-secondary">Cari</button></form>
    <div class="table-wrap"><table class="sales-history-table">
        <thead><tr><th>NOMOR TRANSAKSI</th><th>WAKTU</th><th>PELANGGAN</th><th>KASIR</th><th>METODE BAYAR</th><th>STATUS</th><th class="text-right">TOTAL</th><th class="text-right">AKSI</th></tr></thead>
        <tbody>
        @forelse($sales as $sale)
            <tr>
                <td><a class="td-main" href="{{ route('sales.show',$sale->id) }}">{{ $sale->sale_number }}</a></td>
                <td>{{ \Illuminate\Support\Carbon::parse($sale->sold_at)->format('d M Y, H:i') }}</td>
                <td>{{ $sale->customer_name ?: 'Pelanggan umum' }}</td><td>{{ $sale->cashier_name }}</td>
                <td><div class="sale-method-list">
                    @forelse($sale->payment_tenders as $tender)
                        <div class="sale-method-detail"><span class="sale-method-chip">{{ $methodLabels[$tender->method] ?? \Illuminate\Support\Str::headline($tender->method) }}</span>
                            <span class="sale-payment-meta">Nominal Rp {{ number_format((float) $tender->amount,0,',','.') }}</span>
                            @if($tender->method === 'cash')
                                <span class="sale-payment-meta">Diterima Rp {{ number_format((float) $tender->amount + (float) $sale->change_total,0,',','.') }}</span>
                                <span class="sale-payment-meta">Kembalian Rp {{ number_format((float) $sale->change_total,0,',','.') }}</span>
                            @elseif(in_array($tender->method, ['bank_transfer','qris_external','qris_xendit'], true))
                                @if($tender->proof_payment_id)<a class="sale-proof-link" href="{{ route('sales.payment-proof', ['id' => $sale->id, 'paymentId' => $tender->proof_payment_id]) }}">Lihat lampiran ↗</a>@else<span class="sale-payment-meta">Tanpa lampiran</span>@endif
                            @endif
                        </div>
                    @empty<span class="muted">—</span>@endforelse
                </div></td>
                <td><span class="status {{ $sale->status === 'voided' ? 'voided' : (($sale->paid_total < $sale->grand_total) ? 'pending' : '') }}">{{ $sale->status === 'voided' ? 'Dibatalkan' : (($sale->paid_total < $sale->grand_total) ? 'Kasbon' : 'Selesai') }}</span></td>
                <td class="money text-right">Rp {{ number_format($sale->grand_total,0,',','.') }}</td>
                <td><div class="sale-row-actions">
                    @if($sale->status !== 'voided')<button class="sale-icon-action" type="button" data-open-receipt data-receipt-src="{{ route('documents.thermal', ['id' => $sale->id, 'w' => 58, 'embed' => 1]) }}" title="Lihat / cetak struk" aria-label="Lihat / cetak struk"><svg viewBox="0 0 24 24"><path d="M6 8V3h12v5M6 17H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6zM18 11h.01"/></svg></button>@endif
                    @if($canManageSales && $sale->status === 'completed')
                        <button class="sale-icon-action" type="button" data-open-sale-dialog="edit-sale-{{ $sale->id }}" title="Edit / koreksi pesanan" aria-label="Edit / koreksi pesanan"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L8 20l-5 1 1-5Z"/></svg></button>
                    @endif
                    @if($isOwner && $sale->status === 'completed')<button class="sale-icon-action sale-icon-danger" type="button" data-open-sale-dialog="cancel-sale-{{ $sale->id }}" title="Batal pesanan" aria-label="Batal pesanan"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/></svg></button>@endif
                </div></td>
            </tr>
        @empty<tr><td colspan="8"><div class="empty-state"><div class="empty-icon">↗</div><b>Belum ada transaksi</b><span>Transaksi yang berhasil disimpan akan tampil di sini.</span></div></td></tr>@endforelse
        </tbody>
    </table></div>
    <div class="pagination">{{ $sales->links() }}</div>
</div>
@foreach($sales as $sale)
    @if($canManageSales && $sale->status === 'completed')
        <dialog id="edit-sale-{{ $sale->id }}" class="sale-action-dialog">
            <div class="sale-dialog-heading"><div><span class="eyebrow">EDIT PESANAN</span><h2>{{ $sale->sale_number }}</h2><p>Harga, jumlah, subtotal, diskon, dan total dihitung ulang seperti kasir.</p></div><button type="button" class="sale-dialog-close" data-close-sale-dialog aria-label="Tutup">×</button></div>
            <form method="post" action="{{ route('sales.replace',$sale->id) }}" class="sale-edit-form" data-sale-editor data-discount="{{ $sale->discount_total }}" enctype="multipart/form-data">@csrf @method('PUT')<input type="hidden" name="edit_sale" value="{{ $sale->id }}">
                <div class="sale-edit-items">
                    @foreach($saleItems->get($sale->id, collect()) as $index => $item)
                        <div class="sale-edit-item" data-sale-line>
                            <div class="sale-edit-product"><b>{{ $item->name_snapshot }}</b><span class="td-sub">Rp {{ number_format((float) $item->unit_price,0,',','.') }} / unit</span><input type="hidden" name="items[{{ $index }}][sale_item_id]" value="{{ $item->id }}"><input type="hidden" data-line-price value="{{ number_format((float) $item->unit_price,2,'.','') }}"></div>
                            <label>Qty<input data-line-quantity type="number" name="items[{{ $index }}][quantity]" min="0.0001" step="0.0001" value="{{ rtrim(rtrim(number_format((float) $item->quantity,4,'.',''),'0'),'.') }}" required></label>
                            <b class="sale-line-total" data-line-total>Rp {{ number_format((float) $item->line_total,0,',','.') }}</b>
                        </div>
                    @endforeach
                    @if($saleItems->get($sale->id, collect())->isEmpty())<p class="muted">Tidak ada item dalam pesanan ini.</p>@endif
                </div>
                <div class="sale-edit-totals">
                    <div class="sale-edit-total-lines"><div><span>Subtotal</span><b data-sale-subtotal>Rp 0</b></div><div><span>Diskon</span><b data-sale-discount-display>− Rp 0</b></div><div class="sale-edit-grand"><span>Total pesanan</span><b data-sale-grand-total>Rp 0</b></div></div>
                </div>
                @php $selectedMethod = $sale->payment_methods->first() ?? 'cash'; $cashTender = (float) $sale->payment_tenders->where('method','cash')->sum('amount'); @endphp
                <div class="sale-edit-payment"><label>Metode pembayaran<select name="method" data-sale-method><option value="cash" @selected($selectedMethod === 'cash')>Tunai</option><option value="bank_transfer" @selected($selectedMethod === 'bank_transfer')>Transfer bank</option><option value="qris_external" @selected(in_array($selectedMethod, ['qris_external','qris_xendit'], true))>QRIS</option>@if($sale->customer_id)<option value="credit" @selected($selectedMethod === 'credit')>Kasbon</option>@endif</select></label></div>
                <div class="sale-proof-upload" data-proof-summary hidden><label>Lampiran bukti transfer / QRIS <span>Opsional · JPG, PNG, atau PDF · maks. 5 MB</span><input type="file" name="payment_proof" data-payment-proof accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" disabled></label></div>
                <div class="sale-cash-summary" data-cash-summary><label class="sale-cash-received">Uang diterima<input type="number" name="cash_received" data-sale-cash-received min="0" step="0.01" value="{{ rtrim(rtrim(number_format(max((float) $sale->grand_total, $cashTender + (float) $sale->change_total),2,'.',''),'0'),'.') }}" required></label><div><span>Kembalian</span><b data-sale-change>Rp 0</b></div></div>
                <div class="sale-dialog-actions"><button class="btn btn-primary" type="submit">Simpan perubahan</button></div>
            </form>
            <div class="sale-add-order"><a class="btn btn-secondary" href="{{ route('pos.index',['add_to_sale'=>$sale->id]) }}">＋ Tambah pesanan</a></div>
        </dialog>
    @endif
    @if($isOwner && $sale->status === 'completed')
        <dialog id="cancel-sale-{{ $sale->id }}" class="sale-action-dialog sale-cancel-dialog">
            <div class="sale-dialog-heading"><div><span class="eyebrow">PEMBATALAN PESANAN</span><h2>{{ $sale->sale_number }}</h2><p>Stok dan jurnal akan dibalik. Refund pembayaran digital yang sudah cair tetap diproses di penyedianya.</p></div><button type="button" class="sale-dialog-close" data-close-sale-dialog aria-label="Tutup">×</button></div>
            <form method="post" action="{{ route('sales.void',$sale->id) }}" class="form-stack" data-swal-confirm data-swal-title="Yakin batalkan pesanan?" data-swal-text="Pembatalan akan membalik stok dan jurnal untuk {{ $sale->sale_number }}." data-swal-icon="warning" data-swal-confirm-text="Ya, batalkan pesanan">@csrf
                <label>Alasan pembatalan<input name="reason" required minlength="5" maxlength="1000" placeholder="Misal: transaksi salah input"></label>
                <div class="sale-dialog-actions"><button class="btn btn-danger" type="submit">Batal pesanan</button></div>
            </form>
        </dialog>
    @endif
@endforeach
<dialog id="sales-receipt-dialog" class="sale-receipt-dialog"><div class="sale-dialog-heading"><div><span class="eyebrow">STRUK TRANSAKSI</span><h2>Pratinjau struk</h2></div><button type="button" class="sale-dialog-close" data-close-sale-dialog aria-label="Tutup">×</button></div><iframe title="Pratinjau struk transaksi" id="sales-receipt-frame" src="about:blank"></iframe><div class="sale-dialog-actions"><button type="button" class="btn btn-secondary" id="print-sales-receipt">Cetak struk</button><button type="button" class="btn btn-primary" data-close-sale-dialog>Tutup</button></div></dialog>
@endsection
@push('scripts')
@php $autoReceiptUrl = session('receipt_sale_id') ? route('documents.thermal', ['id' => session('receipt_sale_id'), 'w' => 58, 'embed' => 1]) : null; @endphp
<script>
    document.querySelectorAll('[data-open-sale-dialog]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.openSaleDialog)?.showModal()));
    document.querySelectorAll('[data-close-sale-dialog]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
    const receiptDialog = document.getElementById('sales-receipt-dialog');
    const receiptFrame = document.getElementById('sales-receipt-frame');
    document.querySelectorAll('[data-open-receipt]').forEach((button) => button.addEventListener('click', () => { receiptFrame.src = button.dataset.receiptSrc; receiptDialog.showModal(); }));
    document.getElementById('print-sales-receipt')?.addEventListener('click', () => receiptFrame.contentWindow?.print());
    receiptDialog?.addEventListener('close', () => { receiptFrame.src = 'about:blank'; });
    @if($autoReceiptUrl) receiptFrame.src = @json($autoReceiptUrl); receiptDialog?.showModal(); @endif
    @if($errors->any() && old('edit_sale')) document.getElementById('edit-sale-{{ old('edit_sale') }}')?.showModal(); @endif
    const rupiah = (amount) => `Rp ${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.round(Number(amount) || 0))}`;
    const compactAmount = (amount) => (Number(amount) || 0).toFixed(2).replace(/\.?0+$/, '');
    function recalculateSaleEditor(form) {
        const lines = [...form.querySelectorAll('[data-sale-line]')];
        const subtotal = lines.reduce((sum, line) => sum + (Number(line.querySelector('[data-line-price]')?.value) || 0) * (Number(line.querySelector('[data-line-quantity]')?.value) || 0), 0);
        const discount = Math.min(Number(form.dataset.discount) || 0, subtotal);
        const total = Math.max(0, subtotal - discount);
        form.querySelector('[data-sale-subtotal]').textContent = rupiah(subtotal);
        form.querySelector('[data-sale-discount-display]').textContent = `− ${rupiah(discount)}`;
        form.querySelector('[data-sale-grand-total]').textContent = rupiah(total);
        const method = form.querySelector('[data-sale-method]')?.value;
        const cashInput = form.querySelector('[data-sale-cash-received]');
        const cashSummary = form.querySelector('[data-cash-summary]');
        const proofSummary = form.querySelector('[data-proof-summary]');
        const proofInput = form.querySelector('[data-payment-proof]');
        cashSummary.hidden = method !== 'cash';
        const needsProof = ['bank_transfer', 'qris_external'].includes(method);
        proofSummary.hidden = !needsProof;
        proofInput.disabled = !needsProof;
        if (cashInput) cashInput.required = method === 'cash';
        if (cashInput && method === 'cash' && document.activeElement !== cashInput) cashInput.value = compactAmount(Math.max(total, Number(cashInput.dataset.received) || total));
        if (cashInput && method === 'cash') { cashInput.min = total.toFixed(2); cashInput.dataset.received = cashInput.value; }
        const received = method === 'cash' ? Number(cashInput?.value) || 0 : total;
        form.querySelector('[data-sale-change]').textContent = rupiah(Math.max(0, received - total));
        lines.forEach((line) => {
            const amount = (Number(line.querySelector('[data-line-price]')?.value) || 0) * (Number(line.querySelector('[data-line-quantity]')?.value) || 0);
            line.querySelector('[data-line-total]').textContent = rupiah(amount);
        });
    }
    document.querySelectorAll('[data-sale-editor]').forEach((form) => {
        const cashInput = form.querySelector('[data-sale-cash-received]');
        if (cashInput) cashInput.dataset.received = cashInput.value;
        recalculateSaleEditor(form);
        form.addEventListener('input', (event) => { if (event.target.matches('[data-line-quantity],[data-sale-cash-received]')) recalculateSaleEditor(form); });
        form.addEventListener('change', (event) => { if (event.target.matches('[data-sale-method]')) recalculateSaleEditor(form); });
    });
</script>
@endpush
