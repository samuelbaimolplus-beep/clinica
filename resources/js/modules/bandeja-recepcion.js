/** Avisos periódicos de nuevas solicitudes sin duplicar las operaciones de resolución. */
// CU10: aviso persistente y consulta periódica sin introducir un proveedor externo.
export function instalarBandejaRecepcion({estadoLocal, clienteApi, navegar, notificacion}) {
    const boton = document.querySelector('#reception-inbox');
    let propietario = null;
    let masReciente = null;
    let cargando = false;
    async function actualizar() {
        const usuario = estadoLocal.user;
        if (!usuario || !['Superadmin', 'Recepcionista'].includes(usuario.role)) {
            boton.hidden = true; propietario = null; masReciente = null;
            return;
        }
        if (cargando || document.hidden) return;
        if (propietario !== usuario.id) { propietario = usuario.id; masReciente = null; }
        cargando = true;
        try {
            const bandeja = await clienteApi('reception-inbox');
            if (estadoLocal.user?.id !== usuario.id) return;
            boton.hidden = bandeja.pending === 0;
            boton.textContent = `${bandeja.pending} solicitud(es) de reprogramación`;
            if (bandeja.pending > 0 && masReciente !== bandeja.latest_id) notificacion('Tiene solicitudes de reprogramación pendientes en Recepción.');
            masReciente = bandeja.latest_id;
        } catch {
            // Los errores de sesión se atienden en el cliente API; no mostrar avisos repetidos.
        } finally { cargando = false; }
    }
    boton.addEventListener('click', () => navegar('CU10'));
    setInterval(actualizar, 45000);
    document.addEventListener('visibilitychange', actualizar);
    return actualizar;
}
