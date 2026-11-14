import test from 'node:test';
import assert from 'node:assert/strict';
import {etapaCita,puedeRegistrarLlegada,puedeFinalizar,desplazarFecha} from "../../resources/js/lib/flujo-clinico.js";

test('la consulta requiere llegada, confirmación y aptitud de atención del servidor',()=>{
    const fechaLocal='2026-11-09',a={date: fechaLocal,status:'Programada',attendance_at:null,care_ready:false};
    assert.equal(puedeRegistrarLlegada(a,fechaLocal),true);assert.equal(puedeFinalizar(a,fechaLocal),false);
    Object.assign(a,{status:'Confirmada',attendance_at:'2026-11-09T08:00:00',care_ready:true});
    assert.equal(etapaCita(a).key,'waiting');assert.equal(puedeRegistrarLlegada(a,fechaLocal),false);assert.equal(puedeFinalizar(a,fechaLocal),true);
    assert.equal(puedeFinalizar({...a,care_ready:false},fechaLocal),false);
    assert.equal(puedeFinalizar({...a,requires_arrival_payment:true},fechaLocal),false);
    assert.equal(puedeFinalizar(a,'2026-11-10'),false);
});
test('los estados terminales no habilitan llegada ni consulta aunque conserven datos de ingreso',()=>{
    for(const situacion of ['Cancelada','Inasistencia','Atendida']){
        const a={status: situacion,date:'2026-11-09',attendance_at:'2026-11-09T08:00:00',care_ready:true};
        assert.equal(puedeRegistrarLlegada(a,a.date),false);assert.equal(puedeFinalizar(a,a.date),false);
        assert.notEqual(etapaCita(a).key,'waiting');
    }
    assert.equal(desplazarFecha('2026-12-31',1),'2027-01-01');
});
