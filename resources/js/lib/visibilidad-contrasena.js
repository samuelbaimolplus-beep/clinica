/** Alterna visibilidad de una contraseña únicamente por acción expresa de su titular. */
const ojo = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
const ojoOculto = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m3 3 18 18M10.6 5.1A12 12 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-3 3.9M6.2 6.2A19 19 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.8-1.7M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

export function botonVisibilidadContrasena(idEntrada) {
    return `<button type="button" class="password-toggle" data-password-toggle aria-controls="${idEntrada}" aria-label="Mostrar contraseña" aria-pressed="false" title="Mostrar contraseña">${ojo}</button>`;
}

function establecerVisibilidad(entrada, boton, visible) {
    entrada.type = visible ? 'text' : 'password';
    boton.setAttribute('aria-pressed', String(visible));
    boton.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    boton.title = boton.getAttribute('aria-label');
    boton.innerHTML = visible ? ojoOculto : ojo;
}

document.addEventListener('click', evento => {
    const boton = evento.target.closest('[data-password-toggle]');
    if (!boton) return;
    const entrada = boton.closest('.password-control')?.querySelector('input');
    if (!entrada) return;
    const inicioFranja = entrada.selectionStart;
    const fin = entrada.selectionEnd;
    establecerVisibilidad(entrada, boton, entrada.type === 'password');
    entrada.focus({preventScroll: true});
    entrada.setSelectionRange(inicioFranja, fin);
});

document.addEventListener('reset', evento => {
    evento.target.querySelectorAll('[data-password-toggle]').forEach(boton => {
        const entrada = boton.closest('.password-control')?.querySelector('input');
        if (entrada) establecerVisibilidad(entrada, boton, false);
    });
});
