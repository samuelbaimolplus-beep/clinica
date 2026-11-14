/** CU10–CU15: tarjetas de citas con acciones por rol y estado. Ocultar un botón no sustituye la autorización del backend. */
import {etapaCita,puedeRegistrarLlegada,puedeFinalizar,desplazarFecha} from "../lib/flujo-clinico";

export function instalarAreaCitas(interfaz) {
    const {$,estadoLocal: estadoLocal,vistas: vistas,clienteApi: clienteApi,escapar: escapar,nombreCompleto: nombreCompleto,codigo: codigo,hora: hora,fechaLocalLocal: fechaLocalLocal,boton: boton,panel,aviso: aviso,enlazarEvento: enlazarEvento,navegar: navegar,modal,areaTexto: areaTexto,seleccionar: seleccionar,botonGuardar: botonGuardar,erroresFormulario: erroresFormulario,enlazarFormulario: enlazarFormulario,cerrarModal: cerrarModal,notificacion: notificacion}=interfaz;
    const filtros={};
    window.addEventListener('clinic:identity-changed',()=>{for(const clave of Object.keys(filtros))delete filtros[clave];});
    const normalizar=valorDato=>String(valorDato||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
    const diaSemana=valorDato=>new Date(valorDato+'T12:00:00Z').toLocaleDateString('es-BO',{weekday:'short',day:'numeric',month:'short',timeZone:'America/La_Paz'});
    const rolLocal=()=>estadoLocal.user.role;
    const recepcion=()=>['Recepcionista','Superadmin'].includes(rolLocal());
    /** Consulta el ámbito permitido y construye la lista operativa con sus filtros. */
    async function areaTrabajo(vista,modalidad) {
        const hoy=fechaLocalLocal();const profesionalMedico=rolLocal()==='Médico',paciente=rolLocal()==='Paciente',operadorClinico=profesionalMedico||rolLocal()==='Superadmin';
        const f=filtros[vista]||{date:['agenda'].includes(modalidad)?hoy:'',stage:'all',q:''};filtros[vista]=f;
        if(estadoLocal.workflowContext?.date)f.date=estadoLocal.workflowContext.date;
        if(estadoLocal.workflowContext?.patient_id){f.patient_id=estadoLocal.workflowContext.patient_id;f.date='';}
        estadoLocal.workflowContext=null;
        const consulta={mode: modalidad,...(f.date?{date:f.date}:{}),...(f.patient_id?{patient_id:f.patient_id}:{})};
        const filas=await clienteApi('appointments?'+new URLSearchParams(consulta));
        const solicitudes=vista==='CU10'?await clienteApi('reschedule-requests'):[];
        const ambito=modalidad==='today'||modalidad==='attend'?'de hoy':f.date?`del ${diaSemana(f.date)}`:'registradas';
        const cantidades={all:filas.length,expected:filas.filter(a=>etapaCita(a).key==='expected').length,waiting:filas.filter(a=>etapaCita(a).key==='waiting').length,finished:filas.filter(a=>etapaCita(a).key==='finished').length,cancelled:filas.filter(a=>a.status==='Cancelada').length};
        /** Ofrece las acciones aplicables al rol y estado del registro. */
        function acciones(a) {
            const vigente=['Programada','Confirmada'].includes(a.status);
            let html='';
            if(recepcion()&&puedeRegistrarLlegada(a,hoy))html+=boton(a.requires_arrival_payment?'Verificar cobro y asistencia':'Registrar asistencia',`data-care="attendance" data-id="${a.id}"`,'success');
            if(recepcion()&&vigente&&a.date!==hoy&&!a.attendance_at)html+=`<span class="help-text">Asistencia pendiente: se registra el día de la cita (${escapar(diaSemana(a.date))}).</span>`;
            if(operadorClinico&&puedeFinalizar(a,hoy))html+=boton('Finalizar consulta',`data-care="attend" data-id="${a.id}"`,'success');
            if(operadorClinico&&vigente&&!a.attendance_at&&a.date===hoy)html+=boton('No acudió al llamado',`data-care="missed-call" data-id="${a.id}"`,'');
            if(recepcion()&&a.status==='Atendida'&&a.followup_suggested_at)html+=boton('Programar seguimiento',`data-followup="${a.id}"`,'success');
            if(vigente&&recepcion())html+=boton('Cambiar horario',`data-change="${a.id}"`,'');
            if(vista==='CU10'&&vigente&&recepcion()&&a.events?.some(evento=>evento.action==='Reprogramación'))html+=boton('Avisar reprogramación',`data-notify-appointment="${a.id}"`,'');
            if(vigente&&paciente)html+=boton('Solicitar cambio',`data-patient-change="${a.id}"`,'');
            if(a.status==='Cancelada'&&a.payment_id&&interfaz.vistasPermitidas().includes('CU20'))html+=boton('Revisar reembolso',`data-refund-for="${a.id}"`,'');
            html+=boton('Ver detalle',`data-record-detail="${a.id}"`,'link');
            if(recepcion()&&vigente)html+=`<details class="record-more"><summary>Más acciones</summary>${boton('Cancelar cita',`data-cancel-record="${a.id}"`,'danger')}${a.date===hoy&&!a.attendance_at?boton('Registrar inasistencia definitiva',`data-care="absent" data-id="${a.id}"`,''):''}</details>`;
            return html;
        }
        /** Presenta el estado y próximo paso de una cita con datos escapados. */
        function fichaCita(a) {
            const etapa=etapaCita(a);
            const pagada=a.complimentary||a.payment?.status==='confirmed';
            const fase=a.status==='Atendida'?4:a.attendance_at?3:pagada?2:1;
            return `<article class="appointment-card ${etapa.key} ${estadoLocal.openAppointmentId===a.id?'highlight-record':''}" data-record="${a.id}"><div class="appointment-time"><strong>${escapar(hora(a.start_time))}</strong><span>${escapar(hora(a.end_time))}</span><small>${escapar(diaSemana(a.date))}</small></div><div class="appointment-body"><div class="record-heading"><div><span class="record-code">${escapar(codigo('CIT',a.id))}</span><h3>${escapar(paciente?nombreCompleto(a.doctor):nombreCompleto(a.patient))}</h3><p>${escapar(a.specialty.name)}${!paciente?' · '+escapar(nombreCompleto(a.doctor)):''}</p></div><span class="stage-badge ${etapa.key}">${escapar(etapa.label)}</span></div><p class="next-step-copy">${escapar(etapa.next)}</p>`
                +(!profesionalMedico?`<ol class="mini-journey" aria-label="Progreso de la cita">${[['Cita creada',1],['Pago o exención',2],['Llegada',3],['Consulta',4]].map(([etiqueta,indice])=>`<li class="${indice<=fase?'done':''}"><span>${indice<=fase?'✓':indice}</span>${etiqueta}</li>`).join('')}</ol>`:'')
                +(profesionalMedico&&!a.attendance_at&&['Programada','Confirmada'].includes(a.status)?'<p class="help-text">La finalización se habilitará cuando Recepción registre el ingreso del paciente.</p>':'')
                +`<div class="record-actions">${acciones(a)}</div></div></article>`;
        }
        /** Filtra únicamente los datos autorizados que ya devolvió el servidor. */
        function filtradas() {
            return filas.filter(a=>(f.stage==='all'||etapaCita(a).key===f.stage)&&normalizar(`${nombreCompleto(a.patient)} ${nombreCompleto(a.doctor)} ${a.specialty.name} ${codigo('CIT',a.id)}`).includes(normalizar(f.q)));
        }
        /** Actualiza los registros visibles y sus acciones sin alterar su estado en el servidor. */
        function dibujar() {
            const visible=filtradas();
            $('#appointment-list').innerHTML=visible.length?visible.map(fichaCita).join(''):`<div class="friendly-empty"><strong>${filas.length?'No hay citas que coincidan con este filtro':'No hay citas '+ambito}</strong><p>${recepcion()?'Puede buscar otro día o programar una cita. No es necesario que el paciente tenga cuenta.':profesionalMedico?'Las citas de su agenda aparecerán aquí; Recepción registra la llegada.':'Sus citas aparecerán aquí cuando la clínica las programe o se confirme una reserva online.'}</p>${interfaz.vistasPermitidas().includes('CU09')?boton('+ Programar cita','data-board-book'):interfaz.vistasPermitidas().includes('CU18')?boton('Buscar una cita','data-board-book'):''}</div>`;
            enlazarRegistros();
        }
        /** Enlaza acciones a las citas autorizadas presentes en el listado. */
        function enlazarRegistros() {
            enlazarEvento('[data-record-detail]','click',e=>interfaz.accionesClinicas.historial(filas.find(a=>String(a.id)===e.currentTarget.dataset.recordDetail)),$('#appointment-list'));
            enlazarEvento('[data-care]','click',e=>interfaz.accionesClinicas.cambiarEstado(filas.find(a=>String(a.id)===e.currentTarget.dataset.id),e.currentTarget.dataset.care),$('#appointment-list'));
            enlazarEvento('[data-change]','click',e=>interfaz.accionesClinicas.reprogramar(filas.find(a=>String(a.id)===e.currentTarget.dataset.change)),$('#appointment-list'));
            enlazarEvento('[data-notify-appointment]','click',e=>interfaz.accionesClinicas.avisarReprogramacion(filas.find(a=>String(a.id)===e.currentTarget.dataset.notifyAppointment)),$('#appointment-list'));
            enlazarEvento('[data-cancel-record]','click',e=>interfaz.accionesClinicas.cancelar(filas.find(a=>String(a.id)===e.currentTarget.dataset.cancelRecord)),$('#appointment-list'));
            enlazarEvento('[data-followup]','click',e=>{estadoLocal.workflowContext={parent_appointment_id:Number(e.currentTarget.dataset.followup)};estadoLocal.bookingDraft=null;return navegar('CU21');},$('#appointment-list'));
            enlazarEvento('[data-refund-for]','click',e=>{estadoLocal.workflowContext={appointment_id:Number(e.currentTarget.dataset.refundFor)};return navegar('CU20');},$('#appointment-list'));
            enlazarEvento('[data-patient-change]','click',e=>{
                const id=e.currentTarget.dataset.patientChange;
                modal('Solicitar cambio de horario',`<p>Su cita actual se conserva hasta que Recepción acuerde el cambio.</p><form id="patient-change">${erroresFormulario}${areaTexto('Motivo y disponibilidad propuesta','reason','','required maxlength="3000"')}${botonGuardar('Enviar solicitud a Recepción')}</form>`);
                enlazarFormulario('patient-change',async datos=>{await clienteApi(`appointments/${id}/reschedule-request`,'POST',datos);cerrarModal();estadoLocal.flowFeedback={message:'Solicitud enviada. Su cita original sigue vigente hasta que Recepción coordine el cambio.'};await navegar('CU10');});
            },$('#appointment-list'));
            enlazarEvento('[data-board-book]','click',()=>{estadoLocal.bookingDraft=null;return navegar(paciente?'CU18':'CU09');},$('#appointment-list'));
        }
        const pendientes=solicitudes.filter(r=>r.status==='pending');
        const accionesSolicitud=r=>{
            if(!recepcion())return '';
            if(r.status==='pending')return `<div class="record-actions">${boton('Acordar nuevo horario',`data-process-request="${r.id}"`)}${boton('Rechazar con respuesta',`data-reject-request="${r.id}"`,'')}</div>`;
            if(r.status==='accepted'&&['Programada','Confirmada'].includes(r.appointment?.status))return `<div class="record-actions">${boton('Avisar reprogramación',`data-notify-request="${r.id}"`,'')}</div>`;
            return '';
        };
        return {html:(estadoLocal.flowFeedback?`<div class="operation-success" role="status"><span>✓</span><div><strong>Operación completada</strong><p>${escapar(estadoLocal.flowFeedback.message)}</p></div></div>`:'')
            +`<div class="workspace-heading"><div><h2>${profesionalMedico?'Su lista de atención':paciente?'Sus citas y su atención':'Agenda de la clínica'}</h2><p>${filas.length} citas ${escapar(ambito)}. ${recepcion()?'Trabaje desde la ficha de cada cita: llegada, cambio, cancelación o seguimiento.':profesionalMedico?'Las acciones se habilitan según el ingreso del paciente.':'La atención presencial también puede gestionarla Recepción sin usar el portal.'}</p></div>${interfaz.vistasPermitidas().includes('CU09')?boton('+ Programar cita','id="board-new-booking"'):paciente?boton('Buscar una cita','id="board-new-booking"'):''}</div>`
            +(vista==='CU10'&&solicitudes.length?panel('Solicitudes de cambio y respuestas',solicitudes.map(r=>`<article class="request-card"><div><strong>${escapar(r.appointment?nombreCompleto(r.appointment.patient):codigo('CIT',r.appointment_id))}</strong><p>${escapar(r.reason)}</p><span class="stage-badge">${escapar(({pending:'Pendiente de Recepción',accepted:'Cambio acordado',rejected:'Solicitud rechazada',closed:'Solicitud cerrada'})[r.status]||r.status)}</span>${r.response?`<p>Respuesta: ${escapar(r.response)}</p>`:'<small>La cita actual se conserva hasta coordinar el cambio.</small>'}</div>${accionesSolicitud(r)}</article>`).join('')):'')
            +`<section class="agenda-controls"><div class="agenda-date">${!['today','attend'].includes(modalidad)?`${boton('‹ Día anterior','data-board-date="-1"','')}<label class="field"><span>Día de atención</span><input id="board-date" type="date" value="${f.date||hoy}"></label>${boton('Día siguiente ›','data-board-date="1"','')}${boton('Hoy','id="board-today"','')}${boton('Ver todas','id="board-all"','')}`:`<strong>${escapar(diaSemana(hoy))}</strong><span>Operaciones de la jornada</span>`}</div><label class="field"><span>Buscar en estas citas</span><input id="board-search" type="search" value="${escapar(f.q)}" placeholder="Nombre, médico o código"></label></section>`
            +`<div class="status-filters" aria-label="Filtrar citas">${[['all','Todas'],['expected','Por llegar'],['waiting','En espera'],['finished','Atendidas'],['cancelled','Canceladas']].map(([clave,etiqueta])=>`<button type="button" class="filter-chip ${f.stage===clave?'selected':''}" data-stage="${clave}" aria-pressed="${f.stage===clave}">${etiqueta} <span>${cantidades[clave]}</span></button>`).join('')}</div><div id="appointment-list" class="appointment-list"></div>`,bind:()=>{
                dibujar();
                enlazarEvento('#board-search','input',e=>{f.q=e.target.value;dibujar();});
                enlazarEvento('[data-stage]','click',e=>{f.stage=e.currentTarget.dataset.stage;document.querySelectorAll('[data-stage]').forEach(b=>{b.classList.toggle('selected',b.dataset.stage===f.stage);b.setAttribute('aria-pressed',String(b.dataset.stage===f.stage));});dibujar();});
                const recargar=fechaLocal=>{f.date=fechaLocal;f.stage='all';return navegar(vista);};
                enlazarEvento('#board-date','change',e=>{if(e.target.value)return recargar(e.target.value);});
                enlazarEvento('[data-board-date]','click',e=>recargar(desplazarFecha(f.date||hoy,Number(e.currentTarget.dataset.boardDate))));
                enlazarEvento('#board-today','click',()=>recargar(hoy));enlazarEvento('#board-all','click',()=>{delete f.patient_id;return recargar('');});
                enlazarEvento('#board-new-booking','click',()=>{estadoLocal.bookingDraft=null;return navegar(paciente?'CU18':'CU09');});
                enlazarEvento('[data-process-request]','click',e=>interfaz.accionesClinicas.reprogramar(pendientes.find(r=>String(r.id)===e.currentTarget.dataset.processRequest).appointment));
                enlazarEvento('[data-notify-request]','click',e=>interfaz.accionesClinicas.avisarReprogramacion(solicitudes.find(r=>String(r.id)===e.currentTarget.dataset.notifyRequest).appointment));
                enlazarEvento('[data-reject-request]','click',e=>{
                    const id=e.currentTarget.dataset.rejectRequest;
                    modal('Responder la solicitud',`<p>La cita actual se conserva. Registre el motivo y comunique la respuesta al paciente.</p><form id="reject-change">${erroresFormulario}${areaTexto('Respuesta al paciente','response','','required maxlength="3000"')}${botonGuardar('Registrar rechazo y respuesta')}</form>`);
                    enlazarFormulario('reject-change',async datos=>{await clienteApi(`reschedule-requests/${id}/reject`,'POST',datos);cerrarModal();await navegar(vista);notificacion('Respuesta registrada. La cita original se conserva.');});
                });
            }};
    }
    for(const [vista,modalidad]of[['CU10','active'],['CU11','active'],['CU12','agenda'],['CU13','today'],['CU14','attend'],['CU15','history']])vistas[vista]=()=>areaTrabajo(vista,modalidad);
}
