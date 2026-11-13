/** Acciones compartidas: ficha, historial, cambio de horario, asistencia, consulta y conciliación de PayPal. */
// CU09–CU21/CU26. La interfaz propone operaciones; el servidor verifica todos los permisos y estados.
import {enlaceReprogramacion, mensajeReprogramacion} from '../lib/whatsapp.js';
export function installClinicalActions(ui) {
    const { $, state, api, esc, full, code, badge, field, select, textarea, localDate, table, button, modal,
        formErrors, on, closeModal, bindForm, navigate, toast, panel, time, notice, submit, details } = ui;
    const money = minor => minor == null ? 'Sin tarifa definida' : `Bs ${(minor / 100).toFixed(2)}`;
    // Una comprobación se cancela al cerrar el diálogo o cambiar de identidad; no quedan pagos consultándose en segundo plano.
    let onlineTimer=null,onlineVersion=0;
    const stopOnlineWatch=()=>{clearTimeout(onlineTimer);onlineTimer=null;onlineVersion++;};
    $('#modal').addEventListener('close',stopOnlineWatch);
    window.addEventListener('clinic:identity-changed',stopOnlineWatch);
    const displayDate = value => {
        if (!value) return '—';
        const date = new Date(`${value.slice(0,10)}T12:00:00Z`);
        return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('es-BO',{dateStyle:'medium',timeZone:'America/La_Paz'});
    };
    const displayDateTime = value => {
        if (!value) return '—';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString('es-BO',{dateStyle:'medium',timeStyle:'short',timeZone:'America/La_Paz'});
    };
    const status = value => ({pending:'Pendiente', approved:'Aprobada, devolución pendiente', completed:'Completada',
        rejected:'Rechazada', ineligible:'No admitida', held:'Retenido', processing:'Pago pendiente de conciliación',
        expired:'Expirada', confirmed:'Confirmado', failed:'Fallido', unknown:'Sin confirmación', initializing:'Preparando',
        accepted:'Aceptada', closed:'Cerrada', cancelled:'Descartada sin pago'}[value] || value);
    const appointmentActions = {
        Creación:'Cita programada',
        Reprogramación:'Cita reprogramada',
        cancel:'Cita cancelada',
        attendance:'Asistencia registrada',
        absent:'Inasistencia registrada',
        attend:'Consulta finalizada',
        'missed-call':'Turno de llamado registrado',
    };
    const scheduleText = schedule => schedule?.date
        ? `${displayDate(schedule.date)} · ${time(schedule.start_time)}–${time(schedule.end_time)}`
        : '';
    const eventDetails = event => {
        const detail = event.details || {};
        const parts = [];

        if (detail.before && typeof detail.before === 'object') {
            const previous = scheduleText(detail.before);
            if (previous) parts.push(`Programación anterior: ${previous}`);
        } else if (detail.before && detail.after && detail.before !== detail.after) {
            parts.push(`${detail.before} → ${detail.after}`);
        }
        if (detail.after && typeof detail.after === 'object') {
            const next = scheduleText(detail.after);
            if (next) parts.push(`Nueva programación: ${next}`);
        }
        if (detail.before?.doctor_id && detail.after?.doctor_id && detail.before.doctor_id !== detail.after.doctor_id) {
            parts.push('También se actualizó el médico asignado.');
        }
        if (detail.reason) parts.push(`Motivo: ${detail.reason}`);
        if (detail.cause) parts.push(`Origen: ${{patient:'Solicitud del paciente',clinic:'Causa de la clínica'}[detail.cause] || detail.cause}`);

        return parts.length ? parts.join(' · ') : 'Sin detalles adicionales.';
    };
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
            ['Medio de pago',esc(a.complimentary ? 'Seguimiento gratuito' : ({cash:'Efectivo',qr:'QR presencial',transfer:'Transferencia',paypal:'PayPal Sandbox'}[a.payment?.method] || a.payment?.method || '—'))],
            ['Referencia de pago o exención',esc(a.complimentary
                ? (a.parent_appointment_id ? `Consulta original ${code('CIT',a.parent_appointment_id)}` : 'Exención de seguimiento validada')
                : (a.payment?.bank_reference || a.payment?.reference || (a.payment?.id ? `Pago n.º ${a.payment.id}` : '—')))],
        ]) + notice('Conserve el código de la cita para sus consultas.') + button('Imprimir ficha','id="print-ficha"'));
        on('#print-ficha','click',()=>{document.body.classList.add('printing-ficha');try{window.print();}finally{document.body.classList.remove('printing-ficha');}});
        if(ui.allowedViews().includes('CU13')){
            $('#modal-body').insertAdjacentHTML('beforeend',`<div class="outcome-hint"><strong>La cita ya está programada</strong><p>El día de la atención, registre la llegada del paciente desde la agenda. No necesita crearle una cuenta.</p>${button('Abrir agenda de la clínica','id="after-booking-agenda"')}</div>`);
            on('#after-booking-agenda','click',()=>{state.openAppointmentId=a.id;state.workflowContext={date:a.date};return navigate('CU12');});
        }
    };
    /** Presenta historial con nombres legibles y limita datos según el rol. */
    async function history(a) {
        const patient=state.user.role==='Paciente';
        const physician=state.user.role==='Médico';
        const entries=[
            ...(!patient?[['Paciente',esc(full(a.patient))]]:[]),['Médico',esc(full(a.doctor))],['Especialidad',esc(a.specialty.name)],
            ['Fecha y hora',esc(`${displayDate(a.date)} ${time(a.start_time)}–${time(a.end_time)}`)],['Estado',badge(a.status)],
            ['Fecha de registro',esc(displayDateTime(a.created_at))],
            ['Asistencia',esc(a.attendance_at ? displayDateTime(a.attendance_at) : 'Sin registrar')],
            ...(!physician?[['Pago',esc(a.complimentary?'Seguimiento exento':money(a.payment?.amount_minor))]]:[]),
            ['Motivo',esc(a.reason || '—')],['Cancelación',esc(a.cancellation_reason || '—')],
            ['Seguimiento recomendado',esc(displayDate(a.followup_suggested_at))],['Indicación',esc(a.followup_note || '—')],
        ];
        const changes=(a.events || []).map(event=>[
            esc(displayDateTime(event.created_at)),
            esc(appointmentActions[event.action] || event.action),
            esc(eventDetails(event)),
        ]);

        modal(`Detalle ${code('CIT',a.id)}`,details(entries)
            + (changes.length?table(['Fecha','Cambio registrado','Información'],changes):notice('Esta cita no tiene cambios adicionales registrados.')));
    }

    /** Presenta estado del servidor y enlace Sandbox permitido; no confirma por parámetros de URL. */
    async function showOnline(result) {
        stopOnlineWatch();
        if(result.appointment){await navigate('CU18');ficha(result.appointment);return;}
        const r=result.booking,p=result.payment;
        if(r.status==='cancelled') { await navigate('CU18');modal('Solicitud descartada',notice('Se liberó la retención después de comprobar que no existen pagos en proceso. No se creó una cita ni una ficha.'));return; }
        if(r.status==='expired') { await navigate('CU18');modal('Solicitud expirada',notice('La solicitud venció sin un pago confirmado ni en proceso. Puede consultar disponibilidad e iniciar otra solicitud.'));return; }
        let approval='';
        try {const u=new URL(result.approval_url||'');if(u.protocol==='https:'&&u.hostname==='www.sandbox.paypal.com'&&u.pathname==='/checkoutnow'&&!u.username&&!u.password)approval=u.href;}catch{}
        modal('Pago con PayPal Sandbox',notice(`Operación ${r.id} · Intento ${r.attempt}/2 · ${money(r.amount_minor)}`)
            +(p?.provider_amount_minor?notice(`PayPal: USD ${(p.provider_amount_minor/100).toFixed(2)} de prueba · Tasa fijada: ${p.exchange_rate} Bs/USD.`):'')
            +notice(`Solicitud: ${status(r.status)}. Pago: ${status(p?.status || 'Sin pago')}. Límite del intento: ${new Date(r.expires_at).toLocaleString('es-BO')}.`)
            +(state.user.role==='Paciente'&&p?.status==='pending'&&approval?`<a class="btn primary" href="${esc(approval)}" rel="noreferrer">Continuar en PayPal Sandbox</a>`:'')
            +notice('La aplicación comprueba el pago en el servidor. Si la pasarela mantiene una transacción pendiente, conserva el cupo hasta conciliarla y bloquea un segundo cobro.')
            +button('Consultar estado',`data-check-payment="${r.id}"`)
            +(state.user.role==='Paciente'&&r.status==='held'&&r.attempt<2?button('Segundo intento',`data-retry-payment="${r.id}"`):'')
            +(state.user.role==='Paciente'&&['held','processing'].includes(r.status)?button('Descartar solicitud sin pago',`data-cancel-booking="${r.id}"`,'danger'):'')
            +notice('Cerrar esta ventana no cancela una transacción pendiente. Para descartar una solicitud, el servidor debe comprobar antes que no exista un cobro en proceso.'));
        const owner=state.user.id,version=onlineVersion;
        const current=()=>$('#modal').open&&state.user?.id===owner&&state.view==='CU18'&&onlineVersion===version;
        const check=async()=>{
            clearTimeout(onlineTimer);
            const control=$('[data-check-payment]');if(control?.disabled)return;
            if(control){control.disabled=true;control.textContent='Comprobando con el servidor…';}
            try{
                const update=state.user.role==='Superadmin'?await api(`online-bookings/${r.id}`):await api(`online-bookings/${r.id}/status`,'POST',{});
                if(current())await showOnline(update);
            }catch(error){if(current()){toast(error.message,true);schedule();}}
            finally{if(control?.isConnected){control.disabled=false;control.textContent='Consultar estado';}}
        };
        const schedule=()=>{
            if(!current()||!['held','processing'].includes(r.status))return;
            const target=p?.next_check_at?Date.parse(p.next_check_at)-Date.now()+1000:15000;
            const delay=Math.min(60000,Math.max(15000,Number.isFinite(target)?target:15000));
            $('#online-refresh-hint')?.remove();
            $('#modal-body').insertAdjacentHTML('beforeend',`<p id="online-refresh-hint" class="help-text" role="status">Comprobación automática en aproximadamente ${Math.ceil(delay/1000)} segundos. La ficha aparecerá cuando el servidor confirme el pago. No vuelva a pagar esta operación.</p>`);
            onlineTimer=setTimeout(check,delay);
        };
        on('[data-check-payment]','click',check);
        on('[data-retry-payment]','click',async()=>showOnline(await api(`online-bookings/${r.id}/retry`,'POST',{})));
        on('[data-cancel-booking]','click',async()=>showOnline(await api(`online-bookings/${r.id}/cancel`,'POST',{})));
        schedule();
    }

    /** Abre un borrador manual con el horario que ya confirmó el servidor. */
    function avisarReprogramacion(cita) {
        const enlace = enlaceReprogramacion(cita);
        modal('Avisar reprogramación aceptada', details([
            ['Paciente', esc(full(cita.patient))], ['Teléfono', esc(cita.patient?.phone || 'Sin teléfono')],
        ]) + textarea('Mensaje preparado', 'mensaje_whatsapp', mensajeReprogramacion(cita), 'readonly')
            + (enlace
                ? '<p>Abra WhatsApp, revise el mensaje y pulse Enviar para avisar al paciente.</p>'
                    + `<a class="btn primary" data-whatsapp-reprogramacion href="${esc(enlace)}" target="_blank" rel="noopener noreferrer">Abrir WhatsApp</a>`
                : notice('Actualice el teléfono móvil del paciente para abrir WhatsApp. Use 8 dígitos para Bolivia o el formato internacional con + y código de país.')));
    }

    /** Solicita un nuevo horario y un acuerdo antes de modificar la cita original. */
    async function reschedule(a) {
        modal(`Reprogramar ${code('CIT',a.id)}`,`<form id="clinical-reschedule">${formErrors}<div class="grid two">
            ${select('Médico de la misma especialidad','doctor_id',state.refs.doctors.filter(d=>d.status==='Activo'&&d.specialties.some(s=>s.id===a.specialty_id)).map(d=>[d.id,full(d)]),a.doctor_id,'required')}
            ${field('Nueva fecha','date','date',a.date,`required min="${localDate()}"`)}</div>${select('Nuevo horario','start_time',[['','Consulte horarios']],'','required')}
            ${select('Causa del cambio','cause',[['patient','Solicitud del paciente'],['clinic','Causa atribuible a la clínica']])}
            ${textarea('Motivo y acuerdo recibido por WhatsApp','change_reason','','required maxlength="3000"')}
            <label class="checks"><input type="checkbox" name="patient_accepted" value="1" required>Registré la aceptación del paciente.</label>
            <div id="reschedule-payment"></div>${submit('Confirmar reprogramación')}</form>`);
        const form=$('#clinical-reschedule');
        const input=name=>form.querySelector(`[name="${name}"]`);
        let requestVersion=0;
        const payment=()=>{$('#reschedule-payment').innerHTML=(a.complimentary&&input('cause').value==='patient')||(!a.complimentary&&a.payment?.status!=='confirmed')?paymentFields(a.specialty.fee_minor):notice('Se conserva el pago original o la exención cuando corresponde.');};
        const load=async()=>{
            const version=++requestVersion;
            input('start_time').disabled=true;
            input('start_time').innerHTML='<option value="">Consultando horarios…</option>';
            try {
                const result=await api('availability?'+new URLSearchParams({doctor_id:input('doctor_id').value,date:input('date').value,exclude:a.id,modality:a.modality}));
                if(!form.isConnected||version!==requestVersion)return;
                const slots=result.filter(b=>b.status==='Disponible');
                input('start_time').innerHTML=[['',slots.length?'Seleccione un horario':'No hay horarios libres; cambie la fecha'],...slots.map(b=>[b.start,`${b.start}–${b.end}`])].map(([v,l])=>`<option value="${esc(v)}">${esc(l)}</option>`).join('');
            }finally{if(form.isConnected&&version===requestVersion)input('start_time').disabled=false;}
        };
        on('[name=doctor_id],[name=date]','change',load,form);on('[name=cause]','change',payment,form);payment();await load();
        bindForm('clinical-reschedule',async data=>{const cita=await api(`appointments/${a.id}/reschedule`,'PUT',data);closeModal();await navigate(state.view);avisarReprogramacion(cita);});
    }

    /** Comparte validación, envío y resultado de operaciones clínicas breves. */
    function requestForm(title, endpoint, fields, after=()=>navigate(state.view)) {
        modal(title,`<form id="clinical-operation">${formErrors}${fields}${submit('Guardar')}</form>`);
        bindForm('clinical-operation',async data=>{const result=await api(endpoint,'POST',data);const action=endpoint.split('/').at(-1);state.flowFeedback={appointmentId:result.id,message:({attendance:'Llegada registrada. El paciente ya está en la lista de espera para el médico.',attend:'Consulta finalizada. La atención quedó en el historial; puede programar el seguimiento indicado.',cancel:'Cita cancelada. El horario se liberó; revise si corresponde solicitar un reembolso.',absent:'Inasistencia registrada y conservada en el historial.'})[action]||'Operación registrada correctamente.'};closeModal();await after();toast(state.flowFeedback.message);});
    }
    function cancel(a) {
        requestForm(`Cancelar ${code('CIT',a.id)}`,`appointments/${a.id}/cancel`,select('Causa','cause',[['patient','Paciente'],['clinic','Clínica']])+textarea('Motivo','reason','','required maxlength="3000"')+notice('Se libera el horario y se conserva el historial. Puede solicitarse reembolso por separado.'));
    }
    /** Abre la confirmación correspondiente al cambio de estado solicitado. */
    function transition(a, action) {
        let fields='';
        if(action==='attend') fields='<input type="hidden" name="consultation_finished" value="1">'+notice('Confirme únicamente cuando la consulta haya finalizado.')+field('Fecha y hora sugeridas para seguimiento (opcional)','followup_suggested_at','datetime-local')+textarea('Indicación de seguimiento (opcional)','followup_note');
        if(action==='absent') fields='<label class="checks"><input type="checkbox" name="definitive" value="1" required>Se determinó que no se atenderá ni reprogramará esta cita.</label>'+textarea('Motivo','reason','','required maxlength="3000"');
        if(action==='attendance') fields=a.requires_arrival_payment?notice(a.complimentary?'El seguimiento perdió la gratuidad. Verifique el pago antes de continuar.':'No consta un pago confirmado. Verifique el cobro antes de continuar.')+paymentFields(a.specialty.fee_minor):notice('Registra la llegada del paciente y cambia la cita a Confirmada.');
        if(action==='missed-call') fields=notice('Registra pérdida del turno de llamado; no declara inasistencia definitiva.');
        requestForm(`${({attendance:'Registrar asistencia',attend:'Finalizar consulta',absent:'Registrar inasistencia','missed-call':'Registrar ausencia al llamado'})[action]} · ${full(a.patient)}`,`appointments/${a.id}/${action}`,`<p class="dialog-context">${esc(a.date)} · ${esc(time(a.start_time))} · ${esc(a.specialty.name)}</p>`+fields);
    }

    // CU17 utiliza estas mismas operaciones, con los permisos de Recepción verificados en servidor.
    ui.clinicalActions={reschedule,cancel,ficha,history,transition,showOnline,avisarReprogramacion};
}
