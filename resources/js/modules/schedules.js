/** CU07: semana del médico, conflictos de turnos, ausencias y distribución de cupos. */
import {conflictingShifts, suggestedShift} from '../lib/schedule-planner';

export function installScheduleViews(ui) {
    const {$, state, views, api, esc, full, code, time, days, localDate, notice, panel, formErrors,
        select, field, submit, table, button, on, navigate, bindForm, toast, modal, closeModal, details} = ui;
    let tab = 'schedule';
    let capacityDate = localDate();
    const weekdays = [1, 2, 3, 4, 5, 6, 0];
    const period = shift => `${time(shift.start_time)}–${time(shift.end_time)}`;
    const dateTime = value => {
        const stamp = String(value).replace(' ', 'T');
        return new Date(stamp + (/Z$|[+-]\d{2}:?\d{2}$/.test(stamp) ? '' : '-04:00')).toLocaleString('es-BO', {dateStyle:'medium', timeStyle:'short', timeZone:'America/La_Paz'});
    };
    const affectedTable = rows => table(['Cita','Paciente','Especialidad','Fecha','Horario','Estado'], rows.map(a => [
        esc(code('CIT',a.id)), esc(full(a.patient)), esc(a.specialty?.name || '—'), esc(a.date),
        esc(`${time(a.start_time)}–${time(a.end_time)}`), esc(a.status),
    ]));
    const affectedSummary = rows => notice(`${rows.length} citas pendientes afectadas. Las citas conservan su estado y programación.`, rows.length ? 'warning' : 'success')
        + (rows.length ? affectedTable(rows) : '<p>No hay citas activas que se crucen con este período.</p>');

    views.CU07 = async () => {
        const doctors = state.refs.doctors;
        if (!doctors.length) return {html:panel('Primero registre un médico', '<p>El horario se asigna a un profesional. Registre su ficha y luego configure sus días de atención.</p>'+button('Ir a médicos','data-open-view="CU04"'))};
        if (!doctors.some(d => String(d.id) === String(state.scheduleDoctor))) state.scheduleDoctor = doctors.find(d => d.status === 'Activo')?.id || doctors[0].id;
        const doctor = doctors.find(d => String(d.id) === String(state.scheduleDoctor));
        const doctorId = doctor.id;
        const data = await api('schedules?'+new URLSearchParams({doctor_id:doctorId}));
        const schedules = data.schedules;
        const canEdit = doctor.status === 'Activo';
        const weeklyMinutes = schedules.reduce((sum,s)=>{
            const minutes=t=>Number(t.slice(0,2))*60+Number(t.slice(3,5));
            return sum + minutes(s.end_time)-minutes(s.start_time);
        },0);
        const week = `<div class="week-grid">${weekdays.map(day=>{
            const shifts=schedules.filter(s=>Number(s.weekday)===day);
            return `<section class="week-day ${shifts.length?'has-shifts':''}" aria-label="${esc(days[day])}"><h4>${esc(days[day])}<span>${shifts.length} ${shifts.length===1?'turno':'turnos'}</span></h4>
                <div class="day-shifts">${shifts.map(s=>`<button type="button" class="shift-card" data-schedule="${s.id}" ${canEdit?'':'disabled'} aria-label="Modificar ${esc(days[day])} ${esc(period(s))}"><strong>${esc(period(s))}</strong><span>Consultas de ${s.duration} min</span><small>${canEdit?'Modificar turno':'Médico inactivo'}</small></button>`).join('') || '<p class="day-empty">Sin atención programada</p>'}</div>
                ${canEdit?button('+ Añadir turno',`data-new-shift="${day}"`,'link'):''}</section>`;
        }).join('')}</div>`;
        let body='';
        if(tab==='schedule') body=panel('Semana de atención',`<div class="section-toolbar"><p>Estos son los turnos que ya existen. Pulse uno para modificarlo o añada un turno en otro horario.</p>${canEdit?button('+ Añadir turno','id="new-shift"'):''}</div>${week}<p class="help-text">Los turnos se repiten cada semana. Dos turnos pueden ser consecutivos, pero no ocupar la misma franja. Las citas existentes se conservan cuando cambia un horario.</p>`);
        else if(tab==='block') body=panel('Ausencias, permisos y vacaciones',`<div class="section-toolbar"><p>Revise los períodos en los que este médico no atenderá. Puede comprobar las citas afectadas antes de guardar.</p>${canEdit?button('+ Registrar ausencia','id="new-unavailability"'):''}</div>`
            +(data.unavailabilities.length?table(['Desde','Hasta','Motivo','Estado','Acciones'],data.unavailabilities.map(b=>[
                esc(dateTime(b.starts_at)),esc(dateTime(b.ends_at)),esc(b.reason)
                    +(b.cancelled_at?`<p><small>Retirada: ${esc(dateTime(b.cancelled_at))} · ${esc(b.cancellation_reason)}</small></p>`:''),
                b.cancelled_at?'Retirada':'Activa',b.cancelled_at?'Se conserva el historial':button('Ver citas afectadas',`data-absence-impacts="${b.id}"`,'link')
                    +button('Retirar ausencia',`data-cancel-absence="${b.id}"`,''),
            ])):'<div class="friendly-empty"><strong>No hay ausencias registradas</strong><p>La disponibilidad sigue el horario semanal del médico.</p></div>'));
        else {
            const quota=data.quota||{presential_percent:50,online_percent:50};
            body=panel('Cómo se reparten las consultas',`<div class="quota-summary"><div><strong>${quota.presential_percent}%</strong><span>Presencial</span></div><div><strong>${quota.online_percent}%</strong><span>Reserva online</span></div></div><p>Las primeras dos horas de cada turno se protegen para atención presencial. El resto se distribuye según estos porcentajes; las citas existentes se conservan.</p>${canEdit?button('Cambiar distribución','id="edit-quota"',''):''}`)
                +panel('Ver capacidad de un día',`<form id="capacity-form">${formErrors}<div class="inline-form">${field('Fecha de atención','date','date',capacityDate,'required')}${submit('Ver cupos')}</div></form><div id="capacity-result" aria-live="polite"></div>`);
        }
        /** Presenta turnos existentes y señala cruces antes de guardar. */
        function openShift(shift=null,day=1) {
            const values=shift?{...shift,start_time:time(shift.start_time),end_time:time(shift.end_time)}:{weekday:day,duration:30,...suggestedShift(schedules,day)};
            modal(shift?'Modificar turno':'Añadir turno',`<p class="dialog-context">${esc(full(doctor))} · ${shift?'Se actualizará el turno seleccionado.':'Se propone una franja libre del horario semanal.'}</p><form id="schedule-form">${formErrors}<div class="grid two">
                ${select('Día de atención','weekday',weekdays.map(n=>[n,days[n]]),values.weekday)}
                ${field('Duración de consulta (minutos)','duration','number',values.duration,'required min="1"')}
                ${field('Hora de inicio','start_time','time',values.start_time,'required')}
                ${field('Hora de finalización','end_time','time',values.end_time,'required')}</div>
                <div id="schedule-existing" class="schedule-existing"></div><div id="schedule-conflicts" aria-live="polite"></div>
                <p class="help-text">Las primeras dos horas del turno se reservan para presencial. Puede ajustar la distribución en «Cupos por modalidad».</p>
                <div class="actions end">${button('Cancelar','data-dismiss','')}<button type="submit" class="btn success">${shift?'Guardar cambios':'Añadir turno'}</button></div></form>`);
            const form=$('#schedule-form');
            const check=()=>{
                const values=Object.fromEntries(new FormData(form));
                const existing=schedules.filter(s=>Number(s.weekday)===Number(values.weekday));
                $('#schedule-existing').innerHTML=`<strong>Turnos del ${esc(days[Number(values.weekday)].toLowerCase())}</strong>`+(existing.length?existing.map(s=>`<span class="schedule-chip ${String(s.id)===String(shift?.id)?'editing':''}">${esc(period(s))}${String(s.id)===String(shift?.id)?' · modificando':''}</span>`).join(''):'<span>No hay turnos. Puede elegir una franja nueva.</span>');
                const conflicts=conflictingShifts(schedules,values,shift?.id);
                const invalid=values.start_time&&values.end_time&&values.end_time<=values.start_time;
                const minutes=t=>Number(t.slice(0,2))*60+Number(t.slice(3,5));
                const tooShort=!invalid&&values.start_time&&values.end_time&&minutes(values.end_time)-minutes(values.start_time)<Number(values.duration);
                const message=invalid?'La hora de finalización debe ser posterior al inicio.':tooShort?'La consulta debe caber dentro del turno.':conflicts.length?`Se cruza con ${conflicts.map(period).join(', ')}. Elija otra franja o cierre esta ventana y modifique ese turno.`:'';
                $('#schedule-conflicts').innerHTML=message?notice(message,'warning'):values.start_time&&values.end_time?notice('Esta franja no se cruza con los turnos mostrados. Se verificará nuevamente al guardar.','success'):notice('Complete las horas del nuevo turno.');
                form.querySelector('[name=end_time]').setCustomValidity(message);
                if(conflicts.length)form.querySelector('[name=end_time]').setAttribute('aria-invalid','true');else form.querySelector('[name=end_time]').removeAttribute('aria-invalid');
            };
            on('#schedule-form [name=weekday]','change',()=>{
                if(!shift){const proposed=suggestedShift(schedules,form.elements.weekday.value);form.elements.start_time.value=proposed.start_time;form.elements.end_time.value=proposed.end_time;}
                check();
            });
            on('#schedule-form input','input',check);on('[data-dismiss]','click',closeModal);check();
            bindForm('schedule-form',async values=>{
                const result=await api(`schedules${shift?'/'+shift.id:''}`,shift?'PUT':'POST',{...values,doctor_id:doctorId});
                closeModal();await navigate('CU07');toast(`Turno guardado para ${days[Number(values.weekday)]}.`);
                if(result.affected_appointments?.length)modal('Citas que necesitan revisión',notice('El horario se guardó y estas citas conservan su estado. Recepción debe revisar su programación.')+table(['Cita','Paciente','Fecha'],result.affected_appointments.map(a=>[esc(code('CIT',a.id)),esc(full(a.patient)),esc(a.date)])));
            });
        }
        /** Permite revisar citas afectadas antes de confirmar una indisponibilidad. */
        function openAbsence() {
            modal('Registrar ausencia',`<p class="dialog-context">${esc(full(doctor))}</p><form id="unavailable-form">${formErrors}<div class="grid two">${select('Motivo','reason',['Ausencia','Permiso','Vacaciones'])}${field('Desde','starts_at','datetime-local',`${localDate()}T08:00`,'required')}${field('Hasta','ends_at','datetime-local',`${localDate()}T12:00`,'required')}</div><div id="affected-preview" aria-live="polite"></div><div class="actions">${button('Ver citas afectadas','id="preview-block"','')}<button type="submit" class="btn success">Registrar ausencia</button></div></form>`);
            on('#preview-block','click',async()=>{
                const form=$('#unavailable-form');if(!form.reportValidity())return;
                const result=await api('unavailabilities/preview','POST',{...Object.fromEntries(new FormData(form)),doctor_id:doctorId});
                $('#affected-preview').innerHTML=affectedSummary(result.affected);
            });
            on('#starts_at,#ends_at','change',()=>{$('#affected-preview').innerHTML='';});
            bindForm('unavailable-form',async values=>{
                const result=await api('unavailabilities','POST',{...values,doctor_id:doctorId});
                closeModal();await navigate('CU07');toast(`Ausencia registrada. ${result.affected.length} citas requieren revisión.`);
                modal('Resultado de la ausencia', affectedSummary(result.affected)
                    +'<p>Las citas anteriores a la hora actual también se evalúan si siguen pendientes. Recepción debe gestionar las citas afectadas.</p>');
            });
        }
        function cancelAbsence(absence) {
            modal('Retirar ausencia', `<p class="dialog-context">${esc(full(doctor))} · ${esc(dateTime(absence.starts_at))} – ${esc(dateTime(absence.ends_at))}</p>`
                + notice('El médico vuelve a atender según sus turnos. Las citas existentes se conservan; otras ausencias y bloqueos siguen vigentes.')
                + `<form id="cancel-absence-form">${formErrors}${field('Motivo del retiro','reason','text','','required maxlength="300" placeholder="El médico confirmó que sí atenderá"')}`
                + `<div class="actions end">${button('Volver','data-dismiss','')}${submit('Confirmar retiro')}</div></form>`);
            on('[data-dismiss]','click',closeModal);
            bindForm('cancel-absence-form',async values=>{
                await api(`unavailabilities/${absence.id}/cancel`,'POST',values);
                closeModal();await navigate('CU07');toast('Ausencia retirada. Se actualizó la disponibilidad y se conservaron las citas.');
            });
        }
        return {html:`<section class="panel doctor-workspace"><div>${select('Ver horario del médico','schedule-doctor',doctors.map(d=>[d.id,`${full(d)}${d.status==='Activo'?'':' · Inactivo'}`]),doctorId)}<p>${esc(doctor.specialties?.map(s=>s.name).join(' · ')||'Sin especialidades')}</p></div><div class="workspace-stat"><strong>${schedules.length}</strong><span>turnos semanales</span></div><div class="workspace-stat"><strong>${Math.round(weeklyMinutes/60*10)/10} h</strong><span>de atención semanal</span></div></section>`
            +(!canEdit?notice('El médico está inactivo. Puede consultar su configuración; actívelo desde Médicos para modificarla.','warning'):'')
            +`<div class="tabs" role="tablist" aria-label="Configuración del médico">${[['schedule','Semana de atención'],['block','Ausencias y vacaciones'],['quota','Cupos por modalidad']].map(([id,label])=>`<button type="button" role="tab" aria-selected="${id===tab}" class="tab ${id===tab?'selected':''}" data-tab="${id}">${label}</button>`).join('')}</div>`+body,
            bind:()=>{
                on('#schedule-doctor','change',e=>{state.scheduleDoctor=e.target.value;return navigate('CU07');});
                on('[data-tab]','click',e=>{tab=e.currentTarget.dataset.tab;return navigate('CU07');});
                on('#new-shift','click',()=>openShift());on('[data-new-shift]','click',e=>openShift(null,Number(e.currentTarget.dataset.newShift)));
                on('[data-schedule]','click',e=>openShift(schedules.find(s=>String(s.id)===e.currentTarget.dataset.schedule)));
                on('#new-unavailability','click',openAbsence);
                on('[data-absence-impacts]','click',async e=>{
                    const result=await api(`unavailabilities/${e.currentTarget.dataset.absenceImpacts}/impacts`);
                    modal('Citas afectadas por esta ausencia',affectedSummary(result.affected));
                });
                on('[data-cancel-absence]','click',e=>cancelAbsence(data.unavailabilities.find(b=>String(b.id)===e.currentTarget.dataset.cancelAbsence)));
                on('#edit-quota','click',()=>{
                    modal('Cambiar distribución de cupos',`<p>${esc(full(doctor))}</p><form id="quota-form">${formErrors}<div class="grid two">${field('Porcentaje presencial','presential_percent','number',data.quota?.presential_percent??50,'required min="0" max="100" step="1"')}${field('Porcentaje online','online_percent','number',data.quota?.online_percent??50,'required min="0" max="100" step="1"')}</div><p>Ambos porcentajes deben sumar 100. Se conservan las citas ya registradas.</p>${submit('Guardar distribución')}</form>`);
                    bindForm('quota-form',async values=>{await api('doctor-quotas','PUT',{...values,doctor_id:doctorId});closeModal();await navigate('CU07');toast('Distribución actualizada.');});
                });
                if(tab==='quota')bindForm('capacity-form',async values=>{
                    capacityDate=values.date;const result=await api('schedules/capacity?'+new URLSearchParams({doctor_id:doctorId,date:capacityDate}));
                    $('#capacity-result').innerHTML=details([['Capacidad del día',esc(result.total_capacity)],['Presencial',esc(result.presential_capacity)],['Online',esc(result.online_capacity)],['Bloqueados',esc(result.blocked_blocks)]])
                        +notice('Capacidad según horario e indisponibilidades. No descuenta las citas ya reservadas.')
                        +table(['Inicio','Fin','Modalidad','Protección presencial'],result.blocks.map(b=>[esc(b.start_time),esc(b.end_time),b.modality==='presential'?'Presencial':'Online',b.protected?'Sí':'No']));
                });
            }};
    };
}
