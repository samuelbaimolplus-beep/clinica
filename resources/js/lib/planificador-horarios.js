/** Cálculo visual de huecos y cruces; los turnos consecutivos no se superponen. */
export const horaCorta = valorDato => String(valorDato || '').slice(0, 5);
export function turnosEnConflicto(horarios, valores, idEnEdicion = null) {
    if (!valores.start_time || !valores.end_time) return [];
    return horarios.filter(turno => String(turno.id) !== String(idEnEdicion)
        && Number(turno.weekday) === Number(valores.weekday)
        && horaCorta(turno.start_time) < valores.end_time
        && horaCorta(turno.end_time) > valores.start_time);
}
export function turnoSugerido(horarios, diaLaboral) {
    const minutos = textoDato => Number(textoDato.slice(0, 2)) * 60 + Number(textoDato.slice(3, 5));
    const formato = valorDato => `${String(Math.floor(valorDato / 60)).padStart(2, '0')}:${String(valorDato % 60).padStart(2, '0')}`;
    const turnos = horarios.filter(s => Number(s.weekday) === Number(diaLaboral))
        .sort((a, b) => horaCorta(a.start_time).localeCompare(horaCorta(b.start_time)));
    let inicioFranja = 8 * 60;
    for (const turno of turnos) {
        if (minutos(turno.start_time) - inicioFranja >= 30) return {start_time:formato(inicioFranja), end_time:formato(Math.min(inicioFranja + 240, minutos(turno.start_time)))};
        inicioFranja = Math.max(inicioFranja, minutos(turno.end_time));
    }
    return inicioFranja + 30 <= 18 * 60 ? {start_time:formato(inicioFranja), end_time:formato(Math.min(inicioFranja + 240, 18 * 60))} : {start_time:'', end_time:''};
}
