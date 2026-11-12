import test from 'node:test';
import assert from 'node:assert/strict';
import {createApiClient} from '../../resources/js/lib/api.js';

for (const method of ['GET', 'POST']) {
    test(`limita la espera de ${method} sin reenviar la solicitud`, async () => {
        let calls = 0;
        const api = createApiClient({
            getToken: () => 'token', setToken: () => {}, onUnauthorized: () => {}, timeoutMs: 10,
            fetchImpl: (_url, {signal}) => new Promise((_resolve, reject) => {
                calls++;
                signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
            }),
        });
        await assert.rejects(api('account/profile', method), method === 'GET' ? /PostgreSQL/ : /Compruebe si el cambio se guardó/);
        assert.equal(calls, 1);
    });
}

function client(responses) {
    const calls = [];
    let token = 'original';
    let signOuts = 0;
    const messages = [];
    const api = createApiClient({
        getToken: () => token,
        setToken: value => { token = value; },
        onUnauthorized: async message => { signOuts++; messages.push(message); },
        fetchImpl: async (url, options) => {
            calls.push({url, options});
            assert.ok(responses.length, 'El cliente realizó una solicitud inesperada.');
            const [status, data] = responses.shift();
            return {status, ok: status >= 200 && status < 300, json: async () => data};
        },
    });
    return {api, calls, messages, token: () => token, signOuts: () => signOuts};
}

test('envía CSRF y adopta el token renovado después de iniciar sesión', async () => {
    const context = client([[200, {csrf: 'rotated'}], [200, {user: {id: 1}}]]);
    await context.api('login', 'POST', {email: 'prueba@example.test', password: 'EjemploDePrueba'});
    await context.api('references');
    assert.equal(context.calls[0].options.headers['X-CSRF-TOKEN'], 'original');
    assert.equal(context.calls[1].options.headers['X-CSRF-TOKEN'], 'rotated');
    assert.equal(context.calls[1].options.cache, 'no-store');
});

test('conserva el estado HTTP para recuperar una sesión que ya estaba autenticada', async () => {
    const context = client([[409, {message: 'Ya tiene una sesión activa.'}]]);
    await assert.rejects(context.api('login', 'POST', {email: 'superadmin', password: 'EjemploDePrueba'}), error => error.status === 409);
    assert.equal(context.calls.length, 1);
    assert.equal(context.signOuts(), 0);
});

test('401 limpia la sesión local y conserva el CSRF recibido para volver a ingresar', async () => {
    const context = client([[401, {message: 'Sesión revocada', csrf: 'guest-token'}]]);
    await assert.rejects(context.api('references'), /Sesión revocada/);
    assert.equal(context.signOuts(), 1);
    assert.equal(context.token(), 'guest-token');
    assert.deepEqual(context.messages, ['Sesión revocada']);
});

test('419 con sesión caducada renueva CSRF y nunca repite la escritura', async () => {
    const context = client([[419, {}], [200, {user: null, csrf: 'renewed'}]]);
    await assert.rejects(context.api('entities/patients', 'POST', {first_name: 'Ana'}), /Inicie sesión/);
    assert.deepEqual(context.calls.map(call => call.url), ['/api/entities/patients', '/api/session']);
    assert.equal(context.calls[1].options.body, undefined);
    assert.equal(context.signOuts(), 1);
    assert.equal(context.token(), 'renewed');
    assert.match(context.messages[0], /La sesión caducó/);
});

test('419 con sesión activa permite corregir el intento sin cerrar sesión ni reenviar datos', async () => {
    const context = client([[419, {}], [200, {user: {id: 1}, csrf: 'renewed'}]]);
    await assert.rejects(context.api('profile', 'PUT', {phone: '70000000'}), /intente nuevamente/);
    assert.equal(context.signOuts(), 0);
    assert.equal(context.calls.length, 2);
    assert.equal(context.token(), 'renewed');
});

test('errores de validación mantienen los mensajes de cada campo sin cerrar sesión', async () => {
    const errors = {current_password: ['Contraseña actual incorrecta']};
    const context = client([[422, {message: 'Datos inválidos', errors}]]);
    await assert.rejects(context.api('profile/password', 'PUT', {}), error => {
        assert.deepEqual(error.errors, errors);
        return true;
    });
    assert.equal(context.signOuts(), 0);
    assert.equal(context.calls.length, 1);
});

test('un fallo al renovar la sesión no reenvía la operación original', async () => {
    const context = client([[419, {}], [503, {message: 'Sin servicio'}]]);
    await assert.rejects(context.api('profile/password', 'PUT', {}), /No se pudo renovar/);
    assert.equal(context.calls.length, 2);
    assert.equal(context.token(), 'original');
});
