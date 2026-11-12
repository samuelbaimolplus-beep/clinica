/** Alterna visibilidad de una contraseña únicamente por acción expresa de su titular. */
const eye = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
const eyeOff = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m3 3 18 18M10.6 5.1A12 12 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-3 3.9M6.2 6.2A19 19 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.8-1.7M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

export function passwordToggleButton(inputId) {
    return `<button type="button" class="password-toggle" data-password-toggle aria-controls="${inputId}" aria-label="Mostrar contraseña" aria-pressed="false" title="Mostrar contraseña">${eye}</button>`;
}

function setVisibility(input, button, visible) {
    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    button.title = button.getAttribute('aria-label');
    button.innerHTML = visible ? eyeOff : eye;
}

document.addEventListener('click', event => {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) return;
    const input = button.closest('.password-control')?.querySelector('input');
    if (!input) return;
    const start = input.selectionStart;
    const end = input.selectionEnd;
    setVisibility(input, button, input.type === 'password');
    input.focus({preventScroll: true});
    input.setSelectionRange(start, end);
});

document.addEventListener('reset', event => {
    event.target.querySelectorAll('[data-password-toggle]').forEach(button => {
        const input = button.closest('.password-control')?.querySelector('input');
        if (input) setVisibility(input, button, false);
    });
});
