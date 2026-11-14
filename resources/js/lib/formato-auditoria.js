/** Etiquetas compartidas por el listado y el detalle de la bitácora. */
const etiquetas = {
    users:'Usuarios', patients:'Pacientes', doctors:'Médicos', specialties:'Especialidades',
    schedules:'Horarios y bloqueos', appointments:'Citas y reservas', payments:'Pagos', refunds:'Reembolsos',
    auth:'Acceso y seguridad', clinical_access:'Acceso clínico',
    created:'Creación', updated:'Modificación', deleted:'Eliminación', specialties_assigned:'Asignación de especialidades',
    login:'Inicio de sesión', logout:'Cierre de sesión', access_denied:'Acceso denegado', denied:'Denegado',
    success:'Correcto', failure:'Fallido', password_changed:'Cambio de contraseña',
    password_reset_by_superadmin:'Contraseña restablecida por Superadmin', password_recovered:'Recuperación de contraseña',
    email_verified:'Activación de cuenta', session_revoked:'Sesión revocada',
    account_created_by_staff:'Cuenta creada por personal', portal_consent_recorded:'Consentimiento del portal',
    booked:'Programación de cita', rescheduled:'Reprogramación de cita', cancel:'Cancelación de cita',
    attendance:'Registro de asistencia', absent:'Registro de inasistencia', attend:'Atención finalizada', 'missed-call':'Llamado sin respuesta',
    change_cause:'Causa del cambio', patient_accepted:'Aceptación del paciente',
    id:'Código', name:'Nombre', username:'Usuario de acceso', email:'Correo electrónico', role:'Rol', status:'Estado',
    email_verified_at:'Activación de cuenta', registration:'Matrícula médica', first_name:'Nombres', last_name:'Apellidos',
    phone:'Teléfono', identity:'Documento de identidad', birth_date:'Fecha de nacimiento', sex:'Sexo', address:'Dirección',
    description:'Descripción', doctor_id:'Médico', patient_id:'Paciente', specialty_id:'Especialidad', user_id:'Usuario',
    weekday:'Día de la semana', start_time:'Hora de inicio', end_time:'Hora de fin', duration:'Duración en minutos',
    starts_at:'Inicio', ends_at:'Fin', date:'Fecha de atención', reason:'Motivo', attendance_at:'Asistencia registrada',
    cancellation_reason:'Motivo de cancelación', cancellation_cause:'Causa de cancelación', cancelled_at:'Cancelación', cancelled_by:'Retirado por',
    created_by:'Creado por', appointment_id:'Cita', issued_by:'Emitido por', terms_version:'Versión de los términos',
    terms_accepted_at:'Aceptación de términos', presential_percent:'Porcentaje presencial', online_percent:'Porcentaje en línea',
    kind:'Tipo de bloqueo', released_by:'Liberado por', released_at:'Liberación', demand_quantity:'Personas solicitadas',
    fee_minor:'Tarifa', modality:'Modalidad', amount_minor:'Importe', attempt:'Intento', booking_request_id:'Reserva',
    method:'Medio de pago', reference:'Referencia', bank_reference:'Referencia bancaria', qr_number:'Número QR',
    confirmed_by:'Confirmado por', confirmed_at:'Confirmación', payment_id:'Pago', parent_appointment_id:'Cita de origen',
    complimentary:'Exención de pago', followup_suggested_at:'Seguimiento sugerido', followup_note:'Nota de seguimiento',
    response:'Respuesta', resolved_by:'Resuelto por', resolved_at:'Resolución', decision_reason:'Motivo de la decisión',
    executor:'Ejecutor de la devolución', completed_at:'Finalización', expires_at:'Vencimiento', deadline_at:'Plazo máximo',
    missed_call_at:'Llamado sin respuesta', attended_at:'Atención finalizada', clinical_slot_id:'Franja clínica',
    provider_currency:'Moneda del proveedor', provider_amount_minor:'Importe del proveedor', exchange_rate:'Tipo de cambio',
    capture_id:'Código de captura', created_at:'Fecha de creación', updated_at:'Última modificación',
    specialty_ids:'Códigos de especialidades', specialties:'Especialidades asignadas',
    active:'Activo', released:'Liberado', consumed:'Consumido', held:'Retenido', processing:'En proceso',
    completed:'Completado', pending:'Pendiente', confirmed:'Confirmado', cancelled:'Cancelado', expired:'Vencido',
    approved:'Aprobado', rejected:'Rechazado', unknown:'Por conciliar',
    presential_block:'Bloqueo provisional presencial', online_opening:'Apertura en línea',
    presential:'Presencial', online:'En línea', cash:'Efectivo', transfer:'Transferencia', patient:'Paciente', clinic:'Clínica',
    invalid_credentials:'Credenciales incorrectas', inactive_account:'Cuenta inactiva',
};

export const etiquetaAuditoria = valorDato => etiquetas[valorDato] || String(valorDato || '—').replaceAll('_', ' ');
export const moduloAuditoria = valorDato => valorDato === 'specialties' ? 'Especialidades y tarifas' : etiquetaAuditoria(valorDato);
export const tipoAuditoria = valorDato => ({User:'Usuario', Doctor:'Médico', Patient:'Paciente', Specialty:'Especialidad',
    Schedule:'Horario', Unavailability:'Ausencia médica', DoctorQuota:'Distribución de cupos', AgendaAdjustment:'Bloqueo de agenda',
    Appointment:'Cita', BookingRequest:'Reserva', Payment:'Pago', RefundRequest:'Solicitud de reembolso', RescheduleRequest:'Solicitud de reprogramación'}[valorDato] || valorDato || 'Sin registro');

const formatoFechaHora = new Intl.DateTimeFormat('es-BO', {
    timeZone:'America/La_Paz', year:'numeric', month:'2-digit', day:'2-digit',
    hour:'2-digit', minute:'2-digit', second:'2-digit', hourCycle:'h23',
});

/** La API conserva el instante; la bitácora siempre lo presenta en la hora de Santa Cruz. */
export function fechaHoraAuditoria(valor) {
    if (valor === null || valor === undefined || valor === '') return 'Sin fecha';
    const texto = String(valor);
    const sello = texto.trim().match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2}(?:\.\d+)?)(Z|[+-]\d{2}(?::?\d{2})?)?$/i);
    if (!sello) return texto;
    // Las fechas SQL sin zona corresponden al horario local de la aplicación.
    let zona = sello[3] || '-04:00';
    if (/^[+-]\d{2}$/.test(zona)) zona += ':00';
    const fecha = new Date(`${sello[1]}T${sello[2]}${zona}`);
    if (Number.isNaN(fecha.getTime())) return texto;
    const partes = Object.fromEntries(formatoFechaHora.formatToParts(fecha).map(parte => [parte.type, parte.value]));
    return `${partes.day}/${partes.month}/${partes.year} ${partes.hour}:${partes.minute}:${partes.second}`;
}

export function valorAuditoria(campo, valorDato, registro = {}) {
    if (valorDato === null || valorDato === undefined || valorDato === '') return 'Sin valor';
    if (typeof valorDato === 'boolean') return valorDato ? 'Sí' : 'No';
    if (campo.endsWith('_at')) return fechaHoraAuditoria(valorDato);
    if (campo === 'weekday') return ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'][Number(valorDato)] ?? String(valorDato);
    if (['fee_minor','amount_minor','provider_amount_minor'].includes(campo) && Number.isFinite(Number(valorDato))) {
        const moneda = campo === 'provider_amount_minor' ? (registro.provider_currency || 'USD') : 'BOB';
        return `${(Number(valorDato) / 100).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2})} ${moneda}`;
    }
    if (Array.isArray(valorDato)) return valorDato.length ? valorDato.map(elemento => typeof elemento === 'object' && elemento ? `${elemento.name || elemento.label || 'Registro'} (#${elemento.id})` : String(elemento)).join(', ') : 'Ninguno';
    if (typeof valorDato === 'object') return JSON.stringify(valorDato);
    return ['status','kind','modality','method','cancellation_cause','change_cause','reason'].includes(campo) ? (etiquetas[valorDato] || String(valorDato)) : String(valorDato);
}

export function registroAuditoria(evento) {
    const registro = evento.details?.record || {};
    const nombreLocal = registro.name || [registro.first_name, registro.last_name].filter(Boolean).join(' ') || registro.username;
    const relacionados = evento.details?.related || {};
    return [nombreLocal, relacionados.patient_id?.label, relacionados.doctor_id?.label, relacionados.specialty_id?.label,
        registro.date, registro.start_time && `${registro.start_time}–${registro.end_time || ''}`].filter(Boolean).join(' · ');
}
