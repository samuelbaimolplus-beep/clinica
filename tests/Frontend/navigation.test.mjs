import test from 'node:test';
import assert from 'node:assert/strict';
import {visibleNavigation, homeView, navigationGroups} from '../../resources/js/lib/navigation.js';

test('el paciente entra directamente a reservar y solo ve las funciones autorizadas', () => {
    const allowed = ['CU01','CU02','CU08','CU10','CU15','CU18','CU20'];
    assert.equal(homeView('Paciente', allowed), 'CU18');
    const links = visibleNavigation('Paciente', allowed).flatMap(([,links]) => links.map(([id]) => id));
    assert.ok(links.includes('CU18'));
    assert.ok(!links.includes('CU03'));
    assert.ok(!links.includes('CU26'));
    assert.ok(!links.includes('CU14'));
});

test('cada rol tiene un inicio permitido y no se inventan enlaces sin permiso', () => {
    for (const [role, home] of [['Administrador','CU03'],['Recepcionista','CU06'],['Médico','CU12'],['Superadmin','CU03']]) {
        assert.equal(homeView(role, ['CU01','CU02',home]), home);
    }
    assert.equal(homeView('Paciente', ['CU01','CU02']), 'CU02');
    assert.deepEqual(visibleNavigation('Desconocido', []), []);
});

test('la supervisión tiene enlaces para todos los módulos navegables sin duplicados', () => {
    const ids = navigationGroups.flatMap(([,links]) => links.map(([id]) => id));
    assert.equal(ids.length, new Set(ids).size);
    for(let n=2;n<=26;n++)assert.ok(ids.includes(`CU${String(n).padStart(2,'0')}`));
});
