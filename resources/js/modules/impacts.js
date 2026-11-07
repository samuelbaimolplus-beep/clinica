export function installImpactViews(ui) {
    const { views, api, panel, notice, table, esc, code, time, badge, full, state, button, on, modal, details } = ui;
    const originText = origin => {
        if (origin.entity === 'Unavailability') return `Indisponibilidad n.º ${origin.id}`;
        if (origin.entity === 'Doctor') return `Médico ${code('MED', origin.id)}`;
        if (origin.entity === 'Specialty') return `Especialidad ${code('ESP', origin.id)}`;
        if (origin.entity === 'DoctorSpecialty') return `Habilitación de ${code('MED', origin.doctor_id)} para ${code('ESP', origin.specialty_id)}`;
        return `Horario de ${code('MED', origin.doctor_id)} · Turnos vigentes: ${origin.schedule_ids.join(', ') || 'Sin turnos configurados'}`;
    };
    views.CU17 = async () => {
        const affected = await api('schedule-impacts');
        const reception = state.user.role === 'Recepcionista';
        return {html: notice('Estas citas conservan su estado. Recepción debe contactar al paciente y acordar una solución.')
            + panel('Citas futuras afectadas', table(['Cita', ...(reception ? ['Paciente', 'Teléfono'] : []), 'Médico', 'Especialidad', 'Fecha', 'Horario', 'Estado', 'Motivo', 'Detalle'], affected.map(appointment => [
                esc(code('CIT', appointment.id)), ...(reception ? [esc(full(appointment.patient)), esc(appointment.patient?.phone)] : []), esc(full(state.refs.doctors.find(doctor => doctor.id === appointment.doctor_id))),
                esc(appointment.specialty?.name), esc(appointment.date), esc(`${time(appointment.start_time)}–${time(appointment.end_time)}`),
                badge(appointment.status), esc(appointment.reasons.join(', ')), button('Consultar origen', `data-impact="${appointment.id}"`, 'link'),
            ]))), bind: () => on('[data-impact]', 'click', event => {
                const appointment = affected.find(item => item.id === Number(event.currentTarget.dataset.impact));
                modal('Origen de la afectación', details([
                    ['Cita', esc(code('CIT', appointment.id))], ['Motivos', esc(appointment.reasons.join(', '))],
                    ['Registros de origen', appointment.origins.map(origin => esc(originText(origin))).join('<br>')],
                ]));
            })};
    };
}
