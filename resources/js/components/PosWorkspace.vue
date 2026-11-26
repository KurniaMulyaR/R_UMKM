<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import QRCode from 'qrcode';

const props = defineProps({
    catalog: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    checkoutUrl: { type: String, required: true },
    csrfToken: { type: String, required: true },
    taxSettings: { type: Object, default: () => ({ enabled: false, rate: 11, mode: 'exclusive' }) },
    shiftOpen: { type: Boolean, default: false },
    shiftOpenedAt: { type: String, default: '' },
    productsUrl: { type: String, default: '/products' },
    shiftsUrl: { type: String, default: '/shifts' },
    xenditEnabled: { type: Boolean, default: false },
    qrisUrl: { type: String, default: '' },
    initialCustomerId: { type: String, default: '' },
    initialNotes: { type: String, default: '' },
    relatedSaleNumber: { type: String, default: '' },
});

const { catalog, customers, checkoutUrl, csrfToken, shiftOpen, shiftOpenedAt, productsUrl, shiftsUrl } = props;
const relatedSaleNumber = props.relatedSaleNumber;

const search = ref('');
const activeCategory = ref('Semua');
const cart = ref([]);
const discount = ref(0);
const customerId = ref(props.initialCustomerId);
const cashReceived = ref(0);
const notes = ref(props.initialNotes);
const payments = ref([{ method: 'cash', amount: 0 }]);
const message = ref('');
const searchInput = ref(null);
const checkoutForm = ref(null);
const isSubmitting = ref(false);
const receipt = ref(null);
const qris = ref(null);
const qrisBusy = ref(false);
const qrisCanvas = ref(null);
let qrisTimer = null;
const hasXendit = computed(() => payments.value.some((payment) => payment.method === 'qris_xendit'));

const categories = computed(() => ['Semua', ...new Set(props.catalog.map((item) => item.category_name || 'Umum'))]);
const filteredProducts = computed(() => {
    const query = search.value.trim().toLocaleLowerCase('id-ID');
    return props.catalog.filter((item) => {
        const matchesCategory = activeCategory.value === 'Semua' || (item.category_name || 'Umum') === activeCategory.value;
        const searchable = `${item.name} ${item.sku} ${item.barcode || ''}`.toLocaleLowerCase('id-ID');
        return matchesCategory && (!query || searchable.includes(query));
    });
});
const subtotal = computed(() => cart.value.reduce((sum, item) => sum + item.price * item.quantity, 0));
const discountValue = computed(() => Math.min(Math.max(0, Number(discount.value) || 0), subtotal.value));
const roundMoney = (value) => Math.round((value + Number.EPSILON) * 100) / 100;
const netAfterDiscount = computed(() => Math.max(0, subtotal.value - discountValue.value));
const taxAmount = computed(() => {
    const tax = props.taxSettings;
    if (!tax.enabled || netAfterDiscount.value <= 0) return 0;
    return tax.mode === 'inclusive'
        ? roundMoney(netAfterDiscount.value * tax.rate / (100 + tax.rate))
        : roundMoney(netAfterDiscount.value * tax.rate / 100);
});
const total = computed(() => props.taxSettings.enabled && props.taxSettings.mode === 'exclusive' ? roundMoney(netAfterDiscount.value + taxAmount.value) : roundMoney(netAfterDiscount.value));
const itemCount = computed(() => cart.value.reduce((sum, item) => sum + Number(item.quantity), 0));
const paymentTotal = computed(() => payments.value.reduce((sum, payment) => sum + (Number(payment.amount) || 0), 0));
const cashTotal = computed(() => payments.value.filter((payment) => payment.method === 'cash').reduce((sum, payment) => sum + (Number(payment.amount) || 0), 0));
const change = computed(() => Math.max(0, Number(cashReceived.value || 0) - cashTotal.value));
const hasTransferPayment = computed(() => payments.value.some((payment) => ['bank_transfer', 'qris_external'].includes(payment.method)));
watch(total, (value) => { if (payments.value.length === 1) payments.value[0].amount = value; });
const hasCredit = computed(() => payments.value.some((payment) => payment.method === 'credit'));
const canSubmit = computed(() => props.shiftOpen && cart.value.length > 0 && total.value > 0
    && Math.round(paymentTotal.value * 100) === Math.round(total.value * 100)
    && cashTotal.value <= Number(cashReceived.value || 0)
    && (!hasCredit.value || Boolean(customerId.value)));

function formatRupiah(value) {
    return `Rp ${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.round(Number(value) || 0))}`;
}

function addProduct(product) {
    message.value = '';
    const current = cart.value.find((item) => item.variant_id === product.variant_id);
    if (current && (current.unit_id || null) !== (product.unit_id || null)) {
        message.value = `${product.name} sudah ada di keranjang dengan satuan lain. Hapus barisnya dulu.`;
        return;
    }
    if (product.track_stock && (Number(current?.quantity || 0) + 1) > Number(product.stock || 0)) {
        message.value = `Stok ${product.name} tersisa ${product.stock}.`;
        return;
    }
    if (current) {
        current.quantity += 1;
    } else {
        cart.value.push({ ...product, quantity: 1 });
    }
    syncSinglePayment();
}

function setQuantity(item, value) {
    const quantity = Number(value);
    if (!quantity || quantity <= 0) {
        removeItem(item.variant_id);
        return;
    }
    if (item.track_stock && quantity > Number(item.stock || 0)) {
        message.value = `Stok ${item.name} hanya ${item.stock}.`;
        return;
    }
    message.value = '';
    item.quantity = quantity;
    syncSinglePayment();
}

function removeItem(variantId) {
    cart.value = cart.value.filter((item) => item.variant_id !== variantId);
    syncSinglePayment();
}

function syncSinglePayment() {
    if (payments.value.length === 1) payments.value[0].amount = total.value;
}

function addPayment() {
    if (payments.value.length >= 4) return;
    payments.value.push({ method: 'bank_transfer', amount: Math.max(0, total.value - paymentTotal.value) });
}

function removePayment(index) {
    payments.value.splice(index, 1);
    syncSinglePayment();
}

function changePaymentMethod(payment) {
    message.value = '';
    if (payments.value.length === 1) syncSinglePayment();
}

function scanBarcode() {
    const barcode = search.value.trim();
    const product = props.catalog.find((item) => item.barcode && item.barcode === barcode);
    if (product) {
        addProduct(product);
        search.value = '';
        activeCategory.value = 'Semua';
    } else if (/^\S{4,}$/.test(barcode) && !filteredProducts.value.length) {
        message.value = `Barcode ${barcode} belum terdaftar.`;
    }
}

const printReceipt = ref(false);
try {
    printReceipt.value = window.localStorage.getItem('rk_print_receipt') === '1';
} catch (error) {
    printReceipt.value = false;
}
watch(printReceipt, (value) => {
    try {
        window.localStorage.setItem('rk_print_receipt', value ? '1' : '0');
    } catch (error) {
        // penyimpanan peramban tidak tersedia; pilihan hanya berlaku di sesi ini
    }
});

function captureScannerKeys(event) {
    const tag = (document.activeElement?.tagName || '').toLowerCase();
    if (['input', 'textarea', 'select', 'button'].includes(tag) || event.ctrlKey || event.metaKey || event.altKey) return;
    if (event.key.length === 1 && searchInput.value) searchInput.value.focus();
}

onMounted(() => {
    window.addEventListener('keydown', captureScannerKeys);
    searchInput.value?.focus();
});
onBeforeUnmount(() => window.removeEventListener('keydown', captureScannerKeys));

async function validateCheckout(event) {
    if (!canSubmit.value) {
        event.preventDefault();
        if (!props.shiftOpen) message.value = 'Buka shift kasir sebelum menyimpan transaksi.';
        else if (!cart.value.length) message.value = 'Pilih produk untuk memulai transaksi.';
        else if (hasCredit.value && !customerId.value) message.value = 'Pilih pelanggan untuk pembayaran kasbon.';
        else if (cashTotal.value > Number(cashReceived.value || 0)) message.value = 'Uang tunai diterima belum cukup.';
        else message.value = 'Jumlah metode pembayaran harus sama dengan total transaksi.';
        return;
    }
    if (hasXendit.value) {
        event.preventDefault();
        startQris();
        return;
    }

    event.preventDefault();
    await submitCheckout(event.currentTarget);
}

async function submitCheckout(form) {
    if (isSubmitting.value) return;
    isSubmitting.value = true;
    message.value = '';
    try {
        const response = await fetch(checkoutUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: new FormData(form),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            message.value = data.message || Object.values(data.errors || {}).flat()[0] || 'Transaksi belum bisa disimpan. Coba lagi.';
            return;
        }

        receipt.value = data.receipt;
        cart.value = [];
        discount.value = 0;
        customerId.value = '';
        cashReceived.value = 0;
        notes.value = '';
        payments.value = [{ method: 'cash', amount: 0 }];
        const proofInput = form.querySelector('[name="payment_proof"]');
        if (proofInput) proofInput.value = '';
        await nextTick();
        if (printReceipt.value) window.print();
    } catch (error) {
        message.value = 'Koneksi bermasalah saat menyimpan transaksi.';
    } finally {
        isSubmitting.value = false;
    }
}

function closeReceipt() {
    receipt.value = null;
    nextTick(() => searchInput.value?.focus());
}

function qrisHeaders() {
    return { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken };
}

async function startQris() {
    if (qrisBusy.value) return;
    qrisBusy.value = true;
    message.value = '';
    try {
        const response = await fetch(props.qrisUrl, {
            method: 'POST', headers: qrisHeaders(), credentials: 'same-origin',
            body: JSON.stringify({
                customer_id: customerId.value || null, discount: discountValue.value, cash_received: Number(cashReceived.value) || 0, notes: notes.value,
                items: cart.value.map((item) => ({ variant_id: item.variant_id, unit_id: item.unit_id || null, quantity: item.quantity })),
                payments: payments.value.map((payment) => ({ method: payment.method, amount: Number(payment.amount) || 0 })),
            }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            message.value = data.message || Object.values(data.errors || {}).flat()[0] || 'QRIS belum bisa dibuat. Coba lagi.';
            return;
        }
        qris.value = { ...data, state: 'waiting' };
        await nextTick();
        if (qrisCanvas.value) await QRCode.toCanvas(qrisCanvas.value, data.qr_string, { width: 260, margin: 1 });
        qrisTimer = setInterval(pollQris, 3000);
    } catch (error) {
        message.value = 'Koneksi bermasalah saat membuat QRIS.';
    } finally {
        qrisBusy.value = false;
    }
}

async function pollQris() {
    if (!qris.value) return;
    try {
        const response = await fetch(qris.value.status_url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const data = await response.json();
        if (data.status === 'paid') {
            stopQrisTimer();
            qris.value.state = 'paid';
            submitQrisComplete();
        } else if (['expired', 'cancelled', 'amount_mismatch'].includes(data.status)) {
            stopQrisTimer();
            qris.value.state = data.status;
        }
    } catch (error) {
        /* jaringan sesaat putus; coba lagi pada interval berikutnya */
    }
}

function stopQrisTimer() {
    if (qrisTimer) clearInterval(qrisTimer);
    qrisTimer = null;
}

async function submitQrisComplete() {
    const currentCharge = qris.value;
    if (!currentCharge || currentCharge.completing) return;
    currentCharge.completing = true;
    try {
        const response = await fetch(currentCharge.complete_url, {
            method: 'POST', headers: qrisHeaders(), credentials: 'same-origin', body: JSON.stringify({}),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            message.value = data.message || 'Pembayaran diterima, tetapi transaksi belum selesai tersimpan.';
            return;
        }
        qris.value = null;
        receipt.value = data.receipt;
        cart.value = [];
        discount.value = 0;
        customerId.value = '';
        cashReceived.value = 0;
        notes.value = '';
        payments.value = [{ method: 'cash', amount: 0 }];
        await nextTick();
        if (printReceipt.value) window.print();
    } catch (error) {
        message.value = 'Pembayaran diterima, tetapi koneksi terputus saat menyelesaikan transaksi.';
    }
}

async function closeQris() {
    stopQrisTimer();
    const current = qris.value;
    qris.value = null;
    if (current && current.state === 'waiting') {
        fetch(current.cancel_url, { method: 'POST', headers: qrisHeaders(), credentials: 'same-origin' }).catch(() => {});
    }
}

onBeforeUnmount(stopQrisTimer);
</script>

<template>
    <div class="page-heading pos-page-heading">
        <div><div class="eyebrow">RUANG TRANSAKSI</div><h1>Kasir</h1><p>{{ relatedSaleNumber ? `Tambah pesanan untuk transaksi ${relatedSaleNumber}. Penjualan tambahan dicatat sebagai transaksi baru.` : 'Catat penjualan anggota dan pelanggan dengan mudah.' }}</p></div>
        <span v-if="shiftOpen" class="shift-open-chip"><i></i> Shift aktif <span v-if="shiftOpenedAt">· {{ shiftOpenedAt }}</span></span>
        <a v-else :href="shiftsUrl" class="btn btn-soft">Buka shift <span>→</span></a>
    </div>

    <div v-if="!shiftOpen" class="shift-banner coop-shift-banner"><span><b>Shift kasir belum dibuka.</b> Buka shift untuk mulai mencatat transaksi.</span><a :href="shiftsUrl" class="btn btn-primary">Buka shift <span>→</span></a></div>

    <div class="pos-shell coop-pos-shell">
        <section class="pos-main">
            <div class="pos-tools">
                <label class="pos-search coop-search"><span aria-hidden="true">⌕</span><input ref="searchInput" v-model="search" placeholder="Cari nama, SKU, atau scan barcode" autocomplete="off" @keydown.enter.prevent="scanBarcode"><button v-if="search" class="clear-search" type="button" @click="search = ''; searchInput?.focus()">×</button></label>
                <div class="catalog-hint"><span class="scan-dot"></span> Scan barcode lalu tekan Enter</div>
            </div>
            <div class="category-pills" aria-label="Kategori produk">
                <button v-for="category in categories" :key="category" type="button" class="pill" :class="{ active: activeCategory === category }" @click="activeCategory = category">{{ category }}</button>
            </div>
            <div class="product-grid coop-product-grid">
                <button v-for="product in filteredProducts" :key="product.cart_key" type="button" class="product-tile coop-product-tile" :disabled="product.track_stock && Number(product.stock) <= 0" @click="addProduct(product)">
                    <span class="product-color">{{ product.name.slice(0, 1).toLocaleUpperCase('id-ID') }}</span>
                    <span class="product-name">{{ product.name }}</span>
                    <span class="product-meta">{{ product.sku }} · {{ product.category_name || 'Umum' }}</span>
                    <span class="product-bottom"><b>{{ formatRupiah(product.price) }}</b><small>{{ product.track_stock ? `${new Intl.NumberFormat('id-ID').format(product.stock)} ${product.unit_symbol}` : 'Jasa' }}</small></span>
                </button>
                <div v-if="!filteredProducts.length" class="card empty-state catalog-empty"><div class="empty-icon">⌕</div><b>{{ catalog.length ? 'Produk belum ditemukan' : 'Belum ada produk untuk dijual' }}</b><span>{{ catalog.length ? 'Coba kata kunci atau kategori yang lain.' : 'Tambahkan produk dan harga jual terlebih dahulu.' }}</span><a v-if="!catalog.length" class="btn btn-primary" :href="productsUrl">Kelola produk</a></div>
            </div>
            <div v-if="message" class="pos-message" role="status"><span>!</span>{{ message }}<button type="button" aria-label="Tutup pesan" @click="message = ''">×</button></div>
        </section>

        <aside class="card cart-card coop-cart-card">
            <div class="cart-head"><div><small class="cart-overline">RINGKASAN</small><h2>Keranjang</h2></div><span class="cart-count">{{ itemCount }} barang</span></div>
            <div class="cart-lines">
                <div v-for="item in cart" :key="item.variant_id" class="cart-line coop-cart-line">
                    <div class="cart-item-info"><b>{{ item.name }}</b><small>{{ formatRupiah(item.price) }} / {{ item.unit_symbol }}</small><strong>{{ formatRupiah(item.price * item.quantity) }}</strong></div>
                    <input class="qty-input" type="number" min="0.0001" step="0.0001" :max="item.track_stock ? item.stock : undefined" :value="item.quantity" :aria-label="`Jumlah ${item.name}`" @change="setQuantity(item, $event.target.value)">
                    <button class="remove-line" type="button" :aria-label="`Hapus ${item.name}`" @click="removeItem(item.variant_id)">×</button>
                </div>
                <div v-if="!cart.length" class="cart-empty"><span class="basket-illustration">♧</span><b>Keranjang masih kosong</b><span>Pilih barang untuk mulai mencatat transaksi.</span></div>
            </div>

            <form ref="checkoutForm" method="post" enctype="multipart/form-data" :action="checkoutUrl" class="pos-checkout coop-checkout" @submit="validateCheckout">
                <input type="hidden" name="_token" :value="csrfToken">
                <template v-for="(item, index) in cart" :key="item.variant_id">
                    <input type="hidden" :name="`items[${index}][variant_id]`" :value="item.variant_id">
                    <input v-if="item.unit_id" type="hidden" :name="`items[${index}][unit_id]`" :value="item.unit_id">
                    <input type="hidden" :name="`items[${index}][quantity]`" :value="item.quantity">
                </template>
                <div class="cart-totals"><div class="total-row"><span>Subtotal</span><b>{{ formatRupiah(subtotal) }}</b></div><label>Potongan harga<input v-model.number="discount" name="discount" type="number" min="0" :max="subtotal" step="1" placeholder="0"></label><div v-if="discountValue" class="total-row discount-row"><span>Diskon</span><b>− {{ formatRupiah(discountValue) }}</b></div><div v-if="taxAmount > 0" class="total-row"><span>{{ taxSettings.mode === 'inclusive' ? 'Termasuk PPN' : 'PPN' }} {{ taxSettings.rate }}%</span><b>{{ formatRupiah(taxAmount) }}</b></div><div class="total-row grand"><span>Total belanja</span><b>{{ formatRupiah(total) }}</b></div></div>
                <div class="checkout-fields">
                    <label>Pelanggan <span class="optional">wajib untuk kasbon</span><select v-model="customerId" name="customer_id"><option value="">Pelanggan umum</option><option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}{{ customer.phone ? ` · ${customer.phone}` : '' }}</option></select></label>
                    <div class="pay-actions"><label>Metode pembayaran</label><button type="button" class="small-link" :disabled="payments.length >= 4" @click="addPayment">＋ Split bayar</button></div>
                    <div class="payment-stack">
                        <div v-for="(payment, index) in payments" :key="index" class="payment-row">
                            <select v-model="payment.method" :name="`payments[${index}][method]`" :aria-label="`Metode pembayaran ${index + 1}`" @change="changePaymentMethod(payment)"><option value="cash">Tunai</option><option value="bank_transfer">Transfer bank</option><option value="qris_external">QRIS (manual)</option><option v-if="xenditEnabled" value="qris_xendit">QRIS Xendit (otomatis)</option><option value="credit">Kasbon</option></select>
                            <input v-model.number="payment.amount" :name="`payments[${index}][amount]`" type="number" min="0.01" step="0.01" :aria-label="`Nominal pembayaran ${index + 1}`" required>
                            <button v-if="payments.length > 1" type="button" :aria-label="`Hapus metode bayar ${index + 1}`" @click="removePayment(index)">×</button>
                        </div>
                    </div>
                    <label v-if="cashTotal > 0">Uang tunai diterima<input v-model.number="cashReceived" name="cash_received" type="number" min="0" step="0.01" placeholder="0"></label>
                    <div v-if="cashTotal > 0" class="total-row change-row"><span>Kembalian</span><b>{{ formatRupiah(change) }}</b></div>
                    <label v-if="hasTransferPayment">Bukti transfer / QRIS <span class="optional">opsional · JPG, PNG, atau PDF, maks. 5 MB</span><input type="file" name="payment_proof" accept="image/jpeg,image/png,application/pdf"></label>
                    <label>Catatan <span class="optional">opsional</span><input v-model="notes" name="notes" maxlength="1000" placeholder="Catatan transaksi"></label>
                    <label class="check-row"><input type="checkbox" v-model="printReceipt"> Cetak struk otomatis setelah transaksi</label>
                    <input v-if="printReceipt" type="hidden" name="print_receipt" value="1">
                    <button class="btn btn-primary btn-block coop-submit" type="submit" :disabled="!canSubmit || isSubmitting">{{ isSubmitting ? 'Menyimpan transaksi…' : 'Simpan transaksi' }} <span v-if="!isSubmitting">→</span></button>
                    <p v-if="hasCredit && !customerId" class="checkout-note">Pilih pelanggan agar transaksi kasbon tercatat.</p>
                </div>
            </form>
        </aside>
    </div>

    <div v-if="receipt" class="receipt-modal" role="dialog" aria-modal="true" aria-label="Struk penjualan">
        <section class="receipt-modal-panel">
            <header class="receipt-modal-heading"><div><span class="eyebrow">TRANSAKSI BERHASIL</span><h2>Struk penjualan</h2><p>{{ receipt.sale_number }}</p></div><button class="receipt-close" type="button" aria-label="Tutup struk" @click="closeReceipt">×</button></header>
            <div class="receipt-paper">
                <div class="receipt-center"><b>{{ receipt.tenant_name }}</b><span>{{ receipt.outlet_name }}</span><span v-if="receipt.outlet_address">{{ receipt.outlet_address }}</span><span v-if="receipt.outlet_phone">Telp {{ receipt.outlet_phone }}</span></div>
                <hr>
                <div>No: {{ receipt.sale_number }}</div><div>Tgl: {{ receipt.sold_at }}</div><div>Kasir: {{ receipt.cashier }}</div><div v-if="receipt.customer_name">Pelanggan: {{ receipt.customer_name }}</div>
                <hr>
                <div v-for="(item, index) in receipt.items" :key="index" class="receipt-item"><b>{{ item.name }}<template v-if="item.variant && item.variant !== 'Default'"> — {{ item.variant }}</template></b><div class="receipt-line"><span>{{ item.quantity }} {{ item.unit || '' }} × {{ formatRupiah(item.unit_price) }}</span><span>{{ formatRupiah(item.line_subtotal) }}</span></div></div>
                <hr>
                <div class="receipt-line"><span>Subtotal</span><span>{{ formatRupiah(receipt.subtotal) }}</span></div>
                <div v-if="receipt.discount > 0" class="receipt-line"><span>Diskon</span><span>− {{ formatRupiah(receipt.discount) }}</span></div>
                <div v-if="receipt.tax > 0" class="receipt-line"><span>{{ receipt.tax_mode === 'inclusive' ? 'Termasuk PPN' : 'PPN' }} {{ receipt.tax_rate }}%</span><span>{{ formatRupiah(receipt.tax) }}</span></div>
                <div class="receipt-line receipt-total"><span>TOTAL</span><span>{{ formatRupiah(receipt.total) }}</span></div>
                <div v-for="(payment, index) in receipt.payments" :key="`payment-${index}`" class="receipt-line"><span>{{ { cash: 'Tunai', bank_transfer: 'Transfer bank', qris_external: 'QRIS', qris_xendit: 'QRIS', credit: 'Kasbon' }[payment.method] || payment.method }}</span><span>{{ formatRupiah(payment.amount) }}</span></div>
                <div v-if="receipt.change > 0" class="receipt-line"><span>Kembalian</span><span>{{ formatRupiah(receipt.change) }}</span></div>
                <div v-if="receipt.credit > 0" class="receipt-line receipt-total"><span>Kasbon</span><span>{{ formatRupiah(receipt.credit) }}</span></div>
                <hr><div class="receipt-center">{{ receipt.footer || 'Terima kasih' }}</div>
            </div>
            <footer class="receipt-modal-actions"><button class="btn btn-secondary" type="button" @click="window.print()">Cetak struk</button><button class="btn btn-primary" type="button" @click="closeReceipt">Transaksi baru</button></footer>
        </section>
    </div>

    <div v-if="qris" class="qris-modal" role="dialog" aria-modal="true" aria-label="Pembayaran QRIS">
        <div class="qris-card">
            <h2>Scan untuk membayar</h2>
            <b class="qris-amount">{{ formatRupiah(qris.amount) }}</b>
            <canvas ref="qrisCanvas" width="260" height="260"></canvas>
            <p v-if="qris.state === 'waiting'" class="muted">Menunggu pembayaran… transaksi tersimpan otomatis setelah lunas.</p>
            <p v-else-if="qris.state === 'paid'" class="muted">Pembayaran diterima, menyimpan transaksi…</p>
            <p v-else class="muted">{{ qris.state === 'expired' ? 'QR kedaluwarsa.' : qris.state === 'amount_mismatch' ? 'Nominal yang dibayar tidak sama. Hubungi pemilik.' : 'QR dibatalkan.' }} Buat QR baru dari kasir.</p>
            <button type="button" class="btn btn-secondary" @click="closeQris">{{ qris.state === 'waiting' ? 'Batalkan' : 'Tutup' }}</button>
        </div>
    </div>
</template>
