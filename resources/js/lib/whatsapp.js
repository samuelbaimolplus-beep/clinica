/** Enlaces de apertura manual: preparar el mensaje no confirma su envío ni su entrega. */
export function numeroWhatsApp(telefono) {
    const original = String(telefono ?? '').trim();
    if (!original || !/^[+\d\s().-]+$/.test(original)) return null;
    const numero = original.replace(/[\s().-]/g, '');
    if (/^[67]\d{7}$/.test(numero)) return `591${numero}`;
    if (/^591[67]\d{7}$/.test(numero)) return numero;
    const internacional = numero.startsWith('+') ? numero.slice(1) : numero.startsWith('00') ? numero.slice(2) : '';
    return /^[1-9]\d{7,14}$/.test(internacional) ? internacional : null;
}

export function mensajeReprogramacion(cita) {
    const nombre = persona => [persona?.first_name, persona?.last_name].filter(Boolean).join(' ');
    const [anio, mes, dia] = String(cita.date).split('-');
    return `Hola ${nombre(cita.patient)}. Clínica Vida Salud confirma la reprogramación acordada de su cita.\n\n`
        + `Fecha: ${dia}/${mes}/${anio}\nHorario: ${cita.start_time.slice(0, 5)}–${cita.end_time.slice(0, 5)} (hora de Santa Cruz, Bolivia).\n`
        + `Médico/a: ${nombre(cita.doctor)}\nCita n.º ${cita.id}\n\nGracias por confirmar el nuevo horario.`;
}

export function enlaceReprogramacion(cita) {
    const numero = numeroWhatsApp(cita.patient?.phone);
    if (!numero) return null;
    return `https://wa.me/${numero}?text=${encodeURIComponent(mensajeReprogramacion(cita))}`;
}
