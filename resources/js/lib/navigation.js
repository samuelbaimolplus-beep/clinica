// Las opciones visibles siempre se cruzan con los permisos entregados por el servidor.
export const navigationGroups = [
    ['Mi cuenta', [['CU02', 'Mi cuenta y seguridad']]],
    ['Administración', [['CU03', 'Usuarios y roles'], ['CU04', 'Médicos'], ['CU05', 'Especialidades y tarifas'], ['CU07', 'Horarios médicos']]],
    ['Recepción', [['CU06', 'Pacientes'], ['CU09', 'Programar cita presencial'], ['CU10', 'Reprogramaciones'], ['CU11', 'Cancelar cita'], ['CU13', 'Asistencia e inasistencia'], ['CU17', 'Citas afectadas'], ['CU24', 'Bloqueos provisionales'], ['CU25', 'Habilitar cupos online']]],
    ['Consultas médicas', [['CU12', 'Agenda médica'], ['CU14', 'Finalizar consulta'], ['CU21', 'Registrar seguimiento']]],
    ['Portal del paciente', [['CU18', 'Reservar cita online']]],
    ['Pagos y devoluciones', [['CU19', 'Pagos y exenciones'], ['CU20', 'Solicitar reembolso'], ['CU26', 'Resolver reembolsos']]],
    ['Consulta y supervisión', [['CU08', 'Disponibilidad médica'], ['CU15', 'Historial de citas'], ['CU16', 'Reportes administrativos'], ['CU22', 'Bitácora de auditoría'], ['CU23', 'Cuentas de pacientes']]],
];

export const rolePanels = {
    Superadmin: {title: 'Panel de Superadmin', home: 'CU03', description: 'Gestión y supervisión global de la clínica.'},
    Administrador: {title: 'Panel de Administración', home: 'CU03', description: 'Personal, médicos, especialidades, horarios y control administrativo.'},
    Recepcionista: {title: 'Panel de Recepción', home: 'CU06', description: 'Pacientes, citas presenciales, asistencia y solicitudes pendientes.'},
    Médico: {title: 'Mi consulta médica', home: 'CU12', description: 'Su agenda y la finalización de sus propias consultas.'},
    Paciente: {title: 'Mi portal de paciente', home: 'CU18', description: 'Reserve una cita online, consulte sus citas y envíe sus solicitudes.'},
};

export function visibleNavigation(role, allowed) {
    const labels = role === 'Paciente' ? {CU10:'Solicitar reprogramación',CU15:'Mis citas e historial'}
        : role === 'Médico' ? {CU12:'Mi agenda',CU14:'Finalizar mis consultas'}
        : role === 'Superadmin' ? {CU14:'Supervisar consultas',CU18:'Reservas online (supervisión)'} : {};
    const groups = role === 'Paciente' ? {'Recepción':'Mis solicitudes','Consulta y supervisión':'Mis consultas'}
        : role === 'Superadmin' ? {'Portal del paciente':'Supervisión del paciente'} : {};
    return navigationGroups.map(([title, links]) => [groups[title] || title, links.filter(([id]) => allowed.includes(id)).map(([id,label])=>[id,labels[id] || label])])
        .filter(([, links]) => links.length);
}

export function homeView(role, allowed) {
    const preferred = rolePanels[role]?.home;
    return allowed.includes(preferred) ? preferred : allowed.find(id => !['CU01', 'CU02'].includes(id)) || 'CU02';
}

// Los identificadores internos conservan la autorización; las direcciones son legibles.
export const viewRoutes = {
    CU02:'mi-cuenta', CU03:'usuarios', CU04:'medicos', CU05:'especialidades', CU06:'pacientes',
    CU07:'horarios', CU08:'disponibilidad', CU09:'nueva-cita', CU10:'reprogramaciones',
    CU11:'cancelaciones', CU12:'agenda', CU13:'asistencia', CU14:'consultas', CU15:'mis-citas',
    CU16:'reportes', CU17:'citas-afectadas', CU18:'reservar-cita', CU19:'pagos', CU20:'solicitar-reembolso',
    CU21:'seguimientos', CU22:'auditoria', CU23:'cuentas-pacientes', CU24:'bloqueos', CU25:'cupos-online', CU26:'reembolsos',
};
export const routeFor = view => viewRoutes[view] || '';
export const viewFromHash = hash => {
    const value = hash.replace(/^#/, '');
    return Object.keys(viewRoutes).find(view => viewRoutes[view] === value) || (viewRoutes[value] ? value : null);
};
export const viewDescriptions = {
    CU02:'Actualice sus datos de contacto y mantenga protegido su acceso.',
    CU03:'Administre las cuentas del personal y sus permisos de acceso.',
    CU04:'Gestione el equipo médico y las especialidades que atiende.',
    CU05:'Organice los servicios de la clínica y sus tarifas de consulta.',
    CU06:'Encuentre a un paciente o registre su ficha y su cuenta de acceso.',
    CU07:'Organice los turnos de atención y la distribución de cupos.',
    CU08:'Consulte los horarios disponibles por especialidad y médico.',
    CU09:'Seleccione al paciente, el horario y el medio de pago para emitir su ficha.',
    CU10:'Consulte las solicitudes y gestione los cambios de fecha u horario.',
    CU11:'Busque la cita y revise sus datos antes de confirmar la cancelación.',
    CU12:'Revise las citas del día y prepare la atención de sus pacientes.',
    CU13:'Registre la llegada de los pacientes y el resultado de la asistencia.',
    CU14:'Revise las citas pendientes y registre las consultas realizadas.',
    CU15:'Consulte el detalle y el estado de las citas registradas.',
    CU16:'Obtenga reportes por período y descargue la información que necesita.',
    CU17:'Revise las citas que necesitan atención por cambios en la disponibilidad.',
    CU18:'Elija especialidad y horario para reservar su próxima consulta.',
    CU19:'Consulte los cobros registrados, sus referencias y las exenciones.',
    CU20:'Consulte las citas elegibles y envíe su solicitud de devolución.',
    CU21:'Programe el seguimiento recomendado en una consulta anterior.',
    CU22:'Consulte la actividad registrada y filtre por usuario, fecha o acción.',
    CU23:'Revise las cuentas de pacientes y el estado de su activación.',
    CU24:'Gestione las franjas reservadas para la demanda presencial de hoy.',
    CU25:'Habilite franjas disponibles para la reserva de citas por internet.',
    CU26:'Revise las solicitudes de devolución y registre su resolución.',
};
