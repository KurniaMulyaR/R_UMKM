<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    userName: { type: String, default: '' },
    dateLabel: { type: String, default: '' },
    summary: { type: Object, required: true },
    cashAccounts: { type: Array, default: () => [] },
    week: { type: Array, default: () => [] },
    methods: { type: Array, default: () => [] },
    trend: { type: Array, default: () => [] },
    lowStock: { type: Array, default: () => [] },
    recent: { type: Array, default: () => [] },
    shiftOpen: { type: Boolean, default: false },
    urls: { type: Object, default: () => ({}) },
});

const nf = new Intl.NumberFormat('id-ID');
const rp = (v) => `Rp ${nf.format(Math.round(v || 0))}`;
const short = (v) => {
    const n = Math.abs(v);
    if (n >= 1e9) return `${+(v / 1e9).toFixed(1)} M`;
    if (n >= 1e6) return `${+(v / 1e6).toFixed(1)} jt`;
    if (n >= 1e3) return `${Math.round(v / 1e3)} rb`;
    return `${Math.round(v)}`;
};
const firstName = computed(() => (props.userName || 'kamu').split(' ')[0]);

// ----- weekly activity (grouped bars) -----
const W = 560, H = 230, padL = 46, padB = 28, padT = 10;
const weekMax = computed(() => {
    const m = Math.max(1, ...props.week.map((d) => Math.max(d.revenue, d.profit)));
    const p = 10 ** Math.floor(Math.log10(m));
    return Math.ceil(m / p) * p;
});
const ticks = computed(() => [0, 1, 2, 3].map((i) => (weekMax.value / 3) * i));
const plotH = H - padB - padT;
const yOf = (v) => padT + plotH - (v / weekMax.value) * plotH;
const slot = computed(() => (W - padL) / Math.max(1, props.week.length));
const bars = computed(() => props.week.map((d, i) => {
    const x = padL + slot.value * i + slot.value / 2;
    const bw = Math.min(16, slot.value / 4);
    return {
        label: d.label, title: `${d.label} · Omzet ${rp(d.revenue)} · Untung ${rp(d.profit)} · ${d.transactions} transaksi`,
        ax: x - bw - 2, bx: x + 2, bw,
        ah: Math.max(3, padT + plotH - yOf(d.revenue)), bh: Math.max(3, padT + plotH - yOf(d.profit)),
        x,
    };
}));

// ----- donut -----
const palette = ['var(--primary)', 'var(--teal)', 'var(--amber)', 'var(--violet)', 'var(--rose)'];
const methodTotal = computed(() => props.methods.reduce((s, m) => s + m.value, 0));
const arcs = computed(() => {
    const total = methodTotal.value || 1;
    const R = 62, C = 2 * Math.PI * R;
    let offset = 0;
    return props.methods.map((m, i) => {
        const len = (m.value / total) * C;
        const arc = { ...m, color: palette[i % palette.length], dash: `${Math.max(0, len - 3)} ${C - Math.max(0, len - 3)}`, offset: -offset, pct: Math.round((m.value / total) * 100) };
        offset += len;
        return arc;
    });
});

// ----- trend line -----
const TW = 640, TH = 190, tPadL = 46, tPadB = 24;
const trendMax = computed(() => Math.max(1, ...props.trend.map((t) => t.revenue)) * 1.1);
const tx = (i) => tPadL + ((TW - tPadL - 8) / Math.max(1, props.trend.length - 1)) * i;
const ty = (v) => 8 + (TH - tPadB - 8) - (v / trendMax.value) * (TH - tPadB - 8);
const linePath = computed(() => {
    const pts = props.trend.map((t, i) => [tx(i), ty(t.revenue)]);
    if (pts.length < 2) return '';
    let d = `M${pts[0][0]},${pts[0][1]}`;
    for (let i = 1; i < pts.length; i++) {
        const cx = (pts[i - 1][0] + pts[i][0]) / 2;
        d += ` C${cx},${pts[i - 1][1]} ${cx},${pts[i][1]} ${pts[i][0]},${pts[i][1]}`;
    }
    return d;
});
const areaPath = computed(() => (linePath.value ? `${linePath.value} L${tx(props.trend.length - 1)},${TH - tPadB} L${tx(0)},${TH - tPadB} Z` : ''));

const cardClass = ['', 'alt', 'teal'];
const typeLabel = { cash: 'Kas tunai', bank: 'Rekening bank', bank_transfer: 'Rekening bank', qris_clearing: 'Saldo QRIS' };
const txIcon = (r) => (r.credit ? 'wallet' : 'arrowDown');
</script>

<template>
    <div class="dash">
        <div class="dash-hello">
            <div>
                <div class="eyebrow">{{ dateLabel.toUpperCase() }}</div>
                <h1>Halo, {{ firstName }} 👋</h1>
                <p>Ringkasan usaha kamu hari ini.</p>
            </div>
            <a class="btn btn-primary" :href="urls.pos"><Icon name="plus" /> Transaksi baru</a>
        </div>

        <div v-if="!shiftOpen" class="shift-banner">
            <span class="shift-banner-copy"><b>Shift kasir belum dibuka</b><small>Buka shift untuk mulai mencatat penjualan.</small></span>
            <a class="btn btn-soft" :href="urls.shifts">Buka shift →</a>
        </div>

        <div class="kpi-strip">
            <article class="card kpi"><span class="kpi-icon" style="background:var(--primary-soft);color:var(--primary)"><Icon name="sales" /></span><div><small>Omzet hari ini</small><b>{{ rp(summary.revenue) }}</b></div></article>
            <article class="card kpi"><span class="kpi-icon" style="background:var(--teal-soft);color:var(--teal)"><Icon name="receipt" /></span><div><small>Transaksi selesai</small><b>{{ nf.format(summary.transactions) }}</b></div></article>
            <article class="card kpi"><span class="kpi-icon" style="background:var(--violet-soft);color:var(--violet)"><Icon name="spark" /></span><div><small>Untung kotor</small><b>{{ rp(summary.grossProfit) }}</b></div></article>
            <article class="card kpi"><span class="kpi-icon" style="background:var(--amber-soft);color:var(--amber)"><Icon name="box" /></span><div><small>Produk aktif</small><b>{{ nf.format(summary.products) }}</b></div></article>
        </div>

        <div class="dash-grid dash-row-top">
            <section>
                <h2 class="dash-title">Saldo kas &amp; rekening <a :href="urls.shifts">Lihat shift</a></h2>
                <div class="dash-cards">
                    <article v-for="(acc, i) in cashAccounts.slice(0, 2)" :key="acc.name" class="bal-card" :class="cardClass[i]">
                        <div class="bal-head"><div><div class="bal-label">{{ typeLabel[acc.type] || acc.type }}</div><div class="bal-value">{{ rp(acc.balance) }}</div></div><span class="bal-chip"></span></div>
                        <div class="bal-foot"><div><small>AKUN</small><b>{{ acc.name }}</b></div><div><small>STATUS</small><b>Terposting</b></div></div>
                    </article>
                    <article v-if="!cashAccounts.length" class="bal-card"><div class="bal-head"><div><div class="bal-label">Saldo kas</div><div class="bal-value">Rp 0</div></div><span class="bal-chip"></span></div><div class="bal-foot"><div><small>AKUN</small><b>Belum ada akun kas</b></div></div></article>
                </div>
            </section>
            <section>
                <h2 class="dash-title">Transaksi terbaru <a :href="urls.sales">Lihat semua</a></h2>
                <div class="card tx-list">
                    <a v-for="r in recent.slice(0, 4)" :key="r.number" :href="r.url" class="tx-row">
                        <span class="tx-icon" :class="r.credit ? 'amber' : 'teal'"><Icon :name="txIcon(r)" /></span>
                        <span class="tx-copy"><b>{{ r.number }}</b><small>{{ r.customer }} · {{ r.time }}</small></span>
                        <span class="tx-amount" :class="r.credit ? 'warn' : 'up'">+{{ rp(r.total) }}</span>
                    </a>
                    <div v-if="!recent.length" class="dash-empty"><b>Belum ada penjualan</b><span>Transaksi pertamamu akan muncul di sini.</span></div>
                </div>
            </section>
        </div>

        <div class="dash-grid dash-row-mid">
            <section>
                <h2 class="dash-title">Metode bayar <small style="color:var(--muted);font-weight:500;font-size:12px">7 hari</small></h2>
                <div class="card chart-card">
                    <div v-if="methods.length" class="donut-wrap">
                        <svg viewBox="0 0 160 160" class="svg-chart" role="img" aria-label="Komposisi metode pembayaran">
                            <circle cx="80" cy="80" r="62" fill="none" stroke="var(--surface-3)" stroke-width="22" />
                            <circle v-for="a in arcs" :key="a.key" cx="80" cy="80" r="62" fill="none" :stroke="a.color" stroke-width="22" :stroke-dasharray="a.dash" :stroke-dashoffset="a.offset" transform="rotate(-90 80 80)" />
                            <text x="80" y="78" text-anchor="middle" class="donut-center">{{ short(methodTotal) }}</text>
                            <text x="80" y="95" text-anchor="middle" class="donut-sub">total masuk</text>
                        </svg>
                        <div class="donut-legend"><div v-for="a in arcs" :key="a.key"><i :style="{ background: a.color }"></i><span>{{ a.label }}</span><b>{{ a.pct }}%</b></div></div>
                    </div>
                    <div v-else class="dash-empty"><b>Belum ada pembayaran</b><span>Komposisi muncul setelah ada transaksi.</span></div>
                </div>
            </section>
            <section>
                <h2 class="dash-title">Aktivitas mingguan</h2>
                <div class="card chart-card">
                    <div class="chart-legend-row"><span><i style="background:var(--primary)"></i>Omzet</span><span><i style="background:var(--teal)"></i>Untung kotor</span></div>
                    <svg :viewBox="`0 0 ${W} ${H}`" class="svg-chart" role="img" aria-label="Omzet dan untung kotor tujuh hari terakhir">
                        <g v-for="t in ticks" :key="t"><line class="grid" :x1="padL" :x2="W" :y1="yOf(t)" :y2="yOf(t)" /><text :x="padL - 8" :y="yOf(t) + 4" text-anchor="end">{{ short(t) }}</text></g>
                        <g v-for="b in bars" :key="b.label + b.x" class="bar">
                            <title>{{ b.title }}</title>
                            <rect class="bar-a" :x="b.ax" :y="padT + plotH - b.ah" :width="b.bw" :height="b.ah" rx="6" />
                            <rect class="bar-b" :x="b.bx" :y="padT + plotH - b.bh" :width="b.bw" :height="b.bh" rx="6" />
                            <text :x="b.x" :y="H - 8" text-anchor="middle">{{ b.label }}</text>
                        </g>
                    </svg>
                </div>
            </section>
        </div>

        <div class="dash-grid dash-row-bot">
            <section>
                <h2 class="dash-title">Pintasan cepat</h2>
                <div class="card quick-card">
                    <div class="quick-avatars">
                        <a class="quick-avatar" :href="urls.pos"><span class="c1"><Icon name="pos" /></span>Kasir</a>
                        <a class="quick-avatar" :href="urls.products"><span class="c2"><Icon name="box" /></span>Produk</a>
                        <a class="quick-avatar" :href="urls.customers"><span class="c3"><Icon name="users" /></span>Pelanggan</a>
                        <a class="quick-avatar" :href="urls.shifts"><span class="c4"><Icon name="clock" /></span>Shift</a>
                        <a class="quick-avatar" :href="urls.features"><span class="c5"><Icon name="grid" /></span>Fitur</a>
                    </div>
                </div>
                <h2 class="dash-title" style="margin-top:22px">Perlu diperiksa <a :href="urls.products">Persediaan</a></h2>
                <div class="card stock-mini">
                    <div v-for="s in lowStock" :key="s.name + s.sku" class="stock-row">
                        <span class="stock-mark">{{ s.name.slice(0, 1).toUpperCase() }}</span>
                        <span class="tx-copy"><b>{{ s.name }}</b><small>{{ s.sku || 'Tanpa SKU' }}</small></span>
                        <span class="stock-qty">{{ nf.format(s.qty) }}<small>tersisa</small></span>
                    </div>
                    <div v-if="!lowStock.length" class="dash-empty"><span class="ok"><Icon name="check" /></span><b>Stok aman</b><span>Belum ada produk yang menipis.</span></div>
                </div>
            </section>
            <section>
                <h2 class="dash-title">Tren omzet 30 hari</h2>
                <div class="card chart-card">
                    <svg :viewBox="`0 0 ${TW} ${TH}`" class="svg-chart" role="img" aria-label="Tren omzet 30 hari">
                        <defs><linearGradient id="trendFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--primary)" stop-opacity=".28" /><stop offset="1" stop-color="var(--primary)" stop-opacity="0" /></linearGradient></defs>
                        <g v-for="i in 4" :key="i"><line class="grid" :x1="tPadL" :x2="TW" :y1="ty((trendMax / 3) * (i - 1))" :y2="ty((trendMax / 3) * (i - 1))" /><text :x="tPadL - 8" :y="ty((trendMax / 3) * (i - 1)) + 4" text-anchor="end">{{ short((trendMax / 3) * (i - 1)) }}</text></g>
                        <path :d="areaPath" fill="url(#trendFill)" />
                        <path :d="linePath" fill="none" stroke="var(--primary)" stroke-width="3" stroke-linecap="round" />
                        <text v-for="i in [0, 7, 14, 21, 29]" :key="i" :x="tx(i)" :y="TH - 6" text-anchor="middle">{{ trend[i]?.label }}</text>
                    </svg>
                </div>
            </section>
        </div>
    </div>
</template>
