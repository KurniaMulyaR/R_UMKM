<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    businessName: { type: String, default: 'Usaha Bersama' },
    outletName: { type: String, default: 'Outlet utama' },
    userName: { type: String, default: 'Pengguna' },
    roleName: { type: String, default: 'Anggota' },
    logoutUrl: { type: String, default: '/logout' },
    logoutToken: { type: String, default: '' },
    links: { type: Array, default: () => [] },
});

const groups = computed(() => {
    const groupedLinks = new Map();
    props.links.forEach((link) => {
        if (!groupedLinks.has(link.group)) groupedLinks.set(link.group, []);
        groupedLinks.get(link.group).push(link);
    });
    return [...groupedLinks].map(([name, links], index) => ({ name, links, id: `sidebar-nav-group-${index}` }));
});
const openGroup = ref('');
const isCollapsed = ref(false);
const isMobileNav = ref(false);

function isGroupOpen(name) {
    return (!isMobileNav.value && isCollapsed.value) || openGroup.value === name;
}

function toggleGroup(name) {
    openGroup.value = openGroup.value === name ? '' : name;
}

function closeMenu() {
    document.body.classList.remove('menu-open');
}

function handleEscape(event) {
    if (event.key === 'Escape') closeMenu();
}

function toggleCollapsed() {
    isCollapsed.value = !isCollapsed.value;
    document.body.classList.toggle('nav-collapsed', isCollapsed.value && !isMobileNav.value);
    window.localStorage.setItem('rkasir-nav-collapsed', isCollapsed.value ? '1' : '0');
}

function syncNavViewport() {
    isMobileNav.value = window.matchMedia('(max-width: 880px)').matches;
    document.body.classList.toggle('nav-collapsed', isCollapsed.value && !isMobileNav.value);
}

onMounted(() => {
    isCollapsed.value = window.localStorage.getItem('rkasir-nav-collapsed') === '1';
    syncNavViewport();
    openGroup.value = props.links.find((link) => link.active)?.group ?? groups.value[0]?.name ?? '';
    window.addEventListener('keydown', handleEscape);
    window.addEventListener('resize', syncNavViewport);
});
onUnmounted(() => {
    window.removeEventListener('keydown', handleEscape);
    window.removeEventListener('resize', syncNavViewport);
});
</script>

<template>
    <aside class="sidebar">
        <div class="brand-row">
            <a class="brand" href="/dashboard" aria-label="R Kasir, ringkasan usaha">
                <span class="brand-mark"><span>R</span></span>
                <span class="brand-copy"><b>R Kasir</b><small>Koperasi & usaha lokal</small></span>
            </a>
            <button class="sidebar-collapse" :class="{ 'is-collapsed': isCollapsed }" type="button" :aria-label="isCollapsed ? 'Lebarkan menu' : 'Ciutkan menu'" :title="isCollapsed ? 'Lebarkan menu' : 'Ciutkan menu'" :aria-pressed="isCollapsed" @click="toggleCollapsed"><Icon name="chevronLeft" /></button>
        </div>

        <div class="store-switch">
            <span class="store-emblem" aria-hidden="true"><Icon name="pos" /></span>
            <span class="store-copy"><small>RUANG USAHA</small><b>{{ businessName }}</b><span>{{ outletName }}</span></span>
            <span class="chevron" aria-hidden="true"><Icon name="chevronDown" /></span>
        </div>

        <div v-for="group in groups" :key="group.name" class="nav-group" :class="{ 'is-open': isGroupOpen(group.name) }">
            <button class="nav-label nav-label-toggle" type="button" :aria-expanded="isGroupOpen(group.name)" :aria-controls="group.id" @click="toggleGroup(group.name)">
                <span>{{ group.name }}</span><Icon class="group-chevron" name="chevronDown" />
            </button>
            <nav v-show="isGroupOpen(group.name)" :id="group.id" class="nav-list" :aria-label="group.name">
                <a v-for="link in group.links" :key="link.label" :href="link.href" class="nav-link" :class="{ active: link.active }" :aria-current="link.active ? 'page' : undefined" :title="link.label" @click="closeMenu">
                    <span class="nav-icon" aria-hidden="true"><Icon :name="link.icon" /></span>
                    <span>{{ link.label }}</span>
                    <kbd v-if="link.shortcut">{{ link.shortcut }}</kbd>
                </a>
            </nav>
        </div>

        <div class="sidebar-bottom">
            <div class="help-card"><span class="help-mark" aria-hidden="true"><Icon name="spark" /></span><div><b>Usaha tumbuh bersama</b><span>Catatan kas dan stok tersimpan rapi setiap hari.</span></div></div>
            <div class="profile-row">
                <span class="avatar">{{ userName.slice(0, 1).toUpperCase() }}</span>
                <span class="profile-name"><b>{{ userName }}</b><small>{{ roleName }}</small></span>
                <form method="post" :action="logoutUrl"><input type="hidden" name="_token" :value="logoutToken"><button class="icon-button" title="Keluar" aria-label="Keluar"><Icon name="logout" /></button></form>
            </div>
        </div>
    </aside>
</template>
