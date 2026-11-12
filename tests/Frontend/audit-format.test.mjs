import test from 'node:test';
import assert from 'node:assert/strict';
import {auditLabel, auditValue, auditRecord} from '../../resources/js/lib/audit-format.js';

test('auditoría traduce cambios y conserva texto libre sin alterar motivos', () => {
    assert.equal(auditLabel('specialties_assigned'), 'Asignación de especialidades');
    assert.equal(auditValue('weekday', 1), 'Lunes');
    assert.equal(auditValue('complimentary', false), 'No');
    assert.equal(auditValue('status', null), 'Sin valor');
    assert.equal(auditValue('reason', 'Motivo escrito por recepción'), 'Motivo escrito por recepción');
    assert.match(auditValue('fee_minor', 12550), /125[,.]50 BOB/);
    assert.equal(auditValue('specialties', [{id: 3, name: 'Pediatría'}]), 'Pediatría (#3)');
});

test('auditoría identifica la cita con nombres y horario guardados en el evento', () => {
    assert.equal(auditRecord({details:{record:{date:'2026-11-12',start_time:'09:00',end_time:'09:30'},related:{patient_id:{label:'Ana'},doctor_id:{label:'Daniel'},specialty_id:{label:'Pediatría'}}}}), 'Ana · Daniel · Pediatría · 2026-11-12 · 09:00–09:30');
    assert.equal(auditRecord({}), '');
});
