/** CU10–CU15: tarjetas de citas con acciones por rol y estado. Ocultar un botón no sustituye la autorización del backend. */
import {appointmentStage,canReceive,canFinish,shiftDate} from '../lib/clinical-flow';

export function installAppointmentWorkspace(ui) {
    const {$,state,views,api,esc,full,code,time,localDate,button,panel,notice,on,navigate,modal,textarea,select,submit,formErrors,bindForm,closeModal,toast}=ui;
    const filters={};
    window.addEventListener('clinic:identity-changed',()=>{for(const key of Object.keys(filters))delete filters[key];});
    const normalize=value=>String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
    const day=value=>new Date(value+'T12:00:00Z').toLocaleDateString('es-BO',{weekday:'short',day:'numeric',month:'short',timeZone:'America/La_Paz'});
    const role=()=>state.user.role;
    const reception=()=>['Recepcionista','Superadmin'].includes(role());
    /** Consulta el ámbito permitido y construye la lista operativa con sus filtros. */
    async function workspace(view,mode) {
        const today=localDate();const physician=role()==='Médico',patient=role()==='Paciente',clinicalOperator=physician||role()==='Superadmin';
        const f=filters[view]||{date:['agenda'].includes(mode)?today:'',stage:'all',q:''};filters[view]=f;
        if(state.workflowContext?.date)f.date=state.workflowContext.date;
        if(state.workflowContext?.patient_id){f.patient_id=state.workflowContext.patient_id;f.date='';}
        state.workflowContext=null;
        const query={mode,...(f.date?{date:f.date}:{}),...(f.patient_id?{patient_id:f.patient_id}:{})};
        const rows=await api('appointments?'+new URLSearchParams(query));
        const requests=view==='CU10'?await api('reschedule-requests'):[];
        const scope=mode==='today'||mode==='attend'?'de hoy':f.date?`del ${day(f.date)}`:'registradas';
        const counts={all:rows.length,expected:rows.filter(a=>appointmentStage(a).key==='expected').length,waiting:rows.filter(a=>appointmentStage(a).key==='waiting').length,finished:rows.filter(a=>appointmentStage(a).key==='finished').length,cancelled:rows.filter(a=>a.status==='Cancelada').length};
        /** Ofrece las acciones aplicables al rol y estado del registro. */
        function actions(a) {
            const active=['Programada','Confirmada'].includes(a.status);
            let html='';
            if(reception()&&canReceive(a,today))html+=button(a.requires_arrival_payment?'Verificar cobro y asistencia':'Registrar asistencia',`data-care="attendance" data-id="${a.id}"`,'success');
            if(reception()&&active&&a.date!==today&&!a.attendance_at)html+=`<span class="help-text">Asistencia pendiente: se registra el día de la cita (${esc(day(a.date))}).</span>`;
            if(clinicalOperator&&canFinish(a,today))html+=button('Finalizar consulta',`data-care="attend" data-id="${a.id}"`,'success');
            if(clinicalOperator&&active&&!a.attendance_at&&a.date===today)html+=button('No acudió al llamado',`data-care="missed-call" data-id="${a.id}"`,'');
            if(reception()&&a.status==='Atendida'&&a.followup_suggested_at)html+=button('Programar seguimiento',`data-followup="${a.id}"`,'success');
            if(active&&reception())html+=button('Cambiar horario',`data-change="${a.id}"`,'');
            if(view==='CU10'&&active&&reception()&&a.events?.some(event=>event.action==='Reprogramación'))html+=button('Avisar reprogramación',`data-notify-appointment="${a.id}"`,'');
            if(active&&patient)html+=button('Solicitar cambio',`data-patient-change="${a.id}"`,'');
            if(a.status==='Cancelada'&&a.payment_id&&ui.allowedViews().includes('CU20'))html+=button('Revisar reembolso',`data-refund-for="${a.id}"`,'');
            html+=button('Ver detalle',`data-record-detail="${a.id}"`,'link');
            if(reception()&&active)html+=`<details class="record-more"><summary>Más acciones</summary>${button('Cancelar cita',`data-cancel-record="${a.id}"`,'danger')}${a.date===today&&!a.attendance_at?button('Registrar inasistencia definitiva',`data-care="absent" data-id="${a.id}"`,''):''}</details>`;
            return html;
        }
        /** Presenta el estado y próximo paso de una cita con datos escapados. */
        function card(a) {
            const stage=appointmentStage(a);
            const paid=a.complimentary||a.payment?.status==='confirmed';
            const phase=a.status==='Atendida'?4:a.attendance_at?3:paid?2:1;
            return `<article class="appointment-card ${stage.key} ${state.openAppointmentId===a.id?'highlight-record':''}" data-record="${a.id}"><div class="appointment-time"><strong>${esc(time(a.start_time))}</strong><span>${esc(time(a.end_time))}</span><small>${esc(day(a.date))}</small></div><div class="appointment-body"><div class="record-heading"><div><span class="record-code">${esc(code('CIT',a.id))}</span><h3>${esc(patient?full(a.doctor):full(a.patient))}</h3><p>${esc(a.specialty.name)}${!patient?' · '+esc(full(a.doctor)):''}</p></div><span class="stage-badge ${stage.key}">${esc(stage.label)}</span></div><p class="next-step-copy">${esc(stage.next)}</p>`
                +(!physician?`<ol class="mini-journey" aria-label="Progreso de la cita">${[['Cita creada',1],['Pago o exención',2],['Llegada',3],['Consulta',4]].map(([label,index])=>`<li class="${index<=phase?'done':''}"><span>${index<=phase?'✓':index}</span>${label}</li>`).join('')}</ol>`:'')
                +(physician&&!a.attendance_at&&['Programada','Confirmada'].includes(a.status)?'<p class="help-text">La finalización se habilitará cuando Recepción registre el ingreso del paciente.</p>':'')
                +`<div class="record-actions">${actions(a)}</div></div></article>`;
        }
        /** Filtra únicamente los datos autorizados que ya devolvió el servidor. */
        function filtered() {
            return rows.filter(a=>(f.stage==='all'||appointmentStage(a).key===f.stage)&&normalize(`${full(a.patient)} ${full(a.doctor)} ${a.specialty.name} ${code('CIT',a.id)}`).includes(normalize(f.q)));
        }
        /** Actualiza los registros visibles y sus acciones sin alterar su estado en el servidor. */
        function draw() {
            const visible=filtered();
            $('#appointment-list').innerHTML=visible.length?visible.map(card).join(''):`<div class="friendly-empty"><strong>${rows.length?'No hay citas que coincidan con este filtro':'No hay citas '+scope}</strong><p>${reception()?'Puede buscar otro día o programar una cita. No es necesario que el paciente tenga cuenta.':physician?'Las citas de su agenda aparecerán aquí; Recepción registra la llegada.':'Sus citas aparecerán aquí cuando la clínica las programe o se confirme una reserva online.'}</p>${ui.allowedViews().includes('CU09')?button('+ Programar cita','data-board-book'):ui.allowedViews().includes('CU18')?button('Buscar una cita','data-board-book'):''}</div>`;
            bindRecords();
        }
        /** Enlaza acciones a las citas autorizadas presentes en el listado. */
        function bindRecords() {
            on('[data-record-detail]','click',e=>ui.clinicalActions.history(rows.find(a=>String(a.id)===e.currentTarget.dataset.recordDetail)),$('#appointment-list'));
            on('[data-care]','click',e=>ui.clinicalActions.transition(rows.find(a=>String(a.id)===e.currentTarget.dataset.id),e.currentTarget.dataset.care),$('#appointment-list'));
            on('[data-change]','click',e=>ui.clinicalActions.reschedule(rows.find(a=>String(a.id)===e.currentTarget.dataset.change)),$('#appointment-list'));
            on('[data-notify-appointment]','click',e=>ui.clinicalActions.avisarReprogramacion(rows.find(a=>String(a.id)===e.currentTarget.dataset.notifyAppointment)),$('#appointment-list'));
            on('[data-cancel-record]','click',e=>ui.clinicalActions.cancel(rows.find(a=>String(a.id)===e.currentTarget.dataset.cancelRecord)),$('#appointment-list'));
            on('[data-followup]','click',e=>{state.workflowContext={parent_appointment_id:Number(e.currentTarget.dataset.followup)};state.bookingDraft=null;return navigate('CU21');},$('#appointment-list'));
            on('[data-refund-for]','click',e=>{state.workflowContext={appointment_id:Number(e.currentTarget.dataset.refundFor)};return navigate('CU20');},$('#appointment-list'));
            on('[data-patient-change]','click',e=>{
                const id=e.currentTarget.dataset.patientChange;
                modal('Solicitar cambio de horario',`<p>Su cita actual se conserva hasta que Recepción acuerde el cambio.</p><form id="patient-change">${formErrors}${textarea('Motivo y disponibilidad propuesta','reason','','required maxlength="3000"')}${submit('Enviar solicitud a Recepción')}</form>`);
                bindForm('patient-change',async data=>{await api(`appointments/${id}/reschedule-request`,'POST',data);closeModal();state.flowFeedback={message:'Solicitud enviada. Su cita original sigue vigente hasta que Recepción coordine el cambio.'};await navigate('CU10');});
            },$('#appointment-list'));
            on('[data-board-book]','click',()=>{state.bookingDraft=null;return navigate(patient?'CU18':'CU09');},$('#appointment-list'));
        }
        const pending=requests.filter(r=>r.status==='pending');
        const requestActions=r=>{
            if(!reception())return '';
            if(r.status==='pending')return `<div class="record-actions">${button('Acordar nuevo horario',`data-process-request="${r.id}"`)}${button('Rechazar con respuesta',`data-reject-request="${r.id}"`,'')}</div>`;
            if(r.status==='accepted'&&['Programada','Confirmada'].includes(r.appointment?.status))return `<div class="record-actions">${button('Avisar reprogramación',`data-notify-request="${r.id}"`,'')}</div>`;
            return '';
        };
        return {html:(state.flowFeedback?`<div class="operation-success" role="status"><span>✓</span><div><strong>Operación completada</strong><p>${esc(state.flowFeedback.message)}</p></div></div>`:'')
            +`<div class="workspace-heading"><div><h2>${physician?'Su lista de atención':patient?'Sus citas y su atención':'Agenda de la clínica'}</h2><p>${rows.length} citas ${esc(scope)}. ${reception()?'Trabaje desde la ficha de cada cita: llegada, cambio, cancelación o seguimiento.':physician?'Las acciones se habilitan según el ingreso del paciente.':'La atención presencial también puede gestionarla Recepción sin usar el portal.'}</p></div>${ui.allowedViews().includes('CU09')?button('+ Programar cita','id="board-new-booking"'):patient?button('Buscar una cita','id="board-new-booking"'):''}</div>`
            +(view==='CU10'&&requests.length?panel('Solicitudes de cambio y respuestas',requests.map(r=>`<article class="request-card"><div><strong>${esc(r.appointment?full(r.appointment.patient):code('CIT',r.appointment_id))}</strong><p>${esc(r.reason)}</p><span class="stage-badge">${esc(({pending:'Pendiente de Recepción',accepted:'Cambio acordado',rejected:'Solicitud rechazada',closed:'Solicitud cerrada'})[r.status]||r.status)}</span>${r.response?`<p>Respuesta: ${esc(r.response)}</p>`:'<small>La cita actual se conserva hasta coordinar el cambio.</small>'}</div>${requestActions(r)}</article>`).join('')):'')
            +`<section class="agenda-controls"><div class="agenda-date">${!['today','attend'].includes(mode)?`${button('‹ Día anterior','data-board-date="-1"','')}<label class="field"><span>Día de atención</span><input id="board-date" type="date" value="${f.date||today}"></label>${button('Día siguiente ›','data-board-date="1"','')}${button('Hoy','id="board-today"','')}${button('Ver todas','id="board-all"','')}`:`<strong>${esc(day(today))}</strong><span>Operaciones de la jornada</span>`}</div><label class="field"><span>Buscar en estas citas</span><input id="board-search" type="search" value="${esc(f.q)}" placeholder="Nombre, médico o código"></label></section>`
            +`<div class="status-filters" aria-label="Filtrar citas">${[['all','Todas'],['expected','Por llegar'],['waiting','En espera'],['finished','Atendidas'],['cancelled','Canceladas']].map(([key,label])=>`<button type="button" class="filter-chip ${f.stage===key?'selected':''}" data-stage="${key}" aria-pressed="${f.stage===key}">${label} <span>${counts[key]}</span></button>`).join('')}</div><div id="appointment-list" class="appointment-list"></div>`,bind:()=>{
                draw();
                on('#board-search','input',e=>{f.q=e.target.value;draw();});
                on('[data-stage]','click',e=>{f.stage=e.currentTarget.dataset.stage;document.querySelectorAll('[data-stage]').forEach(b=>{b.classList.toggle('selected',b.dataset.stage===f.stage);b.setAttribute('aria-pressed',String(b.dataset.stage===f.stage));});draw();});
                const reload=date=>{f.date=date;f.stage='all';return navigate(view);};
                on('#board-date','change',e=>{if(e.target.value)return reload(e.target.value);});
                on('[data-board-date]','click',e=>reload(shiftDate(f.date||today,Number(e.currentTarget.dataset.boardDate))));
                on('#board-today','click',()=>reload(today));on('#board-all','click',()=>{delete f.patient_id;return reload('');});
                on('#board-new-booking','click',()=>{state.bookingDraft=null;return navigate(patient?'CU18':'CU09');});
                on('[data-process-request]','click',e=>ui.clinicalActions.reschedule(pending.find(r=>String(r.id)===e.currentTarget.dataset.processRequest).appointment));
                on('[data-notify-request]','click',e=>ui.clinicalActions.avisarReprogramacion(requests.find(r=>String(r.id)===e.currentTarget.dataset.notifyRequest).appointment));
                on('[data-reject-request]','click',e=>{
                    const id=e.currentTarget.dataset.rejectRequest;
                    modal('Responder la solicitud',`<p>La cita actual se conserva. Registre el motivo y comunique la respuesta al paciente.</p><form id="reject-change">${formErrors}${textarea('Respuesta al paciente','response','','required maxlength="3000"')}${submit('Registrar rechazo y respuesta')}</form>`);
                    bindForm('reject-change',async data=>{await api(`reschedule-requests/${id}/reject`,'POST',data);closeModal();await navigate(view);toast('Respuesta registrada. La cita original se conserva.');});
                });
            }};
    }
    for(const [view,mode]of[['CU10','active'],['CU11','active'],['CU12','agenda'],['CU13','today'],['CU14','attend'],['CU15','history']])views[view]=()=>workspace(view,mode);
}
