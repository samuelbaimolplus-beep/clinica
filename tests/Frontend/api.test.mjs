import test from 'node:test';
import assert from 'node:assert/strict';
import {crearClienteApi} from '../../resources/js/lib/api.js';

for (const metodo of ['GET', 'POST']) {
    test(`limita la espera de ${metodo} sin reenviar la solicitud`, async () => {
        let calls = 0;
        const clienteApi = crearClienteApi({
            getToken: () => 'token', setToken: () => {}, onUnauthorized: () => {}, timeoutMs: 10,
            fetchImpl: (_url, {signal}) => new Promise((_resolve, reject) => {
                calls++;
                signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
            }),
        });
        await assert.rejects(clienteApi('account/profile', metodo), metodo === 'GET' ? /PostgreSQL/ : /Compruebe si el cambio se guardó/);
        assert.equal(calls, 1);
    });
}

function client(responses) {
    const calls = [];
    let token = 'original';
    let signOuts = 0;
    const mensajes = [];
    const clienteApi = crearClienteApi({
        getToken: () => token,
        setToken: valorDato => { token = valorDato; },
        onUnauthorized: async mensajeLocal => { signOuts++; mensajes.push(mensajeLocal); },
        fetchImpl: async (url, opciones) => {
            calls.push({url, options: opciones});
            assert.ok(responses.length, 'El cliente realizó una solicitud inesperada.');
            const [situacion, datos] = responses.shift();
            return {status: situacion, ok: situacion >= 200 && situacion < 300, json: async () => datos};
        },
    });
    return {api: clienteApi, calls, messages: mensajes, token: () => token, signOuts: () => signOuts};
}

test('envía CSRF y adopta el token renovado después de iniciar sesión', async () => {
    const contexto = client([[200, {csrf: 'rotated'}], [200, {user: {id: 1}}]]);
    await contexto.api('login', 'POST', {email: 'prueba@example.test', password: 'EjemploDePrueba'});
    await contexto.api('references');
    assert.equal(contexto.calls[0].options.headers['X-CSRF-TOKEN'], 'original');
    assert.equal(contexto.calls[1].options.headers['X-CSRF-TOKEN'], 'rotated');
    assert.equal(contexto.calls[1].options.cache, 'no-store');
});

test('conserva el estado HTTP para recuperar una sesión que ya estaba autenticada', async () => {
    const contexto = client([[409, {message: 'Ya tiene una sesión activa.'}]]);
    await assert.rejects(contexto.api('login', 'POST', {email: 'superadmin', password: 'EjemploDePrueba'}), fallo => fallo.status === 409);
    assert.equal(contexto.calls.length, 1);
    assert.equal(contexto.signOuts(), 0);
});

test('401 limpia la sesión local y conserva el CSRF recibido para volver a ingresar', async () => {
    const contexto = client([[401, {message: 'Sesión revocada', csrf: 'guest-token'}]]);
    await assert.rejects(contexto.api('references'), /Sesión revocada/);
    assert.equal(contexto.signOuts(), 1);
    assert.equal(contexto.token(), 'guest-token');
    assert.deepEqual(contexto.messages, ['Sesión revocada']);
});

test('419 con sesión caducada renueva CSRF y nunca repite la escritura', async () => {
    const contexto = client([[419, {}], [200, {user: null, csrf: 'renewed'}]]);
    await assert.rejects(contexto.api('entities/patients', 'POST', {first_name: 'Ana'}), /Inicie sesión/);
    assert.deepEqual(contexto.calls.map(call => call.url), ['/api/entities/patients', '/api/session']);
    assert.equal(contexto.calls[1].options.body, undefined);
    assert.equal(contexto.signOuts(), 1);
    assert.equal(contexto.token(), 'renewed');
    assert.match(contexto.messages[0], /La sesión caducó/);
});

test('419 con sesión activa permite corregir el intento sin cerrar sesión ni reenviar datos', async () => {
    const contexto = client([[419, {}], [200, {user: {id: 1}, csrf: 'renewed'}]]);
    await assert.rejects(contexto.api('profile', 'PUT', {phone: '70000000'}), /intente nuevamente/);
    assert.equal(contexto.signOuts(), 0);
    assert.equal(contexto.calls.length, 2);
    assert.equal(contexto.token(), 'renewed');
});

test('errores de validación mantienen los mensajes de cada campo sin cerrar sesión', async () => {
    const errores = {current_password: ['Contraseña actual incorrecta']};
    const contexto = client([[422, {message: 'Datos inválidos', errors: errores}]]);
    await assert.rejects(contexto.api('profile/password', 'PUT', {}), fallo => {
        assert.deepEqual(fallo.errors, errores);
        return true;
    });
    assert.equal(contexto.signOuts(), 0);
    assert.equal(contexto.calls.length, 1);
});

test('un fallo al renovar la sesión no reenvía la operación original', async () => {
    const contexto = client([[419, {}], [503, {message: 'Sin servicio'}]]);
    await assert.rejects(contexto.api('profile/password', 'PUT', {}), /No se pudo renovar/);
    assert.equal(contexto.calls.length, 2);
    assert.equal(contexto.token(), 'original');
});
