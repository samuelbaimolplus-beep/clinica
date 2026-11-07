export function installImpactViews(ui) {
    const { views, api, panel, notice, table, esc, code, time, badge, full, state } = ui;
    views.CU17 = async () => {
        const affected = await api('schedule-impacts');
        const reception = state.user.role === 'Recepcionista';
        return {html: notice('Estas citas conservan su estado. Recepción debe contactar al paciente y acordar una solución. La reprogramación y cancelación con pagos siguen pendientes.')
            + panel('Citas futuras afectadas', table(['Cita', ...(reception ? ['Paciente', 'Teléfono'] : []), 'Médico', 'Fecha', 'Horario', 'Estado', 'Motivo'], affected.map(appointment => [
                esc(code('CIT', appointment.id)), ...(reception ? [esc(full(appointment.patient)), esc(appointment.patient?.phone)] : []), esc(full(state.refs.doctors.find(doctor => doctor.id === appointment.doctor_id))),
                esc(appointment.date), esc(`${time(appointment.start_time)}–${time(appointment.end_time)}`),
                badge(appointment.status), esc(appointment.reasons.join(', ')),
            ])))};
    };
}
