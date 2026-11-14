import test from 'node:test';
import assert from 'node:assert/strict';
import {navegacionVisible, vistaInicio, gruposNavegacion} from "../../resources/js/lib/navegacion.js";

test('el paciente entra directamente a reservar y solo ve las funciones autorizadas', () => {
    const permitidas = ['CU01','CU02','CU08','CU10','CU15','CU18','CU20'];
    assert.equal(vistaInicio('Paciente', permitidas), 'CU18');
    const enlaces = navegacionVisible('Paciente', permitidas).flatMap(([,enlaces]) => enlaces.map(([id]) => id));
    assert.ok(enlaces.includes('CU18'));
    assert.ok(!enlaces.includes('CU03'));
    assert.ok(!enlaces.includes('CU26'));
    assert.ok(!enlaces.includes('CU14'));
});

test('cada rol tiene un inicio permitido y no se inventan enlaces sin permiso', () => {
    for (const [rolLocal, inicioLocal] of [['Administrador','CU03'],['Recepcionista','CU06'],['Médico','CU12'],['Superadmin','CU03']]) {
        assert.equal(vistaInicio(rolLocal, ['CU01','CU02',inicioLocal]), inicioLocal);
    }
    assert.equal(vistaInicio('Paciente', ['CU01','CU02']), 'CU02');
    assert.deepEqual(navegacionVisible('Desconocido', []), []);
});

test('la supervisión tiene enlaces para todos los módulos navegables sin duplicados', () => {
    const identificadores = gruposNavegacion.flatMap(([,enlaces]) => enlaces.map(([id]) => id));
    assert.equal(identificadores.length, new Set(identificadores).size);
    for(let n=2;n<=26;n++)assert.ok(identificadores.includes(`CU${String(n).padStart(2,'0')}`));
});
