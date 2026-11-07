// Las opciones visibles siempre se cruzan con los permisos entregados por el servidor.
export const navigationGroups = [
    ['Acceso', [['CU02', 'Mi sesión']]],
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
