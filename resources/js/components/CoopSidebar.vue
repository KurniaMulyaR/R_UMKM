<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
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
    return [...groupedLinks].map(([name, links]) => ({ name, links }));
});

function closeMenu() {
    document.body.classList.remove('menu-open');
}

function handleEscape(event) {
    if (event.key === 'Escape') closeMenu();
}

function toggleCollapsed() {
    const collapsed = document.body.classList.toggle('nav-collapsed');
    window.localStorage.setItem('rkasir-nav-collapsed', collapsed ? '1' : '0');
}

onMounted(() => {
    if (window.localStorage.getItem('rkasir-nav-collapsed') === '1') document.body.classList.add('nav-collapsed');
    window.addEventListener('keydown', handleEscape);
});
onUnmounted(() => window.removeEventListener('keydown', handleEscape));
</script>

<template>
    <aside class="sidebar">
        <div class="brand-row">
            <a class="brand" href="/dashboard" aria-label="R Kasir, ringkasan usaha">
                <span class="brand-mark"><span>R</span></span>
                <span class="brand-copy"><b>R Kasir</b><small>Koperasi & usaha lokal</small></span>
            </a>
            <button class="sidebar-collapse" type="button" aria-label="Ciutkan atau lebarkan navigasi" title="Ciutkan atau lebarkan navigasi" @click="toggleCollapsed"><Icon name="chevronLeft" /></button>
        </div>

        <div class="store-switch">
            <span class="store-emblem" aria-hidden="true"><Icon name="pos" /></span>
            <span class="store-copy"><small>RUANG USAHA</small><b>{{ businessName }}</b><span>{{ outletName }}</span></span>
            <span class="chevron" aria-hidden="true"><Icon name="chevronDown" /></span>
        </div>

        <div v-for="group in groups" :key="group.name" class="nav-group">
            <div class="nav-label">{{ group.name }}</div>
            <nav class="nav-list" :aria-label="group.name">
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
