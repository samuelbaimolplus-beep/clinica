/** Acciones compartidas: ficha, historial, cambio de horario, asistencia, consulta y conciliación de PayPal. */
// CU09–CU21/CU26. La interfaz propone operaciones; el servidor verifica todos los permisos y estados.
import {enlaceReprogramacion, mensajeReprogramacion} from '../lib/whatsapp.js';
export function instalarAccionesClinicas(interfaz) {
    const { $, estadoLocal: estadoLocal, clienteApi: clienteApi, escapar: escapar, nombreCompleto: nombreCompleto, codigo: codigo, distintivo: distintivo, campo: campo, seleccionar: seleccionar, areaTexto: areaTexto, fechaLocalLocal: fechaLocalLocal, tabla: tabla, boton: boton, modal,
        erroresFormulario: erroresFormulario, enlazarEvento: enlazarEvento, cerrarModal: cerrarModal, enlazarFormulario: enlazarFormulario, navegar: navegar, notificacion: notificacion, panel, hora: hora, aviso: aviso, botonGuardar: botonGuardar, detalles: detalles } = interfaz;
    const importeMonetario = menor => menor == null ? 'Sin tarifa definida' : `Bs ${(menor / 100).toFixed(2)}`;
    // Una comprobación se cancela al cerrar el diálogo o cambiar de identidad; no quedan pagos consultándose en segundo plano.
    let temporizadorOnline=null,versionOnline=0;
    const detenerConsultaOnline=()=>{clearTimeout(temporizadorOnline);temporizadorOnline=null;versionOnline++;};
    $('#modal').addEventListener('close',detenerConsultaOnline);
    window.addEventListener('clinic:identity-changed',detenerConsultaOnline);
    const mostrarFecha = valorDato => {
        if (!valorDato) return '—';
        const fechaLocal = new Date(`${valorDato.slice(0,10)}T12:00:00Z`);
        return Number.isNaN(fechaLocal.getTime()) ? valorDato : fechaLocal.toLocaleDateString('es-BO',{dateStyle:'medium',timeZone:'America/La_Paz'});
    };
    const mostrarFechaHora = valorDato => {
        if (!valorDato) return '—';
        const fechaLocal = new Date(valorDato);
        return Number.isNaN(fechaLocal.getTime()) ? valorDato : fechaLocal.toLocaleString('es-BO',{dateStyle:'medium',timeStyle:'short',timeZone:'America/La_Paz'});
    };
    const situacion = valorDato => ({pending:'Pendiente', approved:'Aprobada, devolución pendiente', completed:'Completada',
        rejected:'Rechazada', ineligible:'No admitida', held:'Retenido', processing:'Pago pendiente de conciliación',
        expired:'Expirada', confirmed:'Confirmado', failed:'Fallido', unknown:'Sin confirmación', initializing:'Preparando',
        accepted:'Aceptada', closed:'Cerrada', cancelled:'Descartada sin pago'}[valorDato] || valorDato);
    const accionesCita = {
        Creación:'Cita programada',
        Reprogramación:'Cita reprogramada',
        cancel:'Cita cancelada',
        attendance:'Asistencia registrada',
        absent:'Inasistencia registrada',
        attend:'Consulta finalizada',
        'missed-call':'Turno de llamado registrado',
    };
    const textoHorario = horario => horario?.date
        ? `${mostrarFecha(horario.date)} · ${hora(horario.start_time)}–${hora(horario.end_time)}`
        : '';
    const detallesEvento = evento => {
        const detalle = evento.details || {};
        const partesTexto = [];

        if (detalle.before && typeof detalle.before === 'object') {
            const anterior = textoHorario(detalle.before);
            if (anterior) partesTexto.push(`Programación anterior: ${anterior}`);
        } else if (detalle.before && detalle.after && detalle.before !== detalle.after) {
            partesTexto.push(`${detalle.before} → ${detalle.after}`);
        }
        if (detalle.after && typeof detalle.after === 'object') {
            const siguiente = textoHorario(detalle.after);
            if (siguiente) partesTexto.push(`Nueva programación: ${siguiente}`);
        }
        if (detalle.before?.doctor_id && detalle.after?.doctor_id && detalle.before.doctor_id !== detalle.after.doctor_id) {
            partesTexto.push('También se actualizó el médico asignado.');
        }
        if (detalle.reason) partesTexto.push(`Motivo: ${detalle.reason}`);
        if (detalle.cause) partesTexto.push(`Origen: ${{patient:'Solicitud del paciente',clinic:'Causa de la clínica'}[detalle.cause] || detalle.cause}`);

        return partesTexto.length ? partesTexto.join(' · ') : 'Sin detalles adicionales.';
    };
    const camposPago = importe => aviso(`Tarifa: ${importeMonetario(importe)}. Verifique la recepción del dinero antes de confirmar.`)
        + seleccionar('Medio de pago', 'payment_method', [['cash','Efectivo'],['qr','QR presencial'],['transfer','Transferencia']], 'cash')
        + campo('Referencia bancaria (obligatoria para QR o transferencia)', 'bank_reference', 'text', '', 'maxlength="180"')
        + `<input type="hidden" name="payment_amount_minor" value="${escapar(importe ?? '')}">
        <label class="checks"><input name="payment_confirmed" type="checkbox" value="1" required>Confirmo que verifiqué el cobro íntegro de ${escapar(importeMonetario(importe))}.</label>`;
    const ficha = a => {
        modal(`Ficha ${codigo('CIT', a.id)}`, detalles([
            ['Paciente',escapar(nombreCompleto(a.patient))],['Especialidad',escapar(a.specialty.name)],['Médico',escapar(nombreCompleto(a.doctor))],
            ['Fecha',escapar(a.date)],['Horario',escapar(`${hora(a.start_time)}–${hora(a.end_time)}`)],['Estado',distintivo(a.status)],
            ['Pago',escapar(a.complimentary ? 'Exención válida de seguimiento' : `${importeMonetario(a.payment?.amount_minor)} · ${situacion(a.payment?.status)}`)],
            ['Medio de pago',escapar(a.complimentary ? 'Seguimiento gratuito' : ({cash:'Efectivo',qr:'QR presencial',transfer:'Transferencia',paypal:'PayPal Sandbox'}[a.payment?.method] || a.payment?.method || '—'))],
            ['Referencia de pago o exención',escapar(a.complimentary
                ? (a.parent_appointment_id ? `Consulta original ${codigo('CIT',a.parent_appointment_id)}` : 'Exención de seguimiento validada')
                : (a.payment?.bank_reference || a.payment?.reference || (a.payment?.id ? `Pago n.º ${a.payment.id}` : '—')))],
        ]) + aviso('Conserve el código de la cita para sus consultas.') + boton('Imprimir ficha','id="print-ficha"'));
        enlazarEvento('#print-ficha','click',()=>{document.body.classList.add('printing-ficha');try{window.print();}finally{document.body.classList.remove('printing-ficha');}});
        if(interfaz.vistasPermitidas().includes('CU13')){
            $('#modal-body').insertAdjacentHTML('beforeend',`<div class="outcome-hint"><strong>La cita ya está programada</strong><p>El día de la atención, registre la llegada del paciente desde la agenda. No necesita crearle una cuenta.</p>${boton('Abrir agenda de la clínica','id="after-booking-agenda"')}</div>`);
            enlazarEvento('#after-booking-agenda','click',()=>{estadoLocal.openAppointmentId=a.id;estadoLocal.workflowContext={date:a.date};return navegar('CU12');});
        }
    };
    /** Presenta historial con nombres legibles y limita datos según el rol. */
    async function historial(a) {
        const paciente=estadoLocal.user.role==='Paciente';
        const profesionalMedico=estadoLocal.user.role==='Médico';
        const entradas=[
            ...(!paciente?[['Paciente',escapar(nombreCompleto(a.patient))]]:[]),['Médico',escapar(nombreCompleto(a.doctor))],['Especialidad',escapar(a.specialty.name)],
            ['Fecha y hora',escapar(`${mostrarFecha(a.date)} ${hora(a.start_time)}–${hora(a.end_time)}`)],['Estado',distintivo(a.status)],
            ['Fecha de registro',escapar(mostrarFechaHora(a.created_at))],
            ['Asistencia',escapar(a.attendance_at ? mostrarFechaHora(a.attendance_at) : 'Sin registrar')],
            ...(!profesionalMedico?[['Pago',escapar(a.complimentary?'Seguimiento exento':importeMonetario(a.payment?.amount_minor))]]:[]),
            ['Motivo',escapar(a.reason || '—')],['Cancelación',escapar(a.cancellation_reason || '—')],
            ['Seguimiento recomendado',escapar(mostrarFecha(a.followup_suggested_at))],['Indicación',escapar(a.followup_note || '—')],
        ];
        const cambios=(a.events || []).map(evento=>[
            escapar(mostrarFechaHora(evento.created_at)),
            escapar(accionesCita[evento.action] || evento.action),
            escapar(detallesEvento(evento)),
        ]);

        modal(`Detalle ${codigo('CIT',a.id)}`,detalles(entradas)
            + (cambios.length?tabla(['Fecha','Cambio registrado','Información'],cambios):aviso('Esta cita no tiene cambios adicionales registrados.')));
    }

    /** Presenta estado del servidor y enlace Sandbox permitido; no confirma por parámetros de URL. */
    async function mostrarReservaOnline(resultado) {
        detenerConsultaOnline();
        if(resultado.appointment){await navegar('CU18');ficha(resultado.appointment);return;}
        const r=resultado.booking,p=resultado.payment;
        if(r.status==='cancelled') { await navegar('CU18');modal('Solicitud descartada',aviso('Se liberó la retención después de comprobar que no existen pagos en proceso. No se creó una cita ni una ficha.'));return; }
        if(r.status==='expired') { await navegar('CU18');modal('Solicitud expirada',aviso('La solicitud venció sin un pago confirmado ni en proceso. Puede consultar disponibilidad e iniciar otra solicitud.'));return; }
        let aprobacion='';
        try {const u=new URL(resultado.approval_url||'');if(u.protocol==='https:'&&u.hostname==='www.sandbox.paypal.com'&&u.pathname==='/checkoutnow'&&!u.username&&!u.password)aprobacion=u.href;}catch{}
        modal('Pago con PayPal Sandbox',aviso(`Operación ${r.id} · Intento ${r.attempt}/2 · ${importeMonetario(r.amount_minor)}`)
            +(p?.provider_amount_minor?aviso(`PayPal: USD ${(p.provider_amount_minor/100).toFixed(2)} de prueba · Tasa fijada: ${p.exchange_rate} Bs/USD.`):'')
            +aviso(`Solicitud: ${situacion(r.status)}. Pago: ${situacion(p?.status || 'Sin pago')}. Límite del intento: ${new Date(r.expires_at).toLocaleString('es-BO')}.`)
            +(estadoLocal.user.role==='Paciente'&&p?.status==='pending'&&aprobacion?`<a class="btn primary" href="${escapar(aprobacion)}" rel="noreferrer">Continuar en PayPal Sandbox</a>`:'')
            +aviso('La aplicación comprueba el pago en el servidor. Si la pasarela mantiene una transacción pendiente, conserva el cupo hasta conciliarla y bloquea un segundo cobro.')
            +boton('Consultar estado',`data-check-payment="${r.id}"`)
            +(estadoLocal.user.role==='Paciente'&&r.status==='held'&&r.attempt<2?boton('Segundo intento',`data-retry-payment="${r.id}"`):'')
            +(estadoLocal.user.role==='Paciente'&&['held','processing'].includes(r.status)?boton('Descartar solicitud sin pago',`data-cancel-booking="${r.id}"`,'danger'):'')
            +aviso('Cerrar esta ventana no cancela una transacción pendiente. Para descartar una solicitud, el servidor debe comprobar antes que no exista un cobro en proceso.'));
        const propietario=estadoLocal.user.id,revision=versionOnline;
        const actual=()=>$('#modal').open&&estadoLocal.user?.id===propietario&&estadoLocal.view==='CU18'&&versionOnline===revision;
        const comprobar=async()=>{
            clearTimeout(temporizadorOnline);
            const controlElemento=$('[data-check-payment]');if(controlElemento?.disabled)return;
            if(controlElemento){controlElemento.disabled=true;controlElemento.textContent='Comprobando con el servidor…';}
            try{
                const actualizarDato=estadoLocal.user.role==='Superadmin'?await clienteApi(`online-bookings/${r.id}`):await clienteApi(`online-bookings/${r.id}/status`,'POST',{});
                if(actual())await mostrarReservaOnline(actualizarDato);
            }catch(fallo){if(actual()){notificacion(fallo.message,true);horario();}}
            finally{if(controlElemento?.isConnected){controlElemento.disabled=false;controlElemento.textContent='Consultar estado';}}
        };
        const horario=()=>{
            if(!actual()||!['held','processing'].includes(r.status))return;
            const destinoElemento=p?.next_check_at?Date.parse(p.next_check_at)-Date.now()+1000:15000;
            const demora=Math.min(60000,Math.max(15000,Number.isFinite(destinoElemento)?destinoElemento:15000));
            $('#online-refresh-hint')?.remove();
            $('#modal-body').insertAdjacentHTML('beforeend',`<p id="online-refresh-hint" class="help-text" role="status">Comprobación automática en aproximadamente ${Math.ceil(demora/1000)} segundos. La ficha aparecerá cuando el servidor confirme el pago. No vuelva a pagar esta operación.</p>`);
            temporizadorOnline=setTimeout(comprobar,demora);
        };
        enlazarEvento('[data-check-payment]','click',comprobar);
        enlazarEvento('[data-retry-payment]','click',async()=>mostrarReservaOnline(await clienteApi(`online-bookings/${r.id}/retry`,'POST',{})));
        enlazarEvento('[data-cancel-booking]','click',async()=>mostrarReservaOnline(await clienteApi(`online-bookings/${r.id}/cancel`,'POST',{})));
        horario();
    }

    /** Abre un borrador manual con el horario que ya confirmó el servidor. */
    function avisarReprogramacion(cita) {
        const enlace = enlaceReprogramacion(cita);
        modal('Avisar reprogramación aceptada', detalles([
            ['Paciente', escapar(nombreCompleto(cita.patient))], ['Teléfono', escapar(cita.patient?.phone || 'Sin teléfono')],
        ]) + areaTexto('Mensaje preparado', 'mensaje_whatsapp', mensajeReprogramacion(cita), 'readonly')
            + (enlace
                ? '<p>Abra WhatsApp, revise el mensaje y pulse Enviar para avisar al paciente.</p>'
                    + `<a class="btn primary" data-whatsapp-reprogramacion href="${escapar(enlace)}" target="_blank" rel="noopener noreferrer">Abrir WhatsApp</a>`
                : aviso('Actualice el teléfono móvil del paciente para abrir WhatsApp. Use 8 dígitos para Bolivia o el formato internacional con + y código de país.')));
    }

    /** Solicita un nuevo horario y un acuerdo antes de modificar la cita original. */
    async function reprogramar(a) {
        modal(`Reprogramar ${codigo('CIT',a.id)}`,`<form id="clinical-reschedule">${erroresFormulario}<div class="grid two">
            ${seleccionar('Médico de la misma especialidad','doctor_id',estadoLocal.refs.doctors.filter(d=>d.status==='Activo'&&d.specialties.some(s=>s.id===a.specialty_id)).map(d=>[d.id,nombreCompleto(d)]),a.doctor_id,'required')}
            ${campo('Nueva fecha','date','date',a.date,`required min="${fechaLocalLocal()}"`)}</div>${seleccionar('Nuevo horario','start_time',[['','Consulte horarios']],'','required')}
            ${seleccionar('Causa del cambio','cause',[['patient','Solicitud del paciente'],['clinic','Causa atribuible a la clínica']])}
            ${areaTexto('Motivo y acuerdo recibido por WhatsApp','change_reason','','required maxlength="3000"')}
            <label class="checks"><input type="checkbox" name="patient_accepted" value="1" required>Registré la aceptación del paciente.</label>
            <div id="reschedule-payment"></div>${botonGuardar('Confirmar reprogramación')}</form>`);
        const formularioLocal=$('#clinical-reschedule');
        const entrada=nombreLocal=>formularioLocal.querySelector(`[name="${nombreLocal}"]`);
        let versionPeticion=0;
        const pago=()=>{$('#reschedule-payment').innerHTML=(a.complimentary&&entrada('cause').value==='patient')||(!a.complimentary&&a.payment?.status!=='confirmed')?camposPago(a.specialty.fee_minor):aviso('Se conserva el pago original o la exención cuando corresponde.');};
        const cargar=async()=>{
            const revision=++versionPeticion;
            entrada('start_time').disabled=true;
            entrada('start_time').innerHTML='<option value="">Consultando horarios…</option>';
            try {
                const resultado=await clienteApi('availability?'+new URLSearchParams({doctor_id:entrada('doctor_id').value,date:entrada('date').value,exclude:a.id,modality:a.modality}));
                if(!formularioLocal.isConnected||revision!==versionPeticion)return;
                const franjas=resultado.filter(b=>b.status==='Disponible');
                entrada('start_time').innerHTML=[['',franjas.length?'Seleccione un horario':'No hay horarios libres; cambie la fecha'],...franjas.map(b=>[b.start,`${b.start}–${b.end}`])].map(([v,l])=>`<option value="${escapar(v)}">${escapar(l)}</option>`).join('');
            }finally{if(formularioLocal.isConnected&&revision===versionPeticion)entrada('start_time').disabled=false;}
        };
        enlazarEvento('[name=doctor_id],[name=date]','change',cargar,formularioLocal);enlazarEvento('[name=cause]','change',pago,formularioLocal);pago();await cargar();
        enlazarFormulario('clinical-reschedule',async datos=>{const cita=await clienteApi(`appointments/${a.id}/reschedule`,'PUT',datos);cerrarModal();await navegar(estadoLocal.view);avisarReprogramacion(cita);});
    }

    /** Comparte validación, envío y resultado de operaciones clínicas breves. */
    function formularioOperacion(titulo, rutaServicio, campos, despues=()=>navegar(estadoLocal.view)) {
        modal(titulo,`<form id="clinical-operation">${erroresFormulario}${campos}${botonGuardar('Guardar')}</form>`);
        enlazarFormulario('clinical-operation',async datos=>{const resultado=await clienteApi(rutaServicio,'POST',datos);const accion=rutaServicio.split('/').at(-1);estadoLocal.flowFeedback={appointmentId:resultado.id,message:({attendance:'Llegada registrada. El paciente ya está en la lista de espera para el médico.',attend:'Consulta finalizada. La atención quedó en el historial; puede programar el seguimiento indicado.',cancel:'Cita cancelada. El horario se liberó; revise si corresponde solicitar un reembolso.',absent:'Inasistencia registrada y conservada en el historial.'})[accion]||'Operación registrada correctamente.'};cerrarModal();await despues();notificacion(estadoLocal.flowFeedback.message);});
    }
    function cancelar(a) {
        formularioOperacion(`Cancelar ${codigo('CIT',a.id)}`,`appointments/${a.id}/cancel`,seleccionar('Causa','cause',[['patient','Paciente'],['clinic','Clínica']])+areaTexto('Motivo','reason','','required maxlength="3000"')+aviso('Se libera el horario y se conserva el historial. Puede solicitarse reembolso por separado.'));
    }
    /** Abre la confirmación correspondiente al cambio de estado solicitado. */
    function cambiarEstado(a, accion) {
        let campos='';
        if(accion==='attend') campos='<input type="hidden" name="consultation_finished" value="1">'+aviso('Confirme únicamente cuando la consulta haya finalizado.')+campo('Fecha y hora sugeridas para seguimiento (opcional)','followup_suggested_at','datetime-local')+areaTexto('Indicación de seguimiento (opcional)','followup_note');
        if(accion==='absent') campos='<label class="checks"><input type="checkbox" name="definitive" value="1" required>Se determinó que no se atenderá ni reprogramará esta cita.</label>'+areaTexto('Motivo','reason','','required maxlength="3000"');
        if(accion==='attendance') campos=a.requires_arrival_payment?aviso(a.complimentary?'El seguimiento perdió la gratuidad. Verifique el pago antes de continuar.':'No consta un pago confirmado. Verifique el cobro antes de continuar.')+camposPago(a.specialty.fee_minor):aviso('Registra la llegada del paciente y cambia la cita a Confirmada.');
        if(accion==='missed-call') campos=aviso('Registra pérdida del turno de llamado; no declara inasistencia definitiva.');
        formularioOperacion(`${({attendance:'Registrar asistencia',attend:'Finalizar consulta',absent:'Registrar inasistencia','missed-call':'Registrar ausencia al llamado'})[accion]} · ${nombreCompleto(a.patient)}`,`appointments/${a.id}/${accion}`,`<p class="dialog-context">${escapar(a.date)} · ${escapar(hora(a.start_time))} · ${escapar(a.specialty.name)}</p>`+campos);
    }

    // CU17 utiliza estas mismas operaciones, con los permisos de Recepción verificados en servidor.
    interfaz.accionesClinicas={reprogramar: reprogramar,cancelar: cancelar,ficha,historial: historial,cambiarEstado: cambiarEstado,mostrarReservaOnline: mostrarReservaOnline,avisarReprogramacion};
}
