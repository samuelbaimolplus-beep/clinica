export function installAgendaAdjustmentViews(ui) {
    const { views, api, panel, notice, table, select, field, formErrors, submit, button, on, bindForm, localDate, state, full, esc, navigate, $, toast } = ui;
    let date = localDate();
    views.CUPOS = async () => {
        const records = await api('agenda-adjustments?' + new URLSearchParams({date}));
        const doctors = state.refs.doctors.filter(doctor => doctor.status === 'Activo');
        const specialties = state.refs.specialties.filter(specialty => specialty.status === 'Activo');
        let proposal = null;
        let protectedBlocks = [];
        const showDoctor = id => full(state.refs.doctors.find(doctor => doctor.id === Number(id)));
        return {
            html: notice('Los bloqueos de demanda no crean citas ni cobros. Deben liberarse manualmente; una advertencia por antigüedad no libera el horario.')
                + panel('Fecha de trabajo', `<form id="agenda-date-form">${formErrors}${field('Fecha', 'date', 'date', date, 'required')}${submit('Consultar movimientos')}</form>`)
                + panel('Proteger cupos por demanda presencial', `<form id="demand-proposal-form">${formErrors}<div class="grid two">
                ${select('Especialidad', 'specialty_id', [['', 'Seleccione…'], ...specialties.map(item => [item.id, item.name])], '', 'required')}
                ${field('Pacientes en espera o estimados', 'quantity', 'number', 1, 'required min="1" max="100"')}
                </div>${submit('Proponer próximos horarios')}</form><div id="demand-proposal"></div>`)
                + panel('Habilitar online horarios protegidos', `<form id="protected-query-form">${formErrors}
                ${select('Médico', 'doctor_id', [['', 'Seleccione…'], ...doctors.map(doctor => [doctor.id, full(doctor)])], '', 'required')}
                ${submit('Consultar franjas protegidas libres')}</form><div id="protected-options"></div>`)
                + panel('Movimientos del día', table(['Médico', 'Horario', 'Movimiento', 'Especialidad', 'Estado', 'Responsable', 'Antigüedad', ''], records.map(record => [
                    esc(showDoctor(record.doctor_id)), esc(`${record.start_time.slice(0, 5)}–${record.end_time.slice(0, 5)}`),
                    record.kind === 'presential_block' ? 'Bloqueo presencial' : 'Apertura online',
                    esc(state.refs.specialties.find(item => item.id === record.specialty_id)?.name || '—'), esc(record.status),
                    esc(`Usuario ${record.created_by}`), esc(`${record.age_minutes} min${record.prolonged ? ' · Revisar bloqueo prolongado' : ''}`),
                    record.status === 'active' ? button(record.kind === 'presential_block' ? 'Liberar' : 'Retirar apertura', `data-release-adjustment="${record.id}"`, 'link') : '',
                ]))),
            bind: () => {
                bindForm('agenda-date-form', async values => { date = values.date; await navigate('CUPOS'); });
                on('#specialty_id,#quantity', 'change', () => { proposal = null; $('#demand-proposal').innerHTML = ''; });
                bindForm('demand-proposal-form', async values => {
                    proposal = {...await api('agenda-adjustments/proposal', 'POST', {...values, date}), specialty_id: values.specialty_id};
                    $('#demand-proposal').innerHTML = notice(`${proposal.blocks.length} de ${proposal.requested} cupos disponibles. No se pueden proteger ${proposal.unfulfilled}.`)
                        + table(['Médico', 'Inicio', 'Fin'], proposal.blocks.map(block => [esc(showDoctor(block.doctor_id)), esc(block.start_time), esc(block.end_time)]))
                        + (proposal.blocks.length ? button('Confirmar bloqueo de estos horarios', 'id="confirm-demand"', 'success') : '');
                    on('#confirm-demand', 'click', async event => {
                        if (!proposal) return;
                        const confirmButton = event.currentTarget;
                        confirmButton.disabled = true;
                        try {
                            await api('agenda-adjustments', 'POST', {kind: 'presential_block', specialty_id: proposal.specialty_id, blocks: proposal.blocks});
                            await navigate('CUPOS'); toast('Bloqueos presenciales registrados.');
                        } finally { confirmButton.disabled = false; }
                    });
                });
                on('#doctor_id', 'change', () => { protectedBlocks = []; $('#protected-options').innerHTML = ''; });
                bindForm('protected-query-form', async values => {
                    protectedBlocks = (await api('agenda-adjustments/protected?' + new URLSearchParams({...values, date})))
                        .map(block => ({doctor_id: values.doctor_id, date, start_time: block.start_time, end_time: block.end_time}));
                    $('#protected-options').innerHTML = protectedBlocks.length
                        ? `<form id="open-online-form">${formErrors}<div class="checks">${protectedBlocks.map((block, index) => `<label><input type="checkbox" name="blocks" value="${index}">${esc(block.start_time)}–${esc(block.end_time)}</label>`).join('')}</div>${submit('Habilitar online las franjas seleccionadas')}</form>`
                        : notice('No hay franjas protegidas libres para este médico y fecha.');
                    if (protectedBlocks.length) bindForm('open-online-form', async (_, form) => {
                        const selected = new FormData(form).getAll('blocks').map(index => protectedBlocks[Number(index)]);
                        if (!selected.length) throw new Error('Seleccione al menos una franja.');
                        await api('agenda-adjustments', 'POST', {kind: 'online_opening', blocks: selected});
                        await navigate('CUPOS'); toast('Franjas habilitadas para online.');
                    });
                });
                on('[data-release-adjustment]', 'click', async event => {
                    await api(`agenda-adjustments/${event.currentTarget.dataset.releaseAdjustment}/release`, 'PATCH');
                    await navigate('CUPOS'); toast('Movimiento liberado.');
                });
            },
        };
    };
}
