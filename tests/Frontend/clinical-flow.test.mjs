import test from 'node:test';
import assert from 'node:assert/strict';
import {appointmentStage,canReceive,canFinish,shiftDate} from '../../resources/js/lib/clinical-flow.js';

test('la consulta requiere llegada, confirmación y aptitud de atención del servidor',()=>{
    const date='2026-11-09',a={date,status:'Programada',attendance_at:null,care_ready:false};
    assert.equal(canReceive(a,date),true);assert.equal(canFinish(a,date),false);
    Object.assign(a,{status:'Confirmada',attendance_at:'2026-11-09T08:00:00',care_ready:true});
    assert.equal(appointmentStage(a).key,'waiting');assert.equal(canReceive(a,date),false);assert.equal(canFinish(a,date),true);
    assert.equal(canFinish({...a,care_ready:false},date),false);
    assert.equal(canFinish({...a,requires_arrival_payment:true},date),false);
    assert.equal(canFinish(a,'2026-11-10'),false);
});
test('los estados terminales no habilitan llegada ni consulta aunque conserven datos de ingreso',()=>{
    for(const status of ['Cancelada','Inasistencia','Atendida']){
        const a={status,date:'2026-11-09',attendance_at:'2026-11-09T08:00:00',care_ready:true};
        assert.equal(canReceive(a,a.date),false);assert.equal(canFinish(a,a.date),false);
        assert.notEqual(appointmentStage(a).key,'waiting');
    }
    assert.equal(shiftDate('2026-12-31',1),'2027-01-01');
});
