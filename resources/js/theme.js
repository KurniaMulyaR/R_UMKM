import { ref } from 'vue';

const KEY = 'rkasir-theme';

function readInitial() {
    const attr = document.documentElement.getAttribute('data-theme');
    return attr === 'dark' ? 'dark' : 'light';
}

export const currentTheme = ref(readInitial());

export function setTheme(theme) {
    currentTheme.value = theme;
    document.documentElement.setAttribute('data-theme', theme);
    try { window.localStorage.setItem(KEY, theme); } catch (e) { /* storage may be blocked */ }
}
