export function installAgendaAdjustmentViews(ui) {
    const { views, api, panel, notice, table, select, formErrors, submit, button, on, bindForm, localDate, state, full, esc, navigate, $, toast, confirmAction } = ui;
    let specialty = '';
    const doctorName = id => full(state.refs.doctors.find(doctor => doctor.id === Number(id)));
    const specialtyOptions = () => [['', 'Seleccione…'], ...state.refs.specialties.filter(item => item.status === 'Activo').map(item => [item.id, item.name])];

    views.CU24 = async () => {
        const date = localDate();
        const records = await api('agenda-adjustments?' + new URLSearchParams({date, ...(specialty ? {specialty_id: specialty} : {})}));
        const blocks = records.filter(record => record.kind === 'presential_block');
        let proposal = null;
        return {
            html: notice(`Demanda presencial de hoy (${date}). Los bloqueos se liberan manualmente y no crean citas, fichas ni pagos.`)
                + panel('Proteger cupos por especialidad', `<form id="demand-proposal-form">${formErrors}<div class="grid two">
                ${select('Especialidad', 'specialty_id', specialtyOptions(), specialty, 'required')}
                <label class="field"><span>Pacientes presentes contados o estimados</span><input name="quantity" id="quantity" type="number" min="1" max="100" value="1" required></label>
                </div>${submit('Proponer horarios de hoy')}</form><div id="demand-proposal"></div>`)
                + panel('Bloqueos de la jornada', `<form id="release-blocks-form">${formErrors}`
                + table(['Seleccionar', 'Especialidad', 'Médico', 'Horario', 'Estado', 'Responsable'], blocks.map(record => [
                    record.status === 'active' ? `<input type="checkbox" name="ids" value="${record.id}" aria-label="Seleccionar bloqueo ${record.id}">` : '',
                    esc(state.refs.specialties.find(item => item.id === record.specialty_id)?.name), esc(doctorName(record.doctor_id)),
                    esc(`${record.start_time.slice(0, 5)}–${record.end_time.slice(0, 5)}`), esc(record.status === 'active' ? 'Activo' : 'Liberado'), esc(`Usuario ${record.created_by}`),
                ])) + submit('Liberar bloqueos seleccionados') + '</form>'),
            bind: () => {
                on('#specialty_id', 'change', async event => { specialty = event.target.value; await navigate('CU24'); });
                on('#quantity', 'input', () => { proposal = null; $('#demand-proposal').innerHTML = ''; });
                bindForm('demand-proposal-form', async values => {
                    proposal = {...await api('agenda-adjustments/proposal', 'POST', {...values, date}), specialty_id: values.specialty_id};
                    $('#demand-proposal').innerHTML = notice(`${proposal.blocks.length} de ${proposal.requested} cupos disponibles. Sin disponibilidad: ${proposal.unfulfilled}.`)
                        + `<form id="confirm-demand-form">${formErrors}`
                        + table(['Seleccionar', 'Médico', 'Inicio', 'Fin'], proposal.blocks.map((block, index) => [
                            `<input type="checkbox" name="blocks" value="${index}" checked aria-label="Seleccionar ${esc(block.start_time)}">`, esc(doctorName(block.doctor_id)), esc(block.start_time), esc(block.end_time),
                        ])) + (proposal.blocks.length ? submit('Confirmar bloqueos seleccionados') : '') + '</form>';
                    bindForm('confirm-demand-form', async (_, form) => {
                        const selected = new FormData(form).getAll('blocks').map(index => proposal.blocks[Number(index)]);
                        if (!selected.length) throw new Error('Seleccione al menos una franja.');
                        await api('agenda-adjustments', 'POST', {kind: 'presential_block', specialty_id: proposal.specialty_id, quantity: proposal.requested, blocks: selected});
                        await navigate('CU24'); toast('Bloqueos registrados para hoy.');
                    });
                });
                bindForm('release-blocks-form', async (_, form) => {
                    const ids = new FormData(form).getAll('ids');
                    if (!ids.length) throw new Error('Seleccione al menos un bloqueo activo.');
                    confirmAction('Liberar bloqueos', `¿Confirma la liberación de ${ids.length} bloqueo(s) de hoy?`, async () => {
                        await api('agenda-adjustments/release', 'POST', {ids});
                        await navigate('CU24'); toast('Bloqueos liberados.');
                    });
                });
            },
        };
    };

    views.CU25 = async () => {
        let blocks = [];
        const date = localDate();
        const openings = (await api('agenda-adjustments?' + new URLSearchParams({date}))).filter(record => record.kind === 'online_opening');
        return {
            html: notice('Recepción puede habilitar franjas libres protegidas para online. Esta operación no admite retirar la apertura.')
                + panel('Habilitar cupos online', `<form id="protected-query-form">${formErrors}
                ${select('Médico', 'doctor_id', [['', 'Seleccione…'], ...state.refs.doctors.filter(doctor => doctor.status === 'Activo').map(doctor => [doctor.id, full(doctor)])], '', 'required')}
                ${submit('Consultar franjas protegidas libres de hoy')}</form><div id="protected-options"></div>`)
                + panel('Habilitaciones de hoy', table(['Médico', 'Inicio', 'Fin', 'Responsable'], openings.map(record => [
                    esc(doctorName(record.doctor_id)), esc(record.start_time.slice(0, 5)), esc(record.end_time.slice(0, 5)), esc(`Usuario ${record.created_by}`),
                ]))),
            bind: () => {
                on('#doctor_id', 'change', () => { blocks = []; $('#protected-options').innerHTML = ''; });
                bindForm('protected-query-form', async values => {
                    blocks = (await api('agenda-adjustments/protected?' + new URLSearchParams({...values, date})))
                        .map(block => ({doctor_id: values.doctor_id, date, start_time: block.start_time, end_time: block.end_time}));
                    $('#protected-options').innerHTML = blocks.length
                        ? `<form id="open-online-form">${formErrors}<div class="checks">${blocks.map((block, index) => `<label><input type="checkbox" name="blocks" value="${index}">${esc(block.start_time)}–${esc(block.end_time)}</label>`).join('')}</div>${submit('Confirmar habilitación online')}</form>`
                        : notice('No hay franjas protegidas libres para este médico hoy.');
                    if (blocks.length) bindForm('open-online-form', async (_, form) => {
                        const selected = new FormData(form).getAll('blocks').map(index => blocks[Number(index)]);
                        if (!selected.length) throw new Error('Seleccione al menos una franja.');
                        await api('agenda-adjustments', 'POST', {kind: 'online_opening', blocks: selected});
                        await navigate('CU25'); toast('Franjas habilitadas para online.');
                    });
                });
            },
        };
    };
}
