<script>
    (function () {
        var theme = 'light';
        try {
            var saved = localStorage.getItem('rkasir-theme');
            theme = saved || (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        } catch (e) {}
        document.documentElement.setAttribute('data-theme', theme);
    })();
</script>
