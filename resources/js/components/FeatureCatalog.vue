<script setup>
import { computed, ref } from 'vue';

const props = defineProps({ modules: { type: Array, default: () => [] } });
const query = ref('');
const statusFilter = ref('Semua');
const groupFilter = ref('Semua');
const statuses = ['Semua', 'Ada', 'Sebagian', 'Belum dibangun'];
const groups = computed(() => ['Semua', ...props.modules.map((module) => module.group)]);
const allFeatures = computed(() => props.modules.flatMap((module) => module.features));
const counts = computed(() => ({
    total: allFeatures.value.length,
    ready: allFeatures.value.filter((feature) => feature.status === 'Ada').length,
    partial: allFeatures.value.filter((feature) => feature.status === 'Sebagian').length,
    planned: allFeatures.value.filter((feature) => feature.status === 'Belum dibangun').length,
}));
const visibleModules = computed(() => props.modules
    .filter((module) => groupFilter.value === 'Semua' || groupFilter.value === module.group)
    .map((module) => ({
        ...module,
        features: module.features.filter((feature) => {
            const matchesStatus = statusFilter.value === 'Semua' || feature.status === statusFilter.value;
            const matchesQuery = !query.value || `${feature.name} ${feature.summary} ${module.group}`.toLocaleLowerCase('id-ID').includes(query.value.toLocaleLowerCase('id-ID'));
            return matchesStatus && matchesQuery;
        }),
    }))
    .filter((module) => module.features.length));

function statusClass(status) {
    return status === 'Ada' ? 'ready' : status === 'Sebagian' ? 'partial' : 'planned';
}
</script>

<template>
    <div class="page-heading feature-heading">
        <div><div class="eyebrow">PETA FITUR PRD</div><h1>Modul R Kasir</h1><p>Daftar ini membedakan fitur yang sudah tersedia, baru sebagian, dan yang masih perlu dibangun.</p></div>
        <span class="feature-total"><b>{{ counts.total }}</b> kemampuan dipetakan</span>
    </div>

    <div class="feature-summary">
        <div class="card feature-stat"><span class="feature-stat-icon ready-icon">✓</span><div><small>Sudah tersedia</small><b>{{ counts.ready }}</b></div></div>
        <div class="card feature-stat"><span class="feature-stat-icon partial-icon">◐</span><div><small>Perlu dilengkapi</small><b>{{ counts.partial }}</b></div></div>
        <div class="card feature-stat"><span class="feature-stat-icon planned-icon">＋</span><div><small>Belum dibangun</small><b>{{ counts.planned }}</b></div></div>
    </div>

    <div class="card feature-toolbar">
        <label class="feature-search"><span>⌕</span><input v-model="query" placeholder="Cari modul atau kemampuan"></label>
        <label class="feature-select"><span>Status</span><select v-model="statusFilter"><option v-for="status in statuses" :key="status">{{ status }}</option></select></label>
        <label class="feature-select"><span>Kelompok</span><select v-model="groupFilter"><option v-for="group in groups" :key="group">{{ group }}</option></select></label>
    </div>

    <section v-for="module in visibleModules" :key="module.group" class="feature-section">
        <div class="feature-section-head"><h2>{{ module.group }}</h2><span>{{ module.features.length }} modul</span></div>
        <div class="feature-grid">
            <article v-for="feature in module.features" :key="feature.name" class="card feature-card">
                <div class="feature-card-top"><span class="feature-symbol">✳</span><span class="feature-state" :class="statusClass(feature.status)">{{ feature.status }}</span></div>
                <h3>{{ feature.name }}</h3>
                <p>{{ feature.summary }}</p>
            </article>
        </div>
    </section>
    <div v-if="!visibleModules.length" class="card empty-state feature-empty"><div class="empty-icon">⌕</div><b>Belum ada modul yang cocok</b><span>Coba ganti kata kunci atau filter status.</span></div>
</template>
