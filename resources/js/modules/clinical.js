// CU09–CU21/CU26. La interfaz propone operaciones; el servidor verifica todos los permisos y estados.
export function installClinicalViews(ui) {
    const { $, state, views, api, esc, full, code, badge, field, select, textarea, localDate, table, button, modal,
        formErrors, on, closeModal, bindForm, navigate, toast, panel, time, notice, submit, details } = ui;
    const money = minor => minor == null ? 'Sin tarifa definida' : `Bs ${(minor / 100).toFixed(2)}`;
    const active = a => ['Programada', 'Confirmada'].includes(a.status);
    const status = value => ({pending:'Pendiente', approved:'Aprobada, devolución pendiente', completed:'Completada',
        rejected:'Rechazada', ineligible:'No admitida', held:'Retenido', processing:'Pago pendiente de conciliación',
        expired:'Expirada', confirmed:'Confirmado', failed:'Fallido', unknown:'Sin confirmación', initializing:'Preparando',
        accepted:'Aceptada', closed:'Cerrada', cancelled:'Descartada sin pago'}[value] || value);
    const paymentFields = amount => notice(`Tarifa: ${money(amount)}. Verifique la recepción del dinero antes de confirmar.`)
        + select('Medio de pago', 'payment_method', [['cash','Efectivo'],['qr','QR presencial'],['transfer','Transferencia']], 'cash')
        + field('Referencia bancaria (obligatoria para QR o transferencia)', 'bank_reference', 'text', '', 'maxlength="180"')
        + `<input type="hidden" name="payment_amount_minor" value="${esc(amount ?? '')}">
        <label class="checks"><input name="payment_confirmed" type="checkbox" value="1" required>Confirmo que verifiqué el cobro íntegro de ${esc(money(amount))}.</label>`;
    const ficha = a => {
        modal(`Ficha ${code('CIT', a.id)}`, details([
            ['Paciente',esc(full(a.patient))],['Especialidad',esc(a.specialty.name)],['Médico',esc(full(a.doctor))],
            ['Fecha',esc(a.date)],['Horario',esc(`${time(a.start_time)}–${time(a.end_time)}`)],['Estado',badge(a.status)],
            ['Pago',esc(a.complimentary ? 'Exención válida de seguimiento' : `${money(a.payment?.amount_minor)} · ${status(a.payment?.status)}`)],
        ]) + notice('Conserve el código de la cita para sus consultas.') + button('Imprimir ficha','id="print-ficha"'));
        on('#print-ficha','click',()=>{document.body.classList.add('printing-ficha');try{window.print();}finally{document.body.classList.remove('printing-ficha');}});
    };
    async function history(a) {
        modal(`Detalle ${code('CIT',a.id)}`, details([
            ['Paciente',esc(full(a.patient))],['Médico',esc(full(a.doctor))],['Especialidad',esc(a.specialty.name)],
            ['Fecha y hora',esc(`${a.date} ${time(a.start_time)}–${time(a.end_time)}`)],['Estado',badge(a.status)],
            ['Fecha de registro',esc(a.created_at || '—')],
            ['Asistencia',esc(a.attendance_at || 'Sin registrar')],['Pago',esc(a.complimentary?'Seguimiento exento':money(a.payment?.amount_minor))],
            ['Motivo',esc(a.reason || '—')],['Cancelación',esc(a.cancellation_reason || '—')],
            ['Seguimiento recomendado',esc(a.followup_suggested_at || '—')],['Indicación',esc(a.followup_note || '—')],
        ]) + table(['Fecha','Operación','Detalle'],(a.events || []).map(e=>[esc(e.created_at),esc(e.action),esc(JSON.stringify(e.details))])));
    }

    async function bookingView(followup = false, online = false) {
        const supervisor = state.user.role === 'Superadmin';
        const options = await api('medical-availability/options');
        const parents = followup ? (await api('appointments?mode=history&status=Atendida')).filter(a=>a.followup_suggested_at) : [];
        const requests = online ? await api('online-bookings') : [];
        let selected = null;
        let blocks = [];
        let queryVersion = 0;
        const operationKey = crypto.randomUUID();
        return {html: (online ? notice('La consulta de horarios no reserva el cupo. Al confirmar «Retener cupo y generar QR de pruebas» se inicia la retención; la ficha se emite al verificar el pago en el servidor.') : '')
            + (online && !options.online_payment_available ? notice('El pago online está temporalmente indisponible. Puede consultar horarios; contacte a Recepción para obtener una cita presencial.', 'error') : '')
            + (online && supervisor ? notice('Supervisión global: puede ver todas las operaciones y consultar los horarios online. La reserva personal se realiza desde una cuenta de Paciente.') : '')
            + (online ? panel(supervisor?'Operaciones online de todos los pacientes':'Mis operaciones online (últimas 50)',table(['Operación',...(supervisor?['Paciente','Médico']:[]),'Fecha','Hora','Estado','Acción'],requests.map(r=>[esc(r.id),...(supervisor?[esc(full(r.patient)),esc(full(r.doctor))]:[]),esc(r.date),esc(time(r.start_time)),esc(status(r.status)),button('Consultar',`data-online="${r.id}"`)]))) : '')
            + panel(followup?'Formalizar seguimiento':'Obtener cita',`<form id="clinical-booking">${formErrors}
                ${followup?select('Consulta atendida con recomendación','parent_appointment_id',[['','Seleccione…'],...parents.map(a=>[a.id,`${code('CIT',a.id)} · ${full(a.patient)} · ${a.followup_suggested_at}`])],'','required'):''}
                ${followup?select('Si cambia la fecha/hora sugerida, ¿quién origina el cambio?','followup_change_cause',[['patient','Paciente'],['clinic','Clínica']])+textarea('Motivo del cambio y acuerdo (obligatorio si lo origina la clínica)','followup_change_reason','','maxlength="3000"'):''}
                ${online?'':select('Paciente','patient_id',[['','Seleccione…'],...state.refs.patients.filter(p=>p.status==='Activo').map(p=>[p.id,`${p.identity||'Sin CI'} · ${full(p)}`])],'','required')}
                <div class="grid two">${select('Especialidad','specialty_id',[['','Seleccione…'],...options.specialties.map(s=>[s.id,s.name])],'','required')}
                ${select('Preferencia de médico','preferred_doctor',[['','Equilibrar según disponibilidad y carga']])}
                ${field('Fecha','date','date',localDate(),`required min="${localDate()}"`)}</div>
                <div id="clinical-slots">${notice('Seleccione especialidad y fecha para consultar cupos.')}</div>
                ${online?'':textarea('Motivo (opcional)','reason','','maxlength="3000"')}
                <div id="clinical-payment"></div>${online&&(supervisor||!options.online_payment_available)?notice(supervisor?'Vista de supervisión: no se reserva en nombre de otro usuario.':'No se puede iniciar el pago mientras el servicio esté indisponible.'):submit(online?'Retener cupo y generar QR de pruebas':'Verificar y emitir ficha')}</form>`), bind:()=>{
            const updatePayment = () => {
                const specialty = options.specialties.find(s=>String(s.id)===$('#specialty_id').value);
                const parent = parents.find(a=>String(a.id)===$('#parent_appointment_id')?.value);
                const free = parent && selected && (parent.followup_suggested_at.slice(0,16).replace('T',' ')===`${$('#date').value} ${selected.start_time}` || $('#followup_change_cause')?.value==='clinic');
                $('#clinical-payment').innerHTML = online ? notice(specialty?`Importe: ${money(specialty.fee_minor)}`:'Seleccione una especialidad para consultar el importe.') : free ? notice('Seguimiento en la fecha/hora recomendadas o con cambio causado por la clínica: exención de pago.') : paymentFields(specialty?.fee_minor);
            };
            const load = async () => {
                const version = ++queryVersion;
                selected = null;
                updatePayment();
                $('#clinical-slots').innerHTML = notice('Consultando cupos…');
                if (!$('#specialty_id').value || !$('#date').value) { $('#clinical-slots').innerHTML = notice('Seleccione especialidad y fecha.'); return; }
                const result = await api('medical-availability?'+new URLSearchParams({specialty_id:$('#specialty_id').value,date:$('#date').value,doctor_id:$('#preferred_doctor').value,modality:online?'online':'presential',...(!online?{include_demand:'1'}:{})}));
                if (version!==queryVersion || !$('#clinical-slots')) return;
                blocks = result.blocks;
                $('#clinical-slots').innerHTML = blocks.length ? table(['Médico','Inicio','Fin','Elegir'],blocks.map((b,i)=>[esc(b.doctor_name),esc(b.start_time),esc(b.end_time),button('Seleccionar',`data-choose="${i}"`)])) : notice(result.message || 'No hay cupos libres. Consulte otra fecha o profesional.');
                on('[data-choose]','click',e=>{selected=blocks[Number(e.currentTarget.dataset.choose)]; document.querySelectorAll('[data-choose]').forEach(b=>b.classList.toggle('selected',b===e.currentTarget));updatePayment();});
            };
            on('#specialty_id','change',async()=>{
                $('#preferred_doctor').innerHTML=[['','Equilibrar según disponibilidad y carga'],...options.doctors.filter(d=>d.specialties.some(s=>String(s.id)===$('#specialty_id').value)).map(d=>[d.id,full(d)])].map(([v,l])=>`<option value="${esc(v)}">${esc(l)}</option>`).join('');
                await load();
            });
            on('#preferred_doctor,#date','change',load);
            on('#followup_change_cause','change',updatePayment);
            on('#parent_appointment_id','change',async()=>{
                const parent=parents.find(a=>String(a.id)===$('#parent_appointment_id').value);
                if(!parent)return;
                $('#patient_id').value=parent.patient_id;$('#specialty_id').value=parent.specialty_id;
                $('#date').value=parent.followup_suggested_at.slice(0,10);await load();
            });
            on('[data-online]','click',async e=>showOnline(supervisor?await api(`online-bookings/${e.currentTarget.dataset.online}`):await api(`online-bookings/${e.currentTarget.dataset.online}/status`,'POST',{})));
            bindForm('clinical-booking',async data=>{
                if(online&&(supervisor||!options.online_payment_available))throw new Error('La reserva online personal no está disponible desde esta vista.');
                if(!selected)throw new Error('Seleccione un cupo disponible.');
                delete data.preferred_doctor;
                Object.assign(data,{doctor_id:selected.doctor_id,start_time:selected.start_time,idempotency_key:operationKey});
                if(online) { delete data.reason;showOnline(await api('online-bookings','POST',data)); }
                else { const a=await api('appointments','POST',data);await navigate(followup?'CU21':'CU09');ficha(a); }
            });
            updatePayment();
        }};
    }

    async function showOnline(result) {
        if(result.appointment){await navigate('CU18');ficha(result.appointment);return;}
        const r=result.booking,p=result.payment;
        if(r.status==='cancelled') { await navigate('CU18');modal('Solicitud descartada',notice('Se liberó la retención después de comprobar que no existen pagos en proceso. No se creó una cita ni una ficha.'));return; }
        if(r.status==='expired') { await navigate('CU18');modal('Solicitud expirada',notice('La solicitud venció sin un pago confirmado ni en proceso. Puede consultar disponibilidad e iniciar otra solicitud.'));return; }
        const image=String(result.qr_image||'').replace(/^data:image\/png;base64,/, '');
        const qr=/^[A-Za-z0-9+/=\r\n]+$/.test(image)?`<img data-payment-qr="${r.id}-${r.attempt}" src="data:image/png;base64,${image}" alt="QR de pago de pruebas" style="max-width:280px;width:100%">`:'';
        modal('Pago online de pruebas',notice(`Operación ${r.id} · Intento ${r.attempt}/2 · ${money(r.amount_minor)}`)
            +notice(`Solicitud: ${status(r.status)}. Pago: ${status(p?.status || 'Sin pago')}. Límite del intento: ${new Date(r.expires_at).toLocaleString('es-BO')}.`)
            +(p?.status==='pending'&&new Date(r.expires_at)>new Date()?qr:'')
            +notice('La aplicación comprueba el pago en el servidor. Si la pasarela mantiene una transacción pendiente, conserva el cupo hasta conciliarla y bloquea un segundo cobro.')
            +button('Consultar estado',`data-check-payment="${r.id}"`)
            +(state.user.role==='Paciente'&&r.status==='held'&&r.attempt<2?button('Segundo intento',`data-retry-payment="${r.id}"`):'')
            +(state.user.role==='Paciente'&&['held','processing'].includes(r.status)?button('Descartar solicitud sin pago',`data-cancel-booking="${r.id}"`,'danger'):'')
            +notice('Cerrar esta ventana no cancela una transacción pendiente. Para descartar una solicitud, el servidor debe comprobar antes que no exista un cobro en proceso.'));
        on('[data-check-payment]','click',async()=>showOnline(state.user.role==='Superadmin'?await api(`online-bookings/${r.id}`):await api(`online-bookings/${r.id}/status`,'POST',{})));
        on('[data-retry-payment]','click',async()=>showOnline(await api(`online-bookings/${r.id}/retry`,'POST',{})));
        on('[data-cancel-booking]','click',async()=>showOnline(await api(`online-bookings/${r.id}/cancel`,'POST',{})));
        if(p?.status==='pending')setTimeout(()=>document.querySelector(`[data-payment-qr="${r.id}-${r.attempt}"]`)?.remove(),Math.max(0,new Date(r.expires_at).getTime()-Date.now()));
    }

    async function reschedule(a) {
        modal(`Reprogramar ${code('CIT',a.id)}`,`<form id="clinical-reschedule">${formErrors}<div class="grid two">
            ${select('Médico de la misma especialidad','doctor_id',state.refs.doctors.filter(d=>d.status==='Activo'&&d.specialties.some(s=>s.id===a.specialty_id)).map(d=>[d.id,full(d)]),a.doctor_id,'required')}
            ${field('Nueva fecha','date','date',a.date,`required min="${localDate()}"`)}</div>${select('Nuevo horario','start_time',[['','Consulte horarios']],'','required')}
            ${select('Causa del cambio','cause',[['patient','Solicitud del paciente'],['clinic','Causa atribuible a la clínica']])}
            ${textarea('Motivo y acuerdo recibido por WhatsApp','change_reason','','required maxlength="3000"')}
            <label class="checks"><input type="checkbox" name="patient_accepted" value="1" required>Registré la aceptación del paciente.</label>
            <div id="reschedule-payment"></div>${submit('Confirmar reprogramación')}</form>`);
        const payment=()=>{$('#reschedule-payment').innerHTML=(a.complimentary&&$('#cause').value==='patient')||(!a.complimentary&&a.payment?.status!=='confirmed')?paymentFields(a.specialty.fee_minor):notice('Se conserva el pago original o la exención cuando corresponde.');};
        const load=async()=>{
            const result=await api('availability?'+new URLSearchParams({doctor_id:$('#doctor_id').value,date:$('#date').value,exclude:a.id,modality:a.modality}));
            if(!$('#clinical-reschedule'))return;
            $('#start_time').innerHTML=[['','Seleccione…'],...result.filter(b=>b.status==='Disponible').map(b=>[b.start,`${b.start}–${b.end}`])].map(([v,l])=>`<option value="${esc(v)}">${esc(l)}</option>`).join('');
        };
        on('#doctor_id,#date','change',load);on('#cause','change',payment);payment();await load();
        bindForm('clinical-reschedule',async data=>{await api(`appointments/${a.id}/reschedule`,'PUT',data);closeModal();await navigate(state.view);toast('Reprogramación registrada. Comunique la respuesta por WhatsApp.');});
    }

    function requestForm(title, endpoint, fields, after=()=>navigate(state.view)) {
        modal(title,`<form id="clinical-operation">${formErrors}${fields}${submit('Guardar')}</form>`);
        bindForm('clinical-operation',async data=>{await api(endpoint,'POST',data);closeModal();await after();toast('Operación registrada.');});
    }
    function cancel(a) {
        requestForm(`Cancelar ${code('CIT',a.id)}`,`appointments/${a.id}/cancel`,select('Causa','cause',[['patient','Paciente'],['clinic','Clínica']])+textarea('Motivo','reason','','required maxlength="3000"')+notice('Se libera el horario y se conserva el historial. Puede solicitarse reembolso por separado.'));
    }
    function transition(a, action) {
        let fields='';
        if(action==='attend') fields='<input type="hidden" name="consultation_finished" value="1">'+notice('Confirme únicamente cuando la consulta haya finalizado.')+field('Fecha y hora sugeridas para seguimiento (opcional)','followup_suggested_at','datetime-local')+textarea('Indicación de seguimiento (opcional)','followup_note');
        if(action==='absent') fields='<label class="checks"><input type="checkbox" name="definitive" value="1" required>Se determinó que no se atenderá ni reprogramará esta cita.</label>'+textarea('Motivo','reason','','required maxlength="3000"');
        if(action==='attendance') fields=a.requires_arrival_payment?notice(a.complimentary?'El seguimiento perdió la gratuidad. Verifique el pago antes de continuar.':'No consta un pago confirmado. Verifique el cobro antes de continuar.')+paymentFields(a.specialty.fee_minor):notice('Registra la llegada del paciente y cambia la cita a Confirmada.');
        if(action==='missed-call') fields=notice('Registra pérdida del turno de llamado; no declara inasistencia definitiva.');
        requestForm('Registrar operación de atención',`appointments/${a.id}/${action}`,fields);
    }

    const filters={};
    async function appointmentView(view, mode) {
        const patient=state.user.role==='Paciente',reception=['Superadmin','Recepcionista'].includes(state.user.role),physician=state.user.role==='Médico';
        const f=filters[view]||{};
        const rows=await api('appointments?'+new URLSearchParams({mode,...f}));
        const requests=view==='CU10'?await api('reschedule-requests'):[];
        return {html:(view==='CU14'&&state.user.role==='Superadmin'?notice('Supervisión de las consultas de hoy. Únicamente el médico responsable puede registrar la consulta como atendida.'):'')
            +(view==='CU10'?panel('Solicitudes de reprogramación',notice(reception?'Las solicitudes nuevas aparecen aquí. Coordine por WhatsApp antes de cambiar la cita.':'Solicitar un cambio conserva la cita original hasta la resolución de Recepción.')+table(['Cita','Motivo','Estado','Respuesta','Acción'],requests.map(r=>[esc(code('CIT',r.appointment_id)),esc(r.reason),esc(status(r.status)),esc(r.response||'—'),reception&&r.status==='pending'?button('Procesar',`data-process="${r.id}"`)+button('Rechazar',`data-reject="${r.id}"`):'—']))):'')
            +panel('Buscar citas',`<form id="clinical-filters">${formErrors}<div class="grid two">${field('Código, paciente o médico','q','search',f.q||'')}${field('Fecha','date','date',f.date||'')}${select('Estado','status',[['','Todos los estados'],...['Programada','Confirmada','Atendida','Cancelada','Inasistencia']],f.status||'')}${patient||physician?'':select('Médico','doctor_id',[['','Todos los médicos'],...state.refs.doctors.map(d=>[d.id,full(d)])],f.doctor_id||'')}</div>${submit('Buscar')}</form>`)
            +(mode==='today'?notice('La presencia confirma la cita. La ausencia al llamado se distingue de la inasistencia definitiva; no existe una tolerancia fija.'):'')
            +panel('Citas',table(['Cita','Paciente','Médico','Especialidad','Fecha','Hora','Estado','Acciones'],rows.map(a=>{
                let actions=button('Detalle',`data-detail="${a.id}"`);
                if(active(a)){
                    if(view==='CU10')actions+=button(patient?'Solicitar cambio':'Reprogramar',`data-reschedule="${a.id}"`);
                    if(reception&&view==='CU11')actions+=button('Cancelar',`data-cancel="${a.id}"`,'danger');
                    if(reception&&mode==='today'&&(!a.attendance_at||a.requires_arrival_payment))actions+=button(a.attendance_at?'Verificar cobro pendiente':'Registrar presencia',`data-op="attendance" data-id="${a.id}"`);
                    if(reception&&mode==='today'&&!a.attendance_at)actions+=button('Inasistencia definitiva',`data-op="absent" data-id="${a.id}"`);
                    if(physician&&mode==='attend'&&!a.attendance_at)actions+=button('Ausente al llamado',`data-op="missed-call" data-id="${a.id}"`);
                }
                if(physician&&mode==='attend'&&a.status==='Confirmada'&&a.attendance_at)actions+=button('Consulta finalizada',`data-op="attend" data-id="${a.id}"`,'success');
                return [esc(code('CIT',a.id)),esc(full(a.patient))+(a.attendance_at?`<br><small>${esc(a.arrival_late?'Llegada tardía: ':'Presente: ')}${esc(a.attendance_at)}</small>`:''),esc(full(a.doctor)),esc(a.specialty.name),esc(a.date),esc(`${time(a.start_time)}–${time(a.end_time)}`),badge(a.status),actions];
            }))),bind:()=>{
            bindForm('clinical-filters',async data=>{filters[view]=Object.fromEntries(Object.entries(data).filter(([,v])=>v));await navigate(view);});
            on('[data-detail]','click',e=>history(rows.find(a=>a.id===Number(e.currentTarget.dataset.detail))));
            on('[data-cancel]','click',e=>cancel(rows.find(a=>a.id===Number(e.currentTarget.dataset.cancel))));
            on('[data-reschedule]','click',e=>{
                const a=rows.find(a=>a.id===Number(e.currentTarget.dataset.reschedule));
                if(patient)requestForm('Solicitar cambio',`appointments/${a.id}/reschedule-request`,textarea('Motivo y disponibilidad propuesta','reason','','required maxlength="3000"'));
                else return reschedule(a);
            });
            on('[data-process]','click',e=>reschedule(requests.find(r=>r.id===Number(e.currentTarget.dataset.process)).appointment));
            on('[data-reject]','click',e=>requestForm('Rechazar solicitud',`reschedule-requests/${e.currentTarget.dataset.reject}/reject`,textarea('Respuesta que comunicará por WhatsApp','response','','required maxlength="3000"')));
            on('[data-op]','click',e=>transition(rows.find(a=>a.id===Number(e.currentTarget.dataset.id)),e.currentTarget.dataset.op));
        }};
    }

    async function refunds(admin=false) {
        const rows=await api('refunds');
        const cancelled=admin?[]:await api('appointments?mode=history&status=Cancelada');
        return {html:(!admin?panel('Solicitar reembolso',notice('La cita debe haber sido cancelada por Recepción. El sistema aplica la política de anticipación, gracia y causas de la clínica.')+`<form id="refund-request">${formErrors}${select('Cita cancelada con pago','appointment_id',[['','Seleccione…'],...cancelled.filter(a=>a.payment_id).map(a=>[a.id,`${code('CIT',a.id)} · ${a.date} · ${money(a.payment?.amount_minor)}`])],'','required')}${textarea('Motivo','reason','','required maxlength="3000"')}${submit('Presentar solicitud')}</form>`):'')
            +panel('Solicitudes de reembolso',table(['Solicitud','Cita','Importe','Estado','Motivo / decisión','Ejecución','Acción'],rows.map(r=>[esc(r.id),esc(code('CIT',r.appointment_id)),esc(money(r.amount_minor)),esc(status(r.status)),esc(`${r.reason} ${r.decision_reason||''}`),esc(r.completed_at?`${r.executor} · ${r.method} · ${r.completed_at} · ${r.reference||''}`:'Pendiente'),admin?(r.status==='pending'?button('Resolver',`data-resolve-refund="${r.id}"`):r.status==='approved'?button('Registrar devolución externa',`data-complete-refund="${r.id}"`):'—'):'—']))),bind:()=>{
            if(!admin)bindForm('refund-request',async data=>{const id=data.appointment_id;delete data.appointment_id;const r=await api(`appointments/${id}/refund`,'POST',data);await navigate('CU20');toast(`Solicitud ${status(r.status)}. ${r.decision_reason||''}`);});
            on('[data-resolve-refund]','click',e=>requestForm('Decisión administrativa',`refunds/${e.currentTarget.dataset.resolveRefund}/decision`,select('Decisión','decision',[['approve','Aprobar'],['reject','Rechazar']])+textarea('Motivo (obligatorio al rechazar)','reason','','maxlength="3000"')+notice('La aprobación conserva la devolución pendiente; no declara que se entregó dinero.')));
            on('[data-complete-refund]','click',e=>{
                const r=rows.find(r=>r.id===Number(e.currentTarget.dataset.completeRefund));
                requestForm('Registrar devolución realizada fuera del sistema',`refunds/${r.id}/complete`,notice(`Importe completo: ${money(r.amount_minor)}. Se registran los datos declarados; la aplicación no verifica la entrega física.`)
                    +field('Nombre de quien devolvió el dinero','executor','text','','required maxlength="255"')+field('Fecha y hora de devolución','completed_at','datetime-local','','required')
                    +field('Medio utilizado','method','text','','required maxlength="80"')+field('Referencia o comprobante (si existe)','reference','text','','maxlength="255"')+`<input type="hidden" name="amount_minor" value="${r.amount_minor}">`);
            });
        }};
    }
    views.CU09=()=>bookingView();views.CU18=()=>bookingView(false,true);views.CU21=()=>bookingView(true);
    views.CU10=()=>appointmentView('CU10','active');views.CU11=()=>appointmentView('CU11','active');views.CU12=()=>appointmentView('CU12','agenda');
    views.CU13=()=>appointmentView('CU13','today');views.CU14=()=>appointmentView('CU14','attend');views.CU15=()=>appointmentView('CU15','history');
    views.CU20=()=>refunds();views.CU26=()=>refunds(true);
    // CU17 utiliza estas mismas operaciones, con los permisos de Recepción verificados en servidor.
    ui.clinicalActions={reschedule,cancel};
}
