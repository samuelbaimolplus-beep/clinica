{{-- Aplicar el tema antes de pintar la página evita un destello claro al abrirla. --}}
<script>
(() => {
    let theme;
    try { theme = localStorage.getItem('clinic.theme'); } catch {}
    document.documentElement.dataset.theme = theme === 'light' || theme === 'dark'
        ? theme : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
})();
</script>
@vite('resources/js/lib/theme.js')
