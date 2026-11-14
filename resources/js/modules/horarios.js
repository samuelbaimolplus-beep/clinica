/** CU07: semana del médico, conflictos de turnos, ausencias y distribución de cupos. */
import {turnosEnConflicto, turnoSugerido} from "../lib/planificador-horarios";

export function instalarVistasHorarios(interfaz) {
    const {$, estadoLocal: estadoLocal, vistas: vistas, clienteApi: clienteApi, escapar: escapar, nombreCompleto: nombreCompleto, codigo: codigo, hora: hora, dias: dias, fechaLocalLocal: fechaLocalLocal, aviso: aviso, panel, erroresFormulario: erroresFormulario,
        seleccionar: seleccionar, campo: campo, botonGuardar: botonGuardar, tabla: tabla, boton: boton, enlazarEvento: enlazarEvento, navegar: navegar, enlazarFormulario: enlazarFormulario, notificacion: notificacion, modal, cerrarModal: cerrarModal, detalles: detalles} = interfaz;
    let pestana = 'schedule';
    let fechaCapacidad = fechaLocalLocal();
    const diasLaborales = [1, 2, 3, 4, 5, 6, 0];
    const periodo = turno => `${hora(turno.start_time)}–${hora(turno.end_time)}`;
    const fechaHora = valorDato => {
        const marcaTiempo = String(valorDato).replace(' ', 'T');
        return new Date(marcaTiempo + (/Z$|[+-]\d{2}:?\d{2}$/.test(marcaTiempo) ? '' : '-04:00')).toLocaleString('es-BO', {dateStyle:'medium', timeStyle:'short', timeZone:'America/La_Paz'});
    };
    const tablaAfectadas = filas => tabla(['Cita','Paciente','Especialidad','Fecha','Horario','Estado'], filas.map(a => [
        escapar(codigo('CIT',a.id)), escapar(nombreCompleto(a.patient)), escapar(a.specialty?.name || '—'), escapar(a.date),
        escapar(`${hora(a.start_time)}–${hora(a.end_time)}`), escapar(a.status),
    ]));
    const resumenAfectadas = filas => aviso(`${filas.length} citas pendientes afectadas. Las citas conservan su estado y programación.`, filas.length ? 'warning' : 'success')
        + (filas.length ? tablaAfectadas(filas) : '<p>No hay citas activas que se crucen con este período.</p>');

    vistas.CU07 = async () => {
        const medicos = estadoLocal.refs.doctors;
        if (!medicos.length) return {html:panel('Primero registre un médico', '<p>El horario se asigna a un profesional. Registre su ficha y luego configure sus días de atención.</p>'+boton('Ir a médicos','data-open-view="CU04"'))};
        if (!medicos.some(d => String(d.id) === String(estadoLocal.scheduleDoctor))) estadoLocal.scheduleDoctor = medicos.find(d => d.status === 'Activo')?.id || medicos[0].id;
        const medico = medicos.find(d => String(d.id) === String(estadoLocal.scheduleDoctor));
        const medicoId = medico.id;
        const datos = await clienteApi('schedules?'+new URLSearchParams({doctor_id:medicoId}));
        const horarios = datos.schedules;
        const puedeEditar = medico.status === 'Activo';
        const minutosSemanales = horarios.reduce((suma,s)=>{
            const minutos=t=>Number(t.slice(0,2))*60+Number(t.slice(3,5));
            return suma + minutos(s.end_time)-minutos(s.start_time);
        },0);
        const semana = `<div class="week-grid">${diasLaborales.map(diaSemana=>{
            const turnos=horarios.filter(s=>Number(s.weekday)===diaSemana);
            return `<section class="week-day ${turnos.length?'has-shifts':''}" aria-label="${escapar(dias[diaSemana])}"><h4>${escapar(dias[diaSemana])}<span>${turnos.length} ${turnos.length===1?'turno':'turnos'}</span></h4>
                <div class="day-shifts">${turnos.map(s=>`<button type="button" class="shift-card" data-schedule="${s.id}" ${puedeEditar?'':'disabled'} aria-label="Modificar ${escapar(dias[diaSemana])} ${escapar(periodo(s))}"><strong>${escapar(periodo(s))}</strong><span>Consultas de ${s.duration} min</span><small>${puedeEditar?'Modificar turno':'Médico inactivo'}</small></button>`).join('') || '<p class="day-empty">Sin atención programada</p>'}</div>
                ${puedeEditar?boton('+ Añadir turno',`data-new-shift="${diaSemana}"`,'link'):''}</section>`;
        }).join('')}</div>`;
        let contenido='';
        if(pestana==='schedule') contenido=panel('Semana de atención',`<div class="section-toolbar"><p>Estos son los turnos que ya existen. Pulse uno para modificarlo o añada un turno en otro horario.</p>${puedeEditar?boton('+ Añadir turno','id="new-shift"'):''}</div>${semana}<p class="help-text">Los turnos se repiten cada semana. Dos turnos pueden ser consecutivos, pero no ocupar la misma franja. Las citas existentes se conservan cuando cambia un horario.</p>`);
        else if(pestana==='block') contenido=panel('Ausencias, permisos y vacaciones',`<div class="section-toolbar"><p>Revise los períodos en los que este médico no atenderá. Puede comprobar las citas afectadas antes de guardar.</p>${puedeEditar?boton('+ Registrar ausencia','id="new-unavailability"'):''}</div>`
            +(datos.unavailabilities.length?tabla(['Desde','Hasta','Motivo','Estado','Acciones'],datos.unavailabilities.map(b=>[
                escapar(fechaHora(b.starts_at)),escapar(fechaHora(b.ends_at)),escapar(b.reason)
                    +(b.cancelled_at?`<p><small>Retirada: ${escapar(fechaHora(b.cancelled_at))} · ${escapar(b.cancellation_reason)}</small></p>`:''),
                b.cancelled_at?'Retirada':'Activa',b.cancelled_at?'Se conserva el historial':boton('Ver citas afectadas',`data-absence-impacts="${b.id}"`,'link')
                    +boton('Retirar ausencia',`data-cancel-absence="${b.id}"`,''),
            ])):'<div class="friendly-empty"><strong>No hay ausencias registradas</strong><p>La disponibilidad sigue el horario semanal del médico.</p></div>'));
        else {
            const cupo=datos.quota||{presential_percent:50,online_percent:50};
            contenido=panel('Cómo se reparten las consultas',`<div class="quota-summary"><div><strong>${cupo.presential_percent}%</strong><span>Presencial</span></div><div><strong>${cupo.online_percent}%</strong><span>Reserva online</span></div></div><p>Las primeras dos horas de cada turno se protegen para atención presencial. El resto se distribuye según estos porcentajes; las citas existentes se conservan.</p>${puedeEditar?boton('Cambiar distribución','id="edit-quota"',''):''}`)
                +panel('Ver capacidad de un día',`<form id="capacity-form">${erroresFormulario}<div class="inline-form">${campo('Fecha de atención','date','date',fechaCapacidad,'required')}${botonGuardar('Ver cupos')}</div></form><div id="capacity-result" aria-live="polite"></div>`);
        }
        /** Presenta turnos existentes y señala cruces antes de guardar. */
        function abrirTurno(turno=null,diaSemana=1) {
            const valores=turno?{...turno,start_time:hora(turno.start_time),end_time:hora(turno.end_time)}:{weekday:diaSemana,duration:30,...turnoSugerido(horarios,diaSemana)};
            modal(turno?'Modificar turno':'Añadir turno',`<p class="dialog-context">${escapar(nombreCompleto(medico))} · ${turno?'Se actualizará el turno seleccionado.':'Se propone una franja libre del horario semanal.'}</p><form id="schedule-form">${erroresFormulario}<div class="grid two">
                ${seleccionar('Día de atención','weekday',diasLaborales.map(n=>[n,dias[n]]),valores.weekday)}
                ${campo('Duración de consulta (minutos)','duration','number',valores.duration,'required min="1"')}
                ${campo('Hora de inicio','start_time','time',valores.start_time,'required')}
                ${campo('Hora de finalización','end_time','time',valores.end_time,'required')}</div>
                <div id="schedule-existing" class="schedule-existing"></div><div id="schedule-conflicts" aria-live="polite"></div>
                <p class="help-text">Las primeras dos horas del turno se reservan para presencial. Puede ajustar la distribución en «Cupos por modalidad».</p>
                <div class="actions end">${boton('Cancelar','data-dismiss','')}<button type="submit" class="btn success">${turno?'Guardar cambios':'Añadir turno'}</button></div></form>`);
            const formularioLocal=$('#schedule-form');
            const comprobar=()=>{
                const valores=Object.fromEntries(new FormData(formularioLocal));
                const existente=horarios.filter(s=>Number(s.weekday)===Number(valores.weekday));
                $('#schedule-existing').innerHTML=`<strong>Turnos del ${escapar(dias[Number(valores.weekday)].toLowerCase())}</strong>`+(existente.length?existente.map(s=>`<span class="schedule-chip ${String(s.id)===String(turno?.id)?'editing':''}">${escapar(periodo(s))}${String(s.id)===String(turno?.id)?' · modificando':''}</span>`).join(''):'<span>No hay turnos. Puede elegir una franja nueva.</span>');
                const conflictos=turnosEnConflicto(horarios,valores,turno?.id);
                const invalido=valores.start_time&&valores.end_time&&valores.end_time<=valores.start_time;
                const minutos=t=>Number(t.slice(0,2))*60+Number(t.slice(3,5));
                const demasiadoCorta=!invalido&&valores.start_time&&valores.end_time&&minutos(valores.end_time)-minutos(valores.start_time)<Number(valores.duration);
                const mensajeLocal=invalido?'La hora de finalización debe ser posterior al inicio.':demasiadoCorta?'La consulta debe caber dentro del turno.':conflictos.length?`Se cruza con ${conflictos.map(periodo).join(', ')}. Elija otra franja o cierre esta ventana y modifique ese turno.`:'';
                $('#schedule-conflicts').innerHTML=mensajeLocal?aviso(mensajeLocal,'warning'):valores.start_time&&valores.end_time?aviso('Esta franja no se cruza con los turnos mostrados. Se verificará nuevamente al guardar.','success'):aviso('Complete las horas del nuevo turno.');
                formularioLocal.querySelector('[name=end_time]').setCustomValidity(mensajeLocal);
                if(conflictos.length)formularioLocal.querySelector('[name=end_time]').setAttribute('aria-invalid','true');else formularioLocal.querySelector('[name=end_time]').removeAttribute('aria-invalid');
            };
            enlazarEvento('#schedule-form [name=weekday]','change',()=>{
                if(!turno){const propuestas=turnoSugerido(horarios,formularioLocal.elements.weekday.value);formularioLocal.elements.start_time.value=propuestas.start_time;formularioLocal.elements.end_time.value=propuestas.end_time;}
                comprobar();
            });
            enlazarEvento('#schedule-form input','input',comprobar);enlazarEvento('[data-dismiss]','click',cerrarModal);comprobar();
            enlazarFormulario('schedule-form',async valores=>{
                const resultado=await clienteApi(`schedules${turno?'/'+turno.id:''}`,turno?'PUT':'POST',{...valores,doctor_id:medicoId});
                cerrarModal();await navegar('CU07');notificacion(`Turno guardado para ${dias[Number(valores.weekday)]}.`);
                if(resultado.affected_appointments?.length)modal('Citas que necesitan revisión',aviso('El horario se guardó y estas citas conservan su estado. Recepción debe revisar su programación.')+tabla(['Cita','Paciente','Fecha'],resultado.affected_appointments.map(a=>[escapar(codigo('CIT',a.id)),escapar(nombreCompleto(a.patient)),escapar(a.date)])));
            });
        }
        /** Permite revisar citas afectadas antes de confirmar una indisponibilidad. */
        function abrirAusencia() {
            modal('Registrar ausencia',`<p class="dialog-context">${escapar(nombreCompleto(medico))}</p><form id="unavailable-form">${erroresFormulario}<div class="grid two">${seleccionar('Motivo','reason',['Ausencia','Permiso','Vacaciones'])}${campo('Desde','starts_at','datetime-local',`${fechaLocalLocal()}T08:00`,'required')}${campo('Hasta','ends_at','datetime-local',`${fechaLocalLocal()}T12:00`,'required')}</div><div id="affected-preview" aria-live="polite"></div><div class="actions">${boton('Ver citas afectadas','id="preview-block"','')}<button type="submit" class="btn success">Registrar ausencia</button></div></form>`);
            enlazarEvento('#preview-block','click',async()=>{
                const formularioLocal=$('#unavailable-form');if(!formularioLocal.reportValidity())return;
                const resultado=await clienteApi('unavailabilities/preview','POST',{...Object.fromEntries(new FormData(formularioLocal)),doctor_id:medicoId});
                $('#affected-preview').innerHTML=resumenAfectadas(resultado.affected);
            });
            enlazarEvento('#starts_at,#ends_at','change',()=>{$('#affected-preview').innerHTML='';});
            enlazarFormulario('unavailable-form',async valores=>{
                const resultado=await clienteApi('unavailabilities','POST',{...valores,doctor_id:medicoId});
                cerrarModal();await navegar('CU07');notificacion(`Ausencia registrada. ${resultado.affected.length} citas requieren revisión.`);
                modal('Resultado de la ausencia', resumenAfectadas(resultado.affected)
                    +'<p>Las citas anteriores a la hora actual también se evalúan si siguen pendientes. Recepción debe gestionar las citas afectadas.</p>');
            });
        }
        function retirarAusencia(ausencia) {
            modal('Retirar ausencia', `<p class="dialog-context">${escapar(nombreCompleto(medico))} · ${escapar(fechaHora(ausencia.starts_at))} – ${escapar(fechaHora(ausencia.ends_at))}</p>`
                + aviso('El médico vuelve a atender según sus turnos. Las citas existentes se conservan; otras ausencias y bloqueos siguen vigentes.')
                + `<form id="cancel-absence-form">${erroresFormulario}${campo('Motivo del retiro','reason','text','','required maxlength="300" placeholder="El médico confirmó que sí atenderá"')}`
                + `<div class="actions end">${boton('Volver','data-dismiss','')}${botonGuardar('Confirmar retiro')}</div></form>`);
            enlazarEvento('[data-dismiss]','click',cerrarModal);
            enlazarFormulario('cancel-absence-form',async valores=>{
                await clienteApi(`unavailabilities/${ausencia.id}/cancel`,'POST',valores);
                cerrarModal();await navegar('CU07');notificacion('Ausencia retirada. Se actualizó la disponibilidad y se conservaron las citas.');
            });
        }
        return {html:`<section class="panel doctor-workspace"><div>${seleccionar('Ver horario del médico','schedule-doctor',medicos.map(d=>[d.id,`${nombreCompleto(d)}${d.status==='Activo'?'':' · Inactivo'}`]),medicoId)}<p>${escapar(medico.specialties?.map(s=>s.name).join(' · ')||'Sin especialidades')}</p></div><div class="workspace-stat"><strong>${horarios.length}</strong><span>turnos semanales</span></div><div class="workspace-stat"><strong>${Math.round(minutosSemanales/60*10)/10} h</strong><span>de atención semanal</span></div></section>`
            +(!puedeEditar?aviso('El médico está inactivo. Puede consultar su configuración; actívelo desde Médicos para modificarla.','warning'):'')
            +`<div class="tabs" role="tablist" aria-label="Configuración del médico">${[['schedule','Semana de atención'],['block','Ausencias y vacaciones'],['quota','Cupos por modalidad']].map(([id,etiqueta])=>`<button type="button" role="tab" aria-selected="${id===pestana}" class="tab ${id===pestana?'selected':''}" data-tab="${id}">${etiqueta}</button>`).join('')}</div>`+contenido,
            bind:()=>{
                enlazarEvento('#schedule-doctor','change',e=>{estadoLocal.scheduleDoctor=e.target.value;return navegar('CU07');});
                enlazarEvento('[data-tab]','click',e=>{pestana=e.currentTarget.dataset.tab;return navegar('CU07');});
                enlazarEvento('#new-shift','click',()=>abrirTurno());enlazarEvento('[data-new-shift]','click',e=>abrirTurno(null,Number(e.currentTarget.dataset.newShift)));
                enlazarEvento('[data-schedule]','click',e=>abrirTurno(horarios.find(s=>String(s.id)===e.currentTarget.dataset.schedule)));
                enlazarEvento('#new-unavailability','click',abrirAusencia);
                enlazarEvento('[data-absence-impacts]','click',async e=>{
                    const resultado=await clienteApi(`unavailabilities/${e.currentTarget.dataset.absenceImpacts}/impacts`);
                    modal('Citas afectadas por esta ausencia',resumenAfectadas(resultado.affected));
                });
                enlazarEvento('[data-cancel-absence]','click',e=>retirarAusencia(datos.unavailabilities.find(b=>String(b.id)===e.currentTarget.dataset.cancelAbsence)));
                enlazarEvento('#edit-quota','click',()=>{
                    modal('Cambiar distribución de cupos',`<p>${escapar(nombreCompleto(medico))}</p><form id="quota-form">${erroresFormulario}<div class="grid two">${campo('Porcentaje presencial','presential_percent','number',datos.quota?.presential_percent??50,'required min="0" max="100" step="1"')}${campo('Porcentaje online','online_percent','number',datos.quota?.online_percent??50,'required min="0" max="100" step="1"')}</div><p>Ambos porcentajes deben sumar 100. Se conservan las citas ya registradas.</p>${botonGuardar('Guardar distribución')}</form>`);
                    enlazarFormulario('quota-form',async valores=>{await clienteApi('doctor-quotas','PUT',{...valores,doctor_id:medicoId});cerrarModal();await navegar('CU07');notificacion('Distribución actualizada.');});
                });
                if(pestana==='quota')enlazarFormulario('capacity-form',async valores=>{
                    fechaCapacidad=valores.date;const resultado=await clienteApi('schedules/capacity?'+new URLSearchParams({doctor_id:medicoId,date:fechaCapacidad}));
                    $('#capacity-result').innerHTML=detalles([['Capacidad del día',escapar(resultado.total_capacity)],['Presencial',escapar(resultado.presential_capacity)],['Online',escapar(resultado.online_capacity)],['Bloqueados',escapar(resultado.blocked_blocks)]])
                        +aviso('Capacidad según horario e indisponibilidades. No descuenta las citas ya reservadas.')
                        +tabla(['Inicio','Fin','Modalidad','Protección presencial'],resultado.blocks.map(b=>[escapar(b.start_time),escapar(b.end_time),b.modality==='presential'?'Presencial':'Online',b.protected?'Sí':'No']));
                });
            }};
    };
}
