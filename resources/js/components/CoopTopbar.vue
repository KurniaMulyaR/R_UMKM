<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import Icon from './Icon.vue';
import { currentTheme, setTheme } from '../theme.js';

const props = defineProps({
    crumb: { type: String, default: 'Ringkasan' },
    dateLabel: { type: String, default: '' },
    links: { type: Array, default: () => [] },
    userName: { type: String, default: '' },
    lowStockCount: { type: Number, default: 0 },
    productsUrl: { type: String, default: '/products' },
});

const theme = currentTheme;

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
            <button class="mobile-menu" type="button" aria-label="Buka menu" aria-controls="coop-sidebar" @click="toggleMenu"><Icon name="menu" /></button>
            <div class="breadcrumb"><span class="breadcrumb-root">R Kasir</span><span class="breadcrumb-divider">/</span><b>{{ crumb }}</b></div>
        </div>
        <div class="topbar-right">
            <button class="global-search-trigger" type="button" aria-label="Cari menu" @click="openSearch">
                <Icon name="search" /><span class="search-trigger-label">Cari menu</span><kbd>Ctrl K</kbd>
            </button>
            <span class="today-label">{{ dateLabel }}</span>
            <div class="theme-toggle" role="group" aria-label="Tema tampilan">
                <button type="button" :class="{ on: theme === 'light' }" aria-label="Mode terang" title="Mode terang" @click="setTheme('light')"><Icon name="sun" /></button>
                <button type="button" :class="{ on: theme === 'dark' }" aria-label="Mode gelap" title="Mode gelap" @click="setTheme('dark')"><Icon name="moon" /></button>
            </div>
            <a class="round-btn hide-sm" :href="productsUrl" aria-label="Stok menipis" title="Stok menipis"><Icon name="bell" /><i v-if="lowStockCount" class="dot"></i></a>
            <span class="topbar-avatar" :title="userName">{{ (userName || 'R').slice(0, 1).toUpperCase() }}</span>
        </div>
    </header>

    <Transition name="search-fade">
        <div v-if="isSearchOpen" class="global-search-backdrop" @click.self="closeSearch">
            <section class="global-search-panel" role="dialog" aria-modal="true" aria-label="Cari halaman">
                <label class="global-search-input">
                    <Icon name="search" />
                    <input ref="searchInput" v-model="searchQuery" type="search" placeholder="Cari menu atau halaman..." autocomplete="off">
                    <button type="button" aria-label="Tutup pencarian" @click="closeSearch">Esc</button>
                </label>
                <div class="search-results-label">{{ searchQuery ? 'HASIL PENCARIAN' : 'MENU CEPAT' }}</div>
                <div v-if="searchResults.length" class="global-search-results">
                    <a v-for="link in searchResults" :key="link.href" :href="link.href" class="global-search-result" @click="closeSearch">
                        <span class="result-icon" aria-hidden="true"><Icon :name="link.icon" /></span>
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
