import test from 'node:test';
import assert from 'node:assert/strict';
import {numeroWhatsApp, mensajeReprogramacion, enlaceReprogramacion} from '../../resources/js/lib/whatsapp.js';

test('WhatsApp normaliza móviles bolivianos y números internacionales explícitos', () => {
    for(const telefono of ['70000000', '7 000-0000', '59170000000', '+591 (700) 00000', '0059170000000']) {
        assert.equal(numeroWhatsApp(telefono), '59170000000');
    }
    assert.equal(numeroWhatsApp('+34 612 345 678'), '34612345678');
    for(const telefono of [null, '', '12345678', '70000000 ext 1', 'javascript:alert(1)', '+591+70000000', '+1234567890123456']) {
        assert.equal(numeroWhatsApp(telefono), null);
    }
});

test('WhatsApp prepara la reprogramación con el horario confirmado y codifica el mensaje', () => {
    const cita = {id:42,date:'2030-11-12',start_time:'12:00:00',end_time:'12:30:00',
        patient:{first_name:'María & Ana',last_name:'Pérez',phone:'70000000'},doctor:{first_name:'Sofía',last_name:'Médica'},
        reason:'Información clínica que no debe enviarse'};
    const mensaje=mensajeReprogramacion(cita);
    assert.match(mensaje, /María & Ana Pérez/);
    assert.match(mensaje, /12\/11\/2030/);
    assert.match(mensaje, /12:00–12:30/);
    assert.match(mensaje, /Santa Cruz, Bolivia/);
    assert.doesNotMatch(mensaje, /Información clínica/);
    const enlace=new URL(enlaceReprogramacion(cita));
    assert.equal(enlace.origin, 'https://wa.me');
    assert.equal(enlace.pathname, '/59170000000');
    assert.equal(enlace.searchParams.get('text'), mensaje);
    assert.equal(enlaceReprogramacion({...cita,patient:{...cita.patient,phone:''}}), null);
});
