export function installScheduleViews(ui) {
    const { $, state, views, api, esc, full, code, time, days, localDate, doctorOptions, notice, panel, formErrors, select, field, submit, table, button, on, navigate, bindForm, toast } = ui;
    let scheduleDoctor = '';
    let scheduleTab = 'schedule';
    let capacityDate = localDate();

    views.CU07 = async () => {
        const doctors = doctorOptions();
        if (!doctors.length) return {html: notice('Registre al menos un médico activo para configurar sus horarios.')};
        if (!doctors.some(([id]) => String(id) === String(scheduleDoctor))) scheduleDoctor = doctors[0][0];
        const data = await api('schedules?' + new URLSearchParams({doctor_id: scheduleDoctor}));
        let body;

        if (scheduleTab === 'schedule') {
            body = panel('Configurar horario semanal', `<form id="schedule-form">${formErrors}<div class="grid four">
                ${select('Día de atención', 'weekday', days.map((day, index) => [index, day]), 1)}
                ${field('Hora de inicio', 'start_time', 'time', '08:00', 'required')}
                ${field('Hora de finalización', 'end_time', 'time', '12:00', 'required')}
                ${field('Duración de cita (minutos)', 'duration', 'number', 30, 'required min="5" max="240"')}
                </div>${submit('Guardar horario')}</form>`)
                + panel('Horario vigente', table(['Día', 'Inicio', 'Fin', 'Duración', ''], data.schedules.map(schedule => [
                    esc(days[schedule.weekday]), time(schedule.start_time), time(schedule.end_time), `${schedule.duration} min`,
                    button('Modificar', `data-schedule="${schedule.id}"`, 'link'),
                ])));
        } else if (scheduleTab === 'block') {
            body = panel('Nueva indisponibilidad', `<form id="unavailable-form">${formErrors}<div class="grid two">
                ${select('Motivo', 'reason', ['Ausencia', 'Permiso', 'Vacaciones'])}
                ${field('Médico', 'doctor_name', 'text', full(state.refs.doctors.find(doctor => String(doctor.id) === String(scheduleDoctor))), 'readonly')}
                ${field('Inicio (fecha y hora)', 'starts_at', 'datetime-local', `${localDate()}T08:00`, 'required')}
                ${field('Finalización (fecha y hora)', 'ends_at', 'datetime-local', `${localDate()}T12:00`, 'required')}
                </div><div class="actions">${button('Consultar citas afectadas', 'id="preview-block"', '')}
                <button class="btn success" type="submit">Registrar indisponibilidad</button></div>
                <div id="affected-preview" style="margin-top:16px"></div></form>`)
                + panel('Indisponibilidades registradas', table(['Inicio', 'Fin', 'Motivo'], data.unavailabilities.map(block => [esc(block.starts_at), esc(block.ends_at), esc(block.reason)])));
        } else {
            const quota = data.quota || {};
            body = panel('Distribución de cupos por médico', `<form id="quota-form">${formErrors}
                <p>Los porcentajes deben sumar 100%. Los cupos se calculan por mayor resto; en empate exacto se prioriza presencial.</p>
                <div class="grid two">
                ${field('Porcentaje presencial', 'presential_percent', 'number', quota.presential_percent ?? '', 'required min="0" max="100" step="0.01"')}
                ${field('Porcentaje online', 'online_percent', 'number', quota.online_percent ?? '', 'required min="0" max="100" step="0.01"')}
                </div>${submit('Guardar distribución')}</form>`)
                + panel('Capacidad por fecha', `<form id="capacity-form">${formErrors}
                ${field('Fecha de atención', 'date', 'date', capacityDate, 'required')}${submit('Calcular cupos')}</form><div id="capacity-result"></div>`);
        }

        return {
            html: panel('', select('Médico activo', 'schedule-doctor', doctors, scheduleDoctor))
                + `<div class="tabs">${[['schedule', 'Horario médico'], ['block', 'Indisponibilidades'], ['quota', 'Distribución de cupos']].map(([key, label]) =>
                    `<button class="tab ${scheduleTab === key ? 'selected' : ''}" data-tab="${key}">${label}</button>`).join('')}</div>` + body,
            bind: () => {
                on('#schedule-doctor', 'change', async event => { scheduleDoctor = event.target.value; await navigate('CU07'); });
                on('[data-tab]', 'click', event => { scheduleTab = event.currentTarget.dataset.tab; return navigate('CU07'); });
                if (scheduleTab === 'schedule') {
                    on('[data-schedule]', 'click', event => {
                        const schedule = data.schedules.find(item => item.id === Number(event.currentTarget.dataset.schedule));
                        for (const key of ['weekday', 'duration', 'start_time', 'end_time']) {
                            $(`[name=${key}]`).value = key.endsWith('time') ? time(schedule[key]) : schedule[key];
                        }
                        $('#schedule-form').scrollIntoView({block: 'center', behavior: 'smooth'});
                    });
                    bindForm('schedule-form', async values => {
                        await api('schedules', 'POST', {...values, doctor_id: scheduleDoctor});
                        await navigate('CU07'); toast('Horario guardado.');
                    });
                } else if (scheduleTab === 'block') {
                    on('#preview-block', 'click', async () => {
                        const form = $('#unavailable-form');
                        if (!form.reportValidity()) return;
                        const values = Object.fromEntries(new FormData(form));
                        delete values.doctor_name;
                        const result = await api('unavailabilities/preview', 'POST', {...values, doctor_id: scheduleDoctor});
                        $('#affected-preview').innerHTML = notice(`${result.affected.length} cita(s) vigentes afectadas. ${result.affected.map(appointment => code('CIT', appointment.id)).join(', ')}`, result.affected.length ? 'warning' : 'success');
                    });
                    on('#starts_at,#ends_at', 'change', () => { $('#affected-preview').innerHTML = ''; });
                    bindForm('unavailable-form', async values => {
                        delete values.doctor_name;
                        const result = await api('unavailabilities', 'POST', {...values, doctor_id: scheduleDoctor});
                        await navigate('CU07'); toast(`Indisponibilidad registrada. ${result.affected.length} cita(s) afectadas.`);
                    });
                } else {
                    bindForm('quota-form', async values => {
                        await api('doctor-quotas', 'PUT', {...values, doctor_id: scheduleDoctor});
                        await navigate('CU07'); toast('Distribución guardada. Las citas existentes se conservaron.');
                    });
                    bindForm('capacity-form', async values => {
                        capacityDate = values.date;
                        const result = await api('schedules/capacity?' + new URLSearchParams({doctor_id: scheduleDoctor, date: capacityDate}));
                        let summary = `Bloques del horario: ${result.configured_blocks}. Bloqueados: ${result.blocked_blocks}. Capacidad: ${result.total_capacity}.`;
                        summary += result.quota
                            ? ` Presenciales: ${result.presential_capacity}. Online: ${result.online_capacity}.`
                            : ' Configure los porcentajes para distribuir la capacidad.';
                        $('#capacity-result').innerHTML = notice(summary)
                            + notice('Este cálculo distribuye la capacidad del horario, descontando indisponibilidades. No muestra cupos libres después de las reservas ni modifica citas existentes.');
                    });
                }
            },
        };
    };
}
