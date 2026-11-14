/** Preferencia visual del navegador, compartida entre páginas y pestañas. */
const clave = 'clinic.theme';
const sistema = window.matchMedia('(prefers-color-scheme: dark)');
const valido = valorDato => valorDato === 'dark' || valorDato === 'light';
let preferencia = null;
try {
    const guardadoLocal = localStorage.getItem(clave);
    if (valido(guardadoLocal)) preferencia = guardadoLocal;
} catch { /* El selector también funciona cuando el navegador bloquea el almacenamiento. */ }

function aplicarTema() {
    const oscuro = (preferencia || (sistema.matches ? 'dark' : 'light')) === 'dark';
    document.documentElement.dataset.theme = oscuro ? 'dark' : 'light';
    document.querySelectorAll('[data-theme-toggle]').forEach(boton => {
        const etiqueta = `Activar modo ${oscuro ? 'claro' : 'oscuro'}`;
        boton.setAttribute('aria-pressed', String(oscuro));
        boton.setAttribute('aria-label', etiqueta);
        boton.title = etiqueta;
        boton.querySelector('[data-theme-label]').textContent = `Modo ${oscuro ? 'claro' : 'oscuro'}`;
    });
}

document.addEventListener('click', evento => {
    if (!evento.target.closest('[data-theme-toggle]')) return;
    preferencia = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(clave, preferencia); } catch {}
    aplicarTema();
});
sistema.addEventListener('change', () => { if (!preferencia) aplicarTema(); });
window.addEventListener('storage', evento => {
    if (evento.key !== clave && evento.key !== null) return;
    preferencia = valido(evento.newValue) ? evento.newValue : null;
    aplicarTema();
});
aplicarTema();
