/** CU17: citas afectadas conservadas, con acciones de Recepción para contactar y resolver. */
export function instalarVistasAfectaciones(interfaz) {
    const { vistas: vistas, clienteApi: clienteApi, panel, aviso: aviso, tabla: tabla, escapar: escapar, codigo: codigo, hora: hora, distintivo: distintivo, nombreCompleto: nombreCompleto, estadoLocal: estadoLocal, boton: boton, enlazarEvento: enlazarEvento, modal, detalles: detalles } = interfaz;
    const textoOrigen = origen => {
        if (origen.entity === 'Unavailability') return `Indisponibilidad n.º ${origen.id}`;
        if (origen.entity === 'Doctor') return `Médico ${codigo('MED', origen.id)}`;
        if (origen.entity === 'Specialty') return `Especialidad ${codigo('ESP', origen.id)}`;
        if (origen.entity === 'DoctorSpecialty') return `Habilitación de ${codigo('MED', origen.doctor_id)} para ${codigo('ESP', origen.specialty_id)}`;
        return `Horario de ${codigo('MED', origen.doctor_id)} · Turnos vigentes: ${origen.schedule_ids.join(', ') || 'Sin turnos configurados'}`;
    };
    vistas.CU17 = async () => {
        const afectadas = await clienteApi('schedule-impacts');
        const recepcion = ['Superadmin', 'Recepcionista'].includes(estadoLocal.user.role);
        return {html: aviso('Estas citas conservan su estado. Recepción debe contactar al paciente y acordar una solución.')
            + panel('Citas futuras afectadas', tabla(['Cita', ...(recepcion ? ['Paciente', 'Teléfono'] : []), 'Médico', 'Especialidad', 'Fecha', 'Horario', 'Estado', 'Motivo', 'Detalle'], afectadas.map(citaLocal => [
                escapar(codigo('CIT', citaLocal.id)), ...(recepcion ? [escapar(nombreCompleto(citaLocal.patient)), escapar(citaLocal.patient?.phone)] : []), escapar(nombreCompleto(estadoLocal.refs.doctors.find(medico => medico.id === citaLocal.doctor_id))),
                escapar(citaLocal.specialty?.name), escapar(citaLocal.date), escapar(`${hora(citaLocal.start_time)}–${hora(citaLocal.end_time)}`),
                distintivo(citaLocal.status), escapar(citaLocal.reasons.join(', ')), boton('Consultar origen', `data-impact="${citaLocal.id}"`, 'link')+(recepcion?boton('Reprogramar',`data-impact-change="${citaLocal.id}"`)+boton('Cancelar',`data-impact-cancel="${citaLocal.id}"`):''),
            ]))), bind: () => {
                const completar=async id=>(await clienteApi('appointments?mode=active')).find(a=>a.id===Number(id));
                enlazarEvento('[data-impact-change]','click',async e=>interfaz.accionesClinicas.reprogramar(await completar(e.currentTarget.dataset.impactChange)));
                enlazarEvento('[data-impact-cancel]','click',async e=>interfaz.accionesClinicas.cancelar(await completar(e.currentTarget.dataset.impactCancel)));
                enlazarEvento('[data-impact]', 'click', evento => {
                const citaLocal = afectadas.find(elemento => elemento.id === Number(evento.currentTarget.dataset.impact));
                modal('Origen de la afectación', detalles([
                    ['Cita', escapar(codigo('CIT', citaLocal.id))], ['Motivos', escapar(citaLocal.reasons.join(', '))],
                    ['Registros de origen', citaLocal.origins.map(origen => escapar(textoOrigen(origen))).join('<br>')],
                ]));
            });}};
    };
}
