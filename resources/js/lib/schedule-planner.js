/** Cálculo visual de huecos y cruces; los turnos consecutivos no se superponen. */
export const shortTime = value => String(value || '').slice(0, 5);
export function conflictingShifts(schedules, values, editingId = null) {
    if (!values.start_time || !values.end_time) return [];
    return schedules.filter(shift => String(shift.id) !== String(editingId)
        && Number(shift.weekday) === Number(values.weekday)
        && shortTime(shift.start_time) < values.end_time
        && shortTime(shift.end_time) > values.start_time);
}
export function suggestedShift(schedules, weekday) {
    const minutes = text => Number(text.slice(0, 2)) * 60 + Number(text.slice(3, 5));
    const format = value => `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
    const shifts = schedules.filter(s => Number(s.weekday) === Number(weekday))
        .sort((a, b) => shortTime(a.start_time).localeCompare(shortTime(b.start_time)));
    let start = 8 * 60;
    for (const shift of shifts) {
        if (minutes(shift.start_time) - start >= 30) return {start_time:format(start), end_time:format(Math.min(start + 240, minutes(shift.start_time)))};
        start = Math.max(start, minutes(shift.end_time));
    }
    return start + 30 <= 18 * 60 ? {start_time:format(start), end_time:format(Math.min(start + 240, 18 * 60))} : {start_time:'', end_time:''};
}
