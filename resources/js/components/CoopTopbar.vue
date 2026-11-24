<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    crumb: { type: String, default: 'Ringkasan' },
    dateLabel: { type: String, default: '' },
    links: { type: Array, default: () => [] },
});

const isSearchOpen = ref(false);
const searchQuery = ref('');
const searchInput = ref(null);

const searchResults = computed(() => {
    const query = searchQuery.value.trim().toLocaleLowerCase('id');
    const links = props.links.filter((link) => link.href);
    if (!query) return links.slice(0, 6);
    return links.filter((link) => `${link.label} ${link.group}`.toLocaleLowerCase('id').includes(query)).slice(0, 8);
});

function toggleMenu() {
    document.body.classList.toggle('menu-open');
}

async function openSearch() {
    isSearchOpen.value = true;
    await nextTick();
    searchInput.value?.focus();
}

function closeSearch() {
    isSearchOpen.value = false;
    searchQuery.value = '';
}

function handleKeyboard(event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        isSearchOpen.value ? closeSearch() : openSearch();
    } else if (event.key === 'Escape') {
        closeSearch();
        document.body.classList.remove('menu-open');
    }
}

onMounted(() => window.addEventListener('keydown', handleKeyboard));
onUnmounted(() => window.removeEventListener('keydown', handleKeyboard));
</script>

<template>
    <header class="topbar">
        <div class="topbar-left">
            <button class="mobile-menu" type="button" aria-label="Buka menu" aria-controls="coop-sidebar" @click="toggleMenu">☰</button>
            <div class="breadcrumb"><span class="breadcrumb-root">R Kasir</span><span class="breadcrumb-divider">/</span><b>{{ crumb }}</b></div>
        </div>
        <div class="topbar-right">
            <button class="global-search-trigger" type="button" aria-label="Cari menu" @click="openSearch">
                <span aria-hidden="true">⌕</span><span class="search-trigger-label">Cari menu</span><kbd>Ctrl K</kbd>
            </button>
            <span class="today-label">{{ dateLabel }}</span>
            <span class="online-pill"><i></i> Tersambung</span>
        </div>
    </header>

    <Transition name="search-fade">
        <div v-if="isSearchOpen" class="global-search-backdrop" @click.self="closeSearch">
            <section class="global-search-panel" role="dialog" aria-modal="true" aria-label="Cari halaman">
                <label class="global-search-input">
                    <span aria-hidden="true">⌕</span>
                    <input ref="searchInput" v-model="searchQuery" type="search" placeholder="Cari menu atau halaman..." autocomplete="off">
                    <button type="button" aria-label="Tutup pencarian" @click="closeSearch">Esc</button>
                </label>
                <div class="search-results-label">{{ searchQuery ? 'HASIL PENCARIAN' : 'MENU CEPAT' }}</div>
                <div v-if="searchResults.length" class="global-search-results">
                    <a v-for="link in searchResults" :key="link.href" :href="link.href" class="global-search-result" @click="closeSearch">
                        <span class="result-icon" aria-hidden="true">{{ link.icon }}</span>
                        <span class="result-copy"><b>{{ link.label }}</b><small>{{ link.group }}</small></span>
                        <span class="result-enter" aria-hidden="true">↵</span>
                    </a>
                </div>
                <div v-else class="search-no-results">Menu yang cocok belum ditemukan.</div>
                <footer class="search-hint"><span>Pilih menu untuk membuka</span><span><kbd>ESC</kbd> tutup</span></footer>
            </section>
        </div>
    </Transition>
    <button class="mobile-nav-backdrop" type="button" aria-label="Tutup menu" @click="toggleMenu"></button>
</template>
