<script setup>
defineProps({ mode: { type: String, default: 'login' } });
</script>

<template>
    <aside class="auth-decoration coop-showcase" aria-label="R Kasir membantu usaha tumbuh bersama">
        <div class="showcase-orbit orbit-one"></div><div class="showcase-orbit orbit-two"></div>
        <div class="showcase-sun"></div>
        <div class="showcase-content">
            <div class="showcase-kicker"><span>✳</span> BERSAMA KITA BISA</div>
            <h2>{{ mode === 'setup' ? 'Mulai usaha yang lebih tertata.' : 'Usaha kuat, tumbuh bersama.' }}</h2>
            <p>Urus kas, stok, dan penjualan dalam satu ruang kerja yang sederhana.</p>
            <div class="showcase-board">
                <div class="showcase-board-head"><span class="board-icon">↗</span><span><small>CATATAN HARI INI</small><b>Usaha lebih terpantau</b></span><span class="board-check">✓</span></div>
                <div class="board-bars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
                <div class="board-foot"><span>Kas tercatat</span><span>Stok terkendali</span><span>Rekap harian</span></div>
            </div>
            <div class="showcase-quote"><span>“</span><div><b>Rapi bersama, maju bersama.</b><small>Catatan yang baik membantu usaha berkembang.</small></div></div>
        </div>
        <span class="showcase-leaf leaf-a">✦</span><span class="showcase-leaf leaf-b">✳</span>
    </aside>
</template>
