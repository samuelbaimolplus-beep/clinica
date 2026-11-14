/** CU22: consulta paginada e inmutable de eventos, actor, dirección IP y metadatos permitidos. */
import {etiquetaAuditoria, valorAuditoria, registroAuditoria, moduloAuditoria, tipoAuditoria, fechaHoraAuditoria} from "../lib/formato-auditoria.js";

export function instalarVistasAuditoria(interfaz) {
    const { vistas: vistas, clienteApi: clienteApi, panel, campo: campo, seleccionar: seleccionar, erroresFormulario: erroresFormulario, botonGuardar: botonGuardar, tabla: tabla, escapar: escapar, boton: boton, enlazarEvento: enlazarEvento, enlazarFormulario: enlazarFormulario, navegar: navegar, modal, detalles: detalles } = interfaz;
    let filtros = {};
    let pagina = 1;
    vistas.CU22 = async () => {
        const opciones = await clienteApi('audit/filters');
        const resultado = await clienteApi('audit?' + new URLSearchParams({...filtros, page: pagina}));
        const etiquetas = Object.fromEntries(opciones.users.map(usuario => [usuario.id, usuario.name]));
        const alternativas = valores => [['', 'Todos'], ...valores.map(valorDato => [valorDato, etiquetaAuditoria(valorDato)])];
        const responsable = evento => {
            const guardadoLocal = evento.details?.actor;
            return guardadoLocal ? [guardadoLocal.name, guardadoLocal.username && `@${guardadoLocal.username}`, guardadoLocal.role].filter(Boolean).join(' · ')
                : etiquetas[evento.user_id] || 'Sistema / sin sesión';
        };
        return {
            html: panel('Consultar auditoría', `<form id="audit-form">${erroresFormulario}<div class="grid two">
                ${campo('Desde', 'from', 'date', filtros.from)}${campo('Hasta', 'to', 'date', filtros.to)}
                ${campo('Dirección IP', 'ip_address', 'text', filtros.ip_address,'maxlength="45" placeholder="IPv4 o IPv6"')}
                ${seleccionar('Usuario responsable', 'user_id', [['', 'Todos'], ...opciones.users.map(usuario => [usuario.id, `${usuario.name} (${usuario.role})`])], filtros.user_id)}
                ${seleccionar('Módulo', 'module', [['', 'Todos'], ...opciones.modules.map(valorDato => [valorDato, moduloAuditoria(valorDato)])], filtros.module)}
                ${seleccionar('Acción', 'action', alternativas(opciones.actions), filtros.action)}
                ${seleccionar('Resultado', 'result', alternativas(opciones.results), filtros.result)}
                </div>${botonGuardar('Buscar eventos')}</form>`)
                + panel('Eventos registrados', '<p>Fechas y horas de Santa Cruz, Bolivia (UTC−4).</p>' + tabla(['Fecha', 'Responsable', 'Dirección IP', 'Módulo', 'Acción', 'Resultado', 'Registro y cambios', ''], resultado.data.map(evento => [
                    escapar(fechaHoraAuditoria(evento.occurred_at)), escapar(responsable(evento)), escapar(evento.ip_address || 'Sin IP registrada'), escapar(moduloAuditoria(evento.module)), escapar(etiquetaAuditoria(evento.action)),
                    escapar(etiquetaAuditoria(evento.result)), escapar(`${tipoAuditoria(evento.record_type)} ${evento.record_id ? '#'+evento.record_id : ''}`)
                        + `<p>${escapar(registroAuditoria(evento))}</p><small>${escapar((evento.details?.fields || []).map(etiquetaAuditoria).join(', '))}${evento.details?.security_changed ? ' · Credencial actualizada (valor protegido)' : ''}</small>`,
                    boton('Consultar', `data-audit="${evento.id}"`, 'link'),
                ])) + `<p>${resultado.total} eventos · Página ${resultado.current_page} de ${resultado.last_page}</p><div class="actions">`
                + boton('Anterior', `id="audit-prev" ${pagina <= 1 ? 'disabled' : ''}`, '')
                + boton('Siguiente', `id="audit-next" ${pagina >= resultado.last_page ? 'disabled' : ''}`, '') + '</div>'),
            bind: () => {
                enlazarFormulario('audit-form', async valores => { filtros = Object.fromEntries(Object.entries(valores).filter(([, valorDato]) => valorDato)); pagina = 1; await navegar('CU22'); });
                enlazarEvento('#audit-prev', 'click', async () => { pagina--; await navegar('CU22'); });
                enlazarEvento('#audit-next', 'click', async () => { pagina++; await navegar('CU22'); });
                enlazarEvento('[data-audit]', 'click', async evento => {
                    const registro = await clienteApi(`audit/${evento.currentTarget.dataset.audit}`);
                    const informacion = registro.details || {};
                    const cambios = Object.entries(informacion.changes || {});
                    const valores = informacion.record || {};
                    let adicional = cambios.length
                        ? `<h3>Cambios realizados</h3>${tabla(['Campo', 'Antes', 'Después'], cambios.map(([clave, valorDato]) => [
                            escapar(etiquetaAuditoria(clave)), escapar(valorAuditoria(clave, valorDato.before, valores)), escapar(valorAuditoria(clave, valorDato.after, valores)),
                        ]))}`
                        : '<p>Este evento no conserva valores anteriores y nuevos. Los eventos históricos solo muestran los datos registrados en su momento.</p>';
                    if (informacion.security_changed) adicional += '<p>Se actualizó una credencial. Su valor permanece protegido.</p>';
                    if (Object.keys(valores).length) adicional += `<h3>Registro en el momento de la acción</h3>${detalles(Object.entries(valores).map(([clave, valorDato]) => [
                        etiquetaAuditoria(clave), escapar(valorAuditoria(clave, valorDato, valores)) + (informacion.related?.[clave] ? ` · ${escapar(informacion.related[clave].label)}` : ''),
                    ]))}`;
                    const relacionados = Object.entries(informacion.related || {}).filter(([clave]) => !(clave in valores));
                    if (relacionados.length) adicional += `<h3>Registros relacionados</h3>${detalles(relacionados.map(([clave, valorDato]) => [etiquetaAuditoria(clave), escapar(`${valorDato.label} (#${valorDato.id})`)]))}`;
                    if (informacion.fields?.length && !cambios.length) adicional += `<p>Campos registrados: ${escapar(informacion.fields.map(etiquetaAuditoria).join(', '))}</p>`;
                    if (informacion.specialty_ids?.length && !cambios.length) adicional += `<p>Especialidades: ${escapar(informacion.specialty_ids.join(', '))}</p>`;
                    modal('Detalle del evento', detalles([
                        ['Fecha (Santa Cruz, UTC−4)', escapar(fechaHoraAuditoria(registro.occurred_at))], ['Responsable', escapar(responsable(registro))],
                        ['Dirección IP', escapar(registro.ip_address || 'No disponible: proceso interno o evento anterior a este registro')],
                        ['Módulo y acción', escapar(`${moduloAuditoria(registro.module)} · ${etiquetaAuditoria(registro.action)}`)], ['Resultado', escapar(etiquetaAuditoria(registro.result))],
                        ['Registro afectado', escapar(`${tipoAuditoria(registro.record_type)} ${registro.record_id || ''}`)],
                        ...(informacion.reason ? [['Motivo', escapar(valorAuditoria('reason', informacion.reason))]] : []),
                        ...(informacion.terms_version ? [['Versión de los términos', escapar(informacion.terms_version)]] : []),
                        ...(informacion.request ? [['Operación', escapar(`${informacion.request.method} /${informacion.request.route}`)]] : []),
                    ]) + adicional);
                });
            },
        };
    };
}
