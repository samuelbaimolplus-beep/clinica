/** Cliente de la API de sesión. Una operación fallida nunca se reenvía automáticamente. */
export function createApiClient({getToken, setToken, onUnauthorized, fetchImpl = (...args) => fetch(...args)}) {
    async function readResponse(response) {
        return response.json().catch(() => ({message: 'No se pudo interpretar la respuesta del servidor.'}));
    }

    function updateToken(data) {
        if (data.csrf) setToken(data.csrf);
    }

    async function renewSession() {
        const response = await fetchImpl('/api/session', {
            credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'},
        });
        const session = await readResponse(response);
        if (!response.ok || !session.csrf) {
            throw new Error('No se pudo renovar la sesión. Recargue la página.');
        }
        updateToken(session);
        if (!session.user) await onUnauthorized(session.message || 'La sesión caducó. Inicie sesión nuevamente.');
        return session;
    }

    return async function api(path, method = 'GET', body) {
        const response = await fetchImpl(`/api/${path}`, {
            method, credentials: 'same-origin', cache: 'no-store',
            headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getToken()},
            ...(body !== undefined ? {body: JSON.stringify(body)} : {}),
        });
        const data = await readResponse(response);
        updateToken(data);
        if (response.status === 419) {
            const session = await renewSession();
            throw new Error(session.user
                ? 'La protección de la sesión se renovó. Revise los datos e intente nuevamente.'
                : 'La sesión caducó. Inicie sesión nuevamente.');
        }
        if (response.status === 401) await onUnauthorized(data.message || 'Debe iniciar sesión.');
        if (!response.ok) {
            const error = new Error(data.message || 'No se pudo completar la operación.');
            error.status = response.status;
            error.errors = data.errors;
            error.data = data;
            throw error;
        }
        return data;
    };
}
