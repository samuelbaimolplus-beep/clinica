import test from 'node:test';
import assert from 'node:assert/strict';
import {conflictingShifts,suggestedShift} from '../../resources/js/lib/schedule-planner.js';

const shifts=[{id:1,weekday:1,start_time:'08:00:00',end_time:'12:00:00'},{id:2,weekday:1,start_time:'14:00:00',end_time:'18:00:00'}];
test('un turno consecutivo no se considera superpuesto y se propone el hueco del mediodía',()=>{
    assert.deepEqual(suggestedShift(shifts,1),{start_time:'12:00',end_time:'14:00'});
    assert.equal(conflictingShifts(shifts,{weekday:'1',start_time:'12:00',end_time:'14:00'}).length,0);
});
test('el cruce identifica el turno real y no se compara el turno editado consigo mismo',()=>{
    assert.equal(conflictingShifts(shifts,{weekday:1,start_time:'11:30',end_time:'12:30'})[0].id,1);
    assert.equal(conflictingShifts(shifts,{weekday:1,start_time:'08:00',end_time:'12:00'},1).length,0);
    assert.equal(conflictingShifts(shifts,{weekday:2,start_time:'08:00',end_time:'12:00'}).length,0);
});
test('sin espacio de media hora no se propone un turno que se cruce ni una hora imposible',()=>{
    assert.deepEqual(suggestedShift([{id:1,weekday:0,start_time:'07:00',end_time:'23:30'}],0),{start_time:'',end_time:''});
});
