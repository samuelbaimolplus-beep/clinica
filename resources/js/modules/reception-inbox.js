// CU10: aviso persistente y consulta periódica sin introducir un proveedor externo.
export function installReceptionInbox({state, api, navigate, toast}) {
    const button = document.querySelector('#reception-inbox');
    let owner = null;
    let latest = null;
    let loading = false;
    async function refresh() {
        const user = state.user;
        if (!user || !['Superadmin', 'Recepcionista'].includes(user.role)) {
            button.hidden = true; owner = null; latest = null;
            return;
        }
        if (loading || document.hidden) return;
        if (owner !== user.id) { owner = user.id; latest = null; }
        loading = true;
        try {
            const inbox = await api('reception-inbox');
            if (state.user?.id !== user.id) return;
            button.hidden = inbox.pending === 0;
            button.textContent = `${inbox.pending} solicitud(es) de reprogramación`;
            if (inbox.pending > 0 && latest !== inbox.latest_id) toast('Tiene solicitudes de reprogramación pendientes en Recepción.');
            latest = inbox.latest_id;
        } catch {
            // Los errores de sesión se atienden en el cliente API; no mostrar avisos repetidos.
        } finally { loading = false; }
    }
    button.addEventListener('click', () => navigate('CU10'));
    setInterval(refresh, 45000);
    document.addEventListener('visibilitychange', refresh);
    return refresh;
}
