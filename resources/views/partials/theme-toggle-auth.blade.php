<button type="button" class="round-btn auth-theme" id="auth-theme-toggle" aria-label="Ganti mode terang atau gelap" title="Ganti mode terang atau gelap">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11Z"/></svg>
</button>
<script>
    document.getElementById('auth-theme-toggle').addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('rkasir-theme', next); } catch (e) {}
    });
</script>
