import test from 'node:test';
import assert from 'node:assert/strict';
import {etiquetaAuditoria, valorAuditoria, registroAuditoria, fechaHoraAuditoria} from "../../resources/js/lib/formato-auditoria.js";

test('la bitácora convierte instantes UTC y otras zonas a la hora de Santa Cruz, incluido el cambio de día', () => {
    assert.equal(fechaHoraAuditoria('2026-11-13T20:30:45.000000Z'), '13/11/2026 16:30:45');
    assert.equal(fechaHoraAuditoria('2026-11-13T16:30:45-04:00'), '13/11/2026 16:30:45');
    assert.equal(fechaHoraAuditoria('2026-11-14T00:30:45+04:00'), '13/11/2026 16:30:45');
    assert.equal(fechaHoraAuditoria('2027-01-01T02:05:00Z'), '31/12/2026 22:05:00');
    assert.equal(fechaHoraAuditoria('2026-11-13T04:00:00Z'), '13/11/2026 00:00:00');
});

test('la bitácora conserva fechas SQL locales y fechas sin hora, y formatea los valores de los cambios', () => {
    assert.equal(fechaHoraAuditoria('2026-11-13 16:30:45'), '13/11/2026 16:30:45');
    assert.equal(fechaHoraAuditoria('2026-11-13 20:30:45+00'), '13/11/2026 16:30:45');
    assert.equal(fechaHoraAuditoria('2026-11-13'), '2026-11-13');
    assert.equal(fechaHoraAuditoria('fecha inválida'), 'fecha inválida');
    assert.equal(fechaHoraAuditoria(null), 'Sin fecha');
    assert.equal(valorAuditoria('cancelled_at', '2026-11-13T20:30:45Z'), '13/11/2026 16:30:45');
    assert.equal(valorAuditoria('birth_date', '2000-11-13'), '2000-11-13');
    assert.equal(valorAuditoria('start_time', '16:30'), '16:30');
});

test('auditoría traduce cambios y conserva texto libre sin alterar motivos', () => {
    assert.equal(etiquetaAuditoria('specialties_assigned'), 'Asignación de especialidades');
    assert.equal(valorAuditoria('weekday', 1), 'Lunes');
    assert.equal(valorAuditoria('complimentary', false), 'No');
    assert.equal(valorAuditoria('status', null), 'Sin valor');
    assert.equal(valorAuditoria('reason', 'Motivo escrito por recepción'), 'Motivo escrito por recepción');
    assert.match(valorAuditoria('fee_minor', 12550), /125[,.]50 BOB/);
    assert.equal(valorAuditoria('specialties', [{id: 3, name: 'Pediatría'}]), 'Pediatría (#3)');
});

test('auditoría identifica la cita con nombres y horario guardados en el evento', () => {
    assert.equal(registroAuditoria({details:{record:{date:'2026-11-12',start_time:'09:00',end_time:'09:30'},related:{patient_id:{label:'Ana'},doctor_id:{label:'Daniel'},specialty_id:{label:'Pediatría'}}}}), 'Ana · Daniel · Pediatría · 2026-11-12 · 09:00–09:30');
    assert.equal(registroAuditoria({}), '');
});
