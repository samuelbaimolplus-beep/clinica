/** Cliente HTTP: CSRF, sesión y errores. Nunca reenvía automáticamente una escritura fallida. */
/** Cliente de la API de sesión. Una operación fallida nunca se reenvía automáticamente. */
export function createApiClient({getToken, setToken, onUnauthorized, fetchImpl = (...args) => fetch(...args), timeoutMs = 20000}) {
    /** Interpreta JSON y ofrece un error legible si la respuesta no tiene el formato esperado. */
    async function readResponse(response) {
        return response.json().catch(error => {
            if (error.name === 'AbortError') throw error;
            return {message: 'No se pudo interpretar la respuesta del servidor.'};
        });
    }

    async function request(url, options) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        try {
            const response = await fetchImpl(url, {...options, signal: controller.signal});
            return {response, data: await readResponse(response)};
        } catch (error) {
            if (controller.signal.aborted) {
                throw new Error(options.method && options.method !== 'GET'
                    ? 'El servidor tardó demasiado en responder. Compruebe si el cambio se guardó antes de intentarlo nuevamente.'
                    : 'El servidor tardó demasiado en responder. Compruebe que PostgreSQL esté disponible e intente nuevamente.');
            }
            throw error;
        } finally { clearTimeout(timer); }
    }

    /** Adopta el CSRF renovado por el servidor para las próximas solicitudes. */
    function updateToken(data) {
        if (data.csrf) setToken(data.csrf);
    }

    /** Renueva CSRF y comprueba si la sesión sigue activa, sin repetir la operación original. */
    async function renewSession() {
        const {response, data: session} = await request('/api/session', {
            credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'},
        });
        if (!response.ok || !session.csrf) {
            throw new Error('No se pudo renovar la sesión. Recargue la página.');
        }
        updateToken(session);
        if (!session.user) await onUnauthorized(session.message || 'La sesión caducó. Inicie sesión nuevamente.');
        return session;
    }

    return async function api(path, method = 'GET', body) {
        const {response, data} = await request(`/api/${path}`, {
            method, credentials: 'same-origin', cache: 'no-store',
            headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getToken()},
            ...(body !== undefined ? {body: JSON.stringify(body)} : {}),
        });
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
