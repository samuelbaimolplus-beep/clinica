export function installAvailabilityViews(ui) {
    const { views, state, api, panel, notice, select, field, formErrors, submit, localDate, full, bindForm, on, $, table, esc, button, navigate } = ui;
    views.CU08 = async () => {
        const options = await api('medical-availability/options');
        const doctors = specialty => options.doctors.filter(doctor => doctor.specialties.some(item => String(item.id) === String(specialty))).map(doctor => [doctor.id, full(doctor)]);
        return {
            html: notice('Consultar disponibilidad no retiene un cupo. Para obtener una ficha utilice Programar cita o Reservar cita online, según su rol.')
                + (state.user.role === 'Paciente' ? `<div class="actions">${button('Reservar cita online', 'id="go-online-booking"')}</div>` : '')
                + panel('Disponibilidad médica', `<form id="medical-availability-form">${formErrors}<div class="grid two">
                ${select('Especialidad', 'specialty_id', [['', 'Seleccione…'], ...options.specialties.map(item => [item.id, item.name])], '', 'required')}
                ${select('Preferencia de médico', 'doctor_id', [['', 'Disponibles según carga']], '')}
                ${field('Fecha', 'date', 'date', localDate(), `required min="${localDate()}"`)}
                ${state.user.role === 'Superadmin' ? select('Modalidad', 'modality', [['presential','Presencial'],['online','Online']]) : field('Modalidad', 'modality_label', 'text', state.user.role === 'Paciente' ? 'Online' : 'Presencial', 'readonly')}
                </div>${submit('Consultar horarios')}</form><div id="medical-availability-result"></div>`),
            bind: () => {
                on('#go-online-booking', 'click', () => navigate('CU18'));
                on('#specialty_id', 'change', event => {
                    $('#doctor_id').innerHTML = [['', 'Disponibles según carga'], ...doctors(event.target.value)].map(([id, label]) => `<option value="${esc(id)}">${esc(label)}</option>`).join('');
                    $('#medical-availability-result').innerHTML = '';
                });
                on('#doctor_id,#date,#modality', 'change', () => { $('#medical-availability-result').innerHTML = ''; });
                bindForm('medical-availability-form', async values => {
                    delete values.modality_label;
                    const result = await api('medical-availability?' + new URLSearchParams(values));
                    $('#medical-availability-result').innerHTML = result.blocks.length ? table(['Médico', 'Inicio', 'Fin', 'Modalidad'], result.blocks.map(block => [esc(block.doctor_name), esc(block.start_time), esc(block.end_time), result.modality === 'online' ? 'Online' : 'Presencial'])) : notice(result.message || 'No hay cupos libres para los criterios seleccionados.');
                });
            },
        };
    };
}
