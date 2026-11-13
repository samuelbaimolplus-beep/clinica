/** Preferencia visual del navegador, compartida entre páginas y pestañas. */
const key = 'clinic.theme';
const system = window.matchMedia('(prefers-color-scheme: dark)');
const valid = value => value === 'dark' || value === 'light';
let preference = null;
try {
    const saved = localStorage.getItem(key);
    if (valid(saved)) preference = saved;
} catch { /* El selector también funciona cuando el navegador bloquea el almacenamiento. */ }

function applyTheme() {
    const dark = (preference || (system.matches ? 'dark' : 'light')) === 'dark';
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
        const label = `Activar modo ${dark ? 'claro' : 'oscuro'}`;
        button.setAttribute('aria-pressed', String(dark));
        button.setAttribute('aria-label', label);
        button.title = label;
        button.querySelector('[data-theme-label]').textContent = `Modo ${dark ? 'claro' : 'oscuro'}`;
    });
}

document.addEventListener('click', event => {
    if (!event.target.closest('[data-theme-toggle]')) return;
    preference = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(key, preference); } catch {}
    applyTheme();
});
system.addEventListener('change', () => { if (!preference) applyTheme(); });
window.addEventListener('storage', event => {
    if (event.key !== key && event.key !== null) return;
    preference = valid(event.newValue) ? event.newValue : null;
    applyTheme();
});
applyTheme();
