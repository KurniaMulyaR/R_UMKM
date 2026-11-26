import { createApp } from 'vue';
import CoopSidebar from './components/CoopSidebar.vue';
import CoopTopbar from './components/CoopTopbar.vue';
import CoopShowcase from './components/CoopShowcase.vue';
import FeatureCatalog from './components/FeatureCatalog.vue';
import PosWorkspace from './components/PosWorkspace.vue';
import DashboardOverview from './components/DashboardOverview.vue';
import './product-actions.js';

const ui = window.rKasirUi ?? {};

const sidebar = document.getElementById('coop-sidebar');
if (sidebar) createApp(CoopSidebar, ui.sidebar ?? {}).mount(sidebar);

const topbar = document.getElementById('coop-topbar');
if (topbar) createApp(CoopTopbar, ui.topbar ?? {}).mount(topbar);

const showcase = document.getElementById('coop-showcase');
if (showcase) createApp(CoopShowcase, { mode: showcase.dataset.mode ?? 'login' }).mount(showcase);

const featureHost = document.getElementById('feature-catalog');
const featureBootstrap = document.getElementById('feature-bootstrap');
if (featureHost && featureBootstrap) {
    createApp(FeatureCatalog, JSON.parse(featureBootstrap.textContent)).mount(featureHost);
}

const posHost = document.getElementById('pos-app');
const posBootstrap = document.getElementById('pos-bootstrap');
if (posHost && posBootstrap) {
    createApp(PosWorkspace, JSON.parse(posBootstrap.textContent)).mount(posHost);
}

const dashHost = document.getElementById('dashboard-app');
const dashBootstrap = document.getElementById('dashboard-bootstrap');
if (dashHost && dashBootstrap) {
    createApp(DashboardOverview, JSON.parse(dashBootstrap.textContent)).mount(dashHost);
}
