/** Cliente HTTP: CSRF, sesión y errores. Nunca reenvía automáticamente una escritura fallida. */
/** Cliente de la API de sesión. Una operación fallida nunca se reenvía automáticamente. */
export function crearClienteApi({getToken: obtenerToken, setToken: establecerToken, onUnauthorized: alDenegarAcceso, fetchImpl: ejecutarPeticion = (...argumentos) => fetch(...argumentos), timeoutMs: tiempoLimiteMs = 20000}) {
    /** Interpreta JSON y ofrece un error legible si la respuesta no tiene el formato esperado. */
    async function leerRespuesta(respuesta) {
        return respuesta.json().catch(fallo => {
            if (fallo.name === 'AbortError') throw fallo;
            return {message: 'No se pudo interpretar la respuesta del servidor.'};
        });
    }

    async function peticion(url, opciones) {
        const controlador = new AbortController();
        const temporizador = setTimeout(() => controlador.abort(), tiempoLimiteMs);
        try {
            const respuesta = await ejecutarPeticion(url, {...opciones, signal: controlador.signal});
            return {response: respuesta, data: await leerRespuesta(respuesta)};
        } catch (fallo) {
            if (controlador.signal.aborted) {
                throw new Error(opciones.method && opciones.method !== 'GET'
                    ? 'El servidor tardó demasiado en responder. Compruebe si el cambio se guardó antes de intentarlo nuevamente.'
                    : 'El servidor tardó demasiado en responder. Compruebe que PostgreSQL esté disponible e intente nuevamente.');
            }
            throw fallo;
        } finally { clearTimeout(temporizador); }
    }

    /** Adopta el CSRF renovado por el servidor para las próximas solicitudes. */
    function actualizarToken(datos) {
        if (datos.csrf) establecerToken(datos.csrf);
    }

    /** Renueva CSRF y comprueba si la sesión sigue activa, sin repetir la operación original. */
    async function renovarSesion() {
        const {response: respuesta, data: sesion} = await peticion('/api/session', {
            credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'},
        });
        if (!respuesta.ok || !sesion.csrf) {
            throw new Error('No se pudo renovar la sesión. Recargue la página.');
        }
        actualizarToken(sesion);
        if (!sesion.user) await alDenegarAcceso(sesion.message || 'La sesión caducó. Inicie sesión nuevamente.');
        return sesion;
    }

    return async function clienteApi(ruta, metodo = 'GET', contenido) {
        const {response: respuesta, data: datos} = await peticion(`/api/${ruta}`, {
            method: metodo, credentials: 'same-origin', cache: 'no-store',
            headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': obtenerToken()},
            ...(contenido !== undefined ? {body: JSON.stringify(contenido)} : {}),
        });
        actualizarToken(datos);
        if (respuesta.status === 419) {
            const sesion = await renovarSesion();
            throw new Error(sesion.user
                ? 'La protección de la sesión se renovó. Revise los datos e intente nuevamente.'
                : 'La sesión caducó. Inicie sesión nuevamente.');
        }
        if (respuesta.status === 401) await alDenegarAcceso(datos.message || 'Debe iniciar sesión.');
        if (!respuesta.ok) {
            const fallo = new Error(datos.message || 'No se pudo completar la operación.');
            fallo.status = respuesta.status;
            fallo.errors = datos.errors;
            fallo.data = datos;
            throw fallo;
        }
        return datos;
    };
}
