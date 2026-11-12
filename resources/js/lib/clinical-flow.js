/** Reglas puras de presentación de estados. El servidor conserva la decisión final sobre cada transición. */
export function shiftDate(value, days) {
    const date=new Date(`${value}T12:00:00Z`);date.setUTCDate(date.getUTCDate()+days);return date.toISOString().slice(0,10);
}
export function appointmentStage(a) {
    if(a.status==='Cancelada')return {key:'cancelled',label:'Cancelada',next:'La cita ya no ocupa el horario. Revise si corresponde solicitar un reembolso.'};
    if(a.status==='Inasistencia')return {key:'absent',label:'No asistió',next:'La inasistencia quedó registrada; la cita permanece en el historial.'};
    if(a.status==='Atendida')return {key:'finished',label:'Consulta finalizada',next:a.followup_suggested_at?'Hay una recomendación de seguimiento para programar.':'La atención está completa y disponible en el historial.'};
    if(a.requires_arrival_payment)return {key:'payment',label:'Revisar cobro',next:'Recepción debe comprobar el pago antes de completar el ingreso del paciente.'};
    if(a.attendance_at)return {key:'waiting',label:'En espera de atención',next:'La llegada está registrada. El médico responsable puede finalizar la consulta cuando termine.'};
    return {key:'expected',label:'Por llegar',next:'Cuando el paciente llegue a la clínica, Recepción registrará su presencia.'};
}
export function canReceive(a,today) {
    return a.date===today&&['Programada','Confirmada'].includes(a.status)&&(!a.attendance_at||Boolean(a.requires_arrival_payment));
}
export function canFinish(a,today) {
    return a.date===today&&a.status==='Confirmada'&&Boolean(a.attendance_at)&&!a.requires_arrival_payment&&a.care_ready!==false;
}
