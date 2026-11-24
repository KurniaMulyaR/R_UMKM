<script setup>
import { computed } from 'vue';

const props = defineProps({ name: { type: String, required: true } });

const paths = {
    home: 'M3 11.5 12 4l9 7.5M5.5 10v9a1 1 0 0 0 1 1H10v-5h4v5h3.5a1 1 0 0 0 1-1v-9',
    pos: 'M4 7h16M5 7l1.2 11a2 2 0 0 0 2 1.8h7.6a2 2 0 0 0 2-1.8L19 7M9 7V5.5A2.5 2.5 0 0 1 11.5 3h1A2.5 2.5 0 0 1 15 5.5V7',
    sales: 'M4 19V9m6 10V5m6 14v-7m4 7H3',
    clock: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    box: 'm12 3 8 4.5v9L12 21l-8-4.5v-9L12 3ZM4 7.5l8 4.5 8-4.5M12 12v9',
    users: 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM20 20v-1.5a3.5 3.5 0 0 0-2.5-3.35M15.5 4.7a3.5 3.5 0 0 1 0 6.6',
    team: 'M12 3 4 6v6c0 4.5 3.2 7.8 8 9 4.8-1.2 8-4.5 8-9V6l-8-3ZM9 12l2 2 4-4',
    grid: 'M4 4h6.5v6.5H4zM13.5 4H20v6.5h-6.5zM4 13.5h6.5V20H4zM13.5 13.5H20V20h-6.5z',
    search: 'M21 21l-4.3-4.3M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z',
    bell: 'M6 9a6 6 0 1 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9ZM10 20a2 2 0 0 0 4 0',
    settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM19.4 13.5a7.7 7.7 0 0 0 0-3l2-1.5-2-3.5-2.3.9a7.5 7.5 0 0 0-2.6-1.5L14 2.5h-4l-.5 2.4a7.5 7.5 0 0 0-2.6 1.5l-2.3-.9-2 3.5 2 1.5a7.7 7.7 0 0 0 0 3l-2 1.5 2 3.5 2.3-.9a7.5 7.5 0 0 0 2.6 1.5l.5 2.4h4l.5-2.4a7.5 7.5 0 0 0 2.6-1.5l2.3.9 2-3.5-2-1.5Z',
    sun: 'M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4m11.4-11.4 1.4-1.4',
    moon: 'M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11Z',
    logout: 'M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3M16 16l4-4-4-4M20 12H9',
    plus: 'M12 5v14M5 12h14',
    arrowUp: 'M7 17 17 7M8 7h9v9',
    arrowDown: 'M7 7l10 10M17 8v9H8',
    wallet: 'M3 7a2 2 0 0 1 2-2h12v3M3 7v10a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1H5a2 2 0 0 1-2-1ZM16 13.5h.01',
    receipt: 'M6 3h12v18l-3-2-3 2-3-2-3 2V3ZM9 8h6M9 12h6',
    spark: 'M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18',
    chevronLeft: 'm15 6-6 6 6 6',
    chevronDown: 'm6 9 6 6 6-6',
    menu: 'M4 7h16M4 12h16M4 17h16',
    check: 'm5 12 4.5 4.5L19 7',
};

const d = computed(() => paths[props.name] ?? paths.grid);
</script>

<template>
    <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path :d="d" /></svg>
</template>
