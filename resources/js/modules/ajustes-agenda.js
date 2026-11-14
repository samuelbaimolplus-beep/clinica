/** CU24/CU25: consultar y proponer no modifica cupos; confirmar sí aplica el ajuste autorizado. */
export function instalarVistasAjustesAgenda(interfaz) {
    const { vistas: vistas, clienteApi: clienteApi, panel, aviso: aviso, tabla: tabla, seleccionar: seleccionar, erroresFormulario: erroresFormulario, botonGuardar: botonGuardar, boton: boton, enlazarEvento: enlazarEvento, enlazarFormulario: enlazarFormulario, fechaLocalLocal: fechaLocalLocal, estadoLocal: estadoLocal, nombreCompleto: nombreCompleto, escapar: escapar, navegar: navegar, $, notificacion: notificacion, confirmarAccion: confirmarAccion } = interfaz;
    let especialidad = '';
    const nombreMedico = id => nombreCompleto(estadoLocal.refs.doctors.find(medico => medico.id === Number(id)));
    const opcionesEspecialidad = () => [['', 'Seleccione…'], ...estadoLocal.refs.specialties.filter(elemento => elemento.status === 'Activo').map(elemento => [elemento.id, elemento.name])];

    vistas.CU24 = async () => {
        const fechaLocal = fechaLocalLocal();
        const registros = await clienteApi('agenda-adjustments?' + new URLSearchParams({date: fechaLocal, ...(especialidad ? {specialty_id: especialidad} : {})}));
        const bloques = registros.filter(registro => registro.kind === 'presential_block');
        let propuesta = null;
        return {
            html: aviso(`Demanda presencial de hoy (${fechaLocal}). Los bloqueos se liberan manualmente y no crean citas, fichas ni pagos.`)
                + panel('Proteger cupos por especialidad', `<form id="demand-proposal-form">${erroresFormulario}<div class="grid two">
                ${seleccionar('Especialidad', 'specialty_id', opcionesEspecialidad(), especialidad, 'required')}
                <label class="field"><span>Pacientes presentes contados o estimados</span><input name="quantity" id="quantity" type="number" min="1" max="100" value="1" required></label>
                </div>${botonGuardar('Proponer horarios de hoy')}</form><div id="demand-proposal"></div>`)
                + panel('Bloqueos de la jornada', `<form id="release-blocks-form">${erroresFormulario}`
                + tabla(['Seleccionar', 'Especialidad', 'Médico', 'Horario', 'Estado', 'Responsable'], bloques.map(registro => [
                    registro.status === 'active' ? `<input type="checkbox" name="ids" value="${registro.id}" aria-label="Seleccionar bloqueo ${registro.id}">` : '',
                    escapar(estadoLocal.refs.specialties.find(elemento => elemento.id === registro.specialty_id)?.name), escapar(nombreMedico(registro.doctor_id)),
                    escapar(`${registro.start_time.slice(0, 5)}–${registro.end_time.slice(0, 5)}`), escapar(({active:'Activo',released:'Liberado',consumed:'Consumido por una cita'})[registro.status] || registro.status), escapar(`Usuario ${registro.created_by}`),
                ])) + botonGuardar('Liberar bloqueos seleccionados') + '</form>'),
            bind: () => {
                enlazarEvento('#specialty_id', 'change', async evento => { especialidad = evento.target.value; await navegar('CU24'); });
                enlazarEvento('#quantity', 'input', () => { propuesta = null; $('#demand-proposal').innerHTML = ''; });
                enlazarFormulario('demand-proposal-form', async valores => {
                    propuesta = {...await clienteApi('agenda-adjustments/proposal', 'POST', {...valores, date: fechaLocal}), specialty_id: valores.specialty_id};
                    $('#demand-proposal').innerHTML = aviso(`${propuesta.blocks.length} de ${propuesta.requested} cupos disponibles. Sin disponibilidad: ${propuesta.unfulfilled}.`)
                        + `<form id="confirm-demand-form">${erroresFormulario}`
                        + tabla(['Seleccionar', 'Médico', 'Inicio', 'Fin'], propuesta.blocks.map((bloque, indice) => [
                            `<input type="checkbox" name="blocks" value="${indice}" checked aria-label="Seleccionar ${escapar(bloque.start_time)}">`, escapar(nombreMedico(bloque.doctor_id)), escapar(bloque.start_time), escapar(bloque.end_time),
                        ])) + (propuesta.blocks.length ? botonGuardar('Confirmar bloqueos seleccionados') : '') + '</form>';
                    enlazarFormulario('confirm-demand-form', async (_, formularioLocal) => {
                        const seleccionada = new FormData(formularioLocal).getAll('blocks').map(indice => propuesta.blocks[Number(indice)]);
                        if (!seleccionada.length) throw new Error('Seleccione al menos una franja.');
                        await clienteApi('agenda-adjustments', 'POST', {kind: 'presential_block', specialty_id: propuesta.specialty_id, quantity: propuesta.requested, blocks: seleccionada});
                        await navegar('CU24'); notificacion('Bloqueos registrados para hoy.');
                    });
                });
                enlazarFormulario('release-blocks-form', async (_, formularioLocal) => {
                    const identificadores = new FormData(formularioLocal).getAll('ids');
                    if (!identificadores.length) throw new Error('Seleccione al menos un bloqueo activo.');
                    confirmarAccion('Liberar bloqueos', `¿Confirma la liberación de ${identificadores.length} bloqueo(s) de hoy?`, async () => {
                        await clienteApi('agenda-adjustments/release', 'POST', {ids: identificadores});
                        await navegar('CU24'); notificacion('Bloqueos liberados.');
                    });
                });
            },
        };
    };

    vistas.CU25 = async () => {
        let bloques = [];
        const fechaLocal = fechaLocalLocal();
        const aperturas = (await clienteApi('agenda-adjustments?' + new URLSearchParams({date: fechaLocal}))).filter(registro => registro.kind === 'online_opening');
        return {
            html: aviso('Recepción puede habilitar franjas libres protegidas para online. Esta operación no admite retirar la apertura.')
                + panel('Habilitar cupos online', `<form id="protected-query-form">${erroresFormulario}
                ${seleccionar('Médico', 'doctor_id', [['', 'Seleccione…'], ...estadoLocal.refs.doctors.filter(medico => medico.status === 'Activo').map(medico => [medico.id, nombreCompleto(medico)])], '', 'required')}
                ${botonGuardar('Consultar franjas protegidas libres de hoy')}</form><div id="protected-options"></div>`)
                + panel('Habilitaciones de hoy', tabla(['Médico', 'Inicio', 'Fin', 'Responsable'], aperturas.map(registro => [
                    escapar(nombreMedico(registro.doctor_id)), escapar(registro.start_time.slice(0, 5)), escapar(registro.end_time.slice(0, 5)), escapar(`Usuario ${registro.created_by}`),
                ]))),
            bind: () => {
                enlazarEvento('#doctor_id', 'change', () => { bloques = []; $('#protected-options').innerHTML = ''; });
                enlazarFormulario('protected-query-form', async valores => {
                    bloques = (await clienteApi('agenda-adjustments/protected?' + new URLSearchParams({...valores, date: fechaLocal})))
                        .map(bloque => ({doctor_id: valores.doctor_id, date: fechaLocal, start_time: bloque.start_time, end_time: bloque.end_time}));
                    $('#protected-options').innerHTML = bloques.length
                        ? `<form id="open-online-form">${erroresFormulario}<div class="checks">${bloques.map((bloque, indice) => `<label><input type="checkbox" name="blocks" value="${indice}">${escapar(bloque.start_time)}–${escapar(bloque.end_time)}</label>`).join('')}</div>${botonGuardar('Confirmar habilitación online')}</form>`
                        : aviso('No hay franjas protegidas libres para este médico hoy.');
                    if (bloques.length) enlazarFormulario('open-online-form', async (_, formularioLocal) => {
                        const seleccionada = new FormData(formularioLocal).getAll('blocks').map(indice => bloques[Number(indice)]);
                        if (!seleccionada.length) throw new Error('Seleccione al menos una franja.');
                        await clienteApi('agenda-adjustments', 'POST', {kind: 'online_opening', blocks: seleccionada});
                        await navegar('CU25'); notificacion('Franjas habilitadas para online.');
                    });
                });
            },
        };
    };
}
