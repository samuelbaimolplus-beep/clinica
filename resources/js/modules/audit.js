/** CU22: consulta paginada e inmutable de eventos, actor, dirección IP y metadatos permitidos. */
import {auditLabel, auditValue, auditRecord, auditModule, auditType} from '../lib/audit-format.js';

export function installAuditViews(ui) {
    const { views, api, panel, field, select, formErrors, submit, table, esc, button, on, bindForm, navigate, modal, details } = ui;
    let filters = {};
    let page = 1;
    views.CU22 = async () => {
        const options = await api('audit/filters');
        const result = await api('audit?' + new URLSearchParams({...filters, page}));
        const labels = Object.fromEntries(options.users.map(user => [user.id, user.name]));
        const choices = values => [['', 'Todos'], ...values.map(value => [value, auditLabel(value)])];
        const actor = event => {
            const saved = event.details?.actor;
            return saved ? [saved.name, saved.username && `@${saved.username}`, saved.role].filter(Boolean).join(' · ')
                : labels[event.user_id] || 'Sistema / sin sesión';
        };
        return {
            html: panel('Consultar auditoría', `<form id="audit-form">${formErrors}<div class="grid two">
                ${field('Desde', 'from', 'date', filters.from)}${field('Hasta', 'to', 'date', filters.to)}
                ${field('Dirección IP', 'ip_address', 'text', filters.ip_address,'maxlength="45" placeholder="IPv4 o IPv6"')}
                ${select('Usuario responsable', 'user_id', [['', 'Todos'], ...options.users.map(user => [user.id, `${user.name} (${user.role})`])], filters.user_id)}
                ${select('Módulo', 'module', [['', 'Todos'], ...options.modules.map(value => [value, auditModule(value)])], filters.module)}
                ${select('Acción', 'action', choices(options.actions), filters.action)}
                ${select('Resultado', 'result', choices(options.results), filters.result)}
                </div>${submit('Buscar eventos')}</form>`)
                + panel('Eventos registrados', table(['Fecha', 'Responsable', 'Dirección IP', 'Módulo', 'Acción', 'Resultado', 'Registro y cambios', ''], result.data.map(event => [
                    esc(event.occurred_at), esc(actor(event)), esc(event.ip_address || 'Sin IP registrada'), esc(auditModule(event.module)), esc(auditLabel(event.action)),
                    esc(auditLabel(event.result)), esc(`${auditType(event.record_type)} ${event.record_id ? '#'+event.record_id : ''}`)
                        + `<p>${esc(auditRecord(event))}</p><small>${esc((event.details?.fields || []).map(auditLabel).join(', '))}${event.details?.security_changed ? ' · Credencial actualizada (valor protegido)' : ''}</small>`,
                    button('Consultar', `data-audit="${event.id}"`, 'link'),
                ])) + `<p>${result.total} eventos · Página ${result.current_page} de ${result.last_page}</p><div class="actions">`
                + button('Anterior', `id="audit-prev" ${page <= 1 ? 'disabled' : ''}`, '')
                + button('Siguiente', `id="audit-next" ${page >= result.last_page ? 'disabled' : ''}`, '') + '</div>'),
            bind: () => {
                bindForm('audit-form', async values => { filters = Object.fromEntries(Object.entries(values).filter(([, value]) => value)); page = 1; await navigate('CU22'); });
                on('#audit-prev', 'click', async () => { page--; await navigate('CU22'); });
                on('#audit-next', 'click', async () => { page++; await navigate('CU22'); });
                on('[data-audit]', 'click', async event => {
                    const record = await api(`audit/${event.currentTarget.dataset.audit}`);
                    const info = record.details || {};
                    const changes = Object.entries(info.changes || {});
                    const values = info.record || {};
                    let extra = changes.length
                        ? `<h3>Cambios realizados</h3>${table(['Campo', 'Antes', 'Después'], changes.map(([key, value]) => [
                            esc(auditLabel(key)), esc(auditValue(key, value.before, values)), esc(auditValue(key, value.after, values)),
                        ]))}`
                        : '<p>Este evento no conserva valores anteriores y nuevos. Los eventos históricos solo muestran los datos registrados en su momento.</p>';
                    if (info.security_changed) extra += '<p>Se actualizó una credencial. Su valor permanece protegido.</p>';
                    if (Object.keys(values).length) extra += `<h3>Registro en el momento de la acción</h3>${details(Object.entries(values).map(([key, value]) => [
                        auditLabel(key), esc(auditValue(key, value, values)) + (info.related?.[key] ? ` · ${esc(info.related[key].label)}` : ''),
                    ]))}`;
                    const related = Object.entries(info.related || {}).filter(([key]) => !(key in values));
                    if (related.length) extra += `<h3>Registros relacionados</h3>${details(related.map(([key, value]) => [auditLabel(key), esc(`${value.label} (#${value.id})`)]))}`;
                    if (info.fields?.length && !changes.length) extra += `<p>Campos registrados: ${esc(info.fields.map(auditLabel).join(', '))}</p>`;
                    if (info.specialty_ids?.length && !changes.length) extra += `<p>Especialidades: ${esc(info.specialty_ids.join(', '))}</p>`;
                    modal('Detalle del evento', details([
                        ['Fecha', esc(record.occurred_at)], ['Responsable', esc(actor(record))],
                        ['Dirección IP', esc(record.ip_address || 'No disponible: proceso interno o evento anterior a este registro')],
                        ['Módulo y acción', esc(`${auditModule(record.module)} · ${auditLabel(record.action)}`)], ['Resultado', esc(auditLabel(record.result))],
                        ['Registro afectado', esc(`${auditType(record.record_type)} ${record.record_id || ''}`)],
                        ...(info.reason ? [['Motivo', esc(auditValue('reason', info.reason))]] : []),
                        ...(info.terms_version ? [['Versión de los términos', esc(info.terms_version)]] : []),
                        ...(info.request ? [['Operación', esc(`${info.request.method} /${info.request.route}`)]] : []),
                    ]) + extra);
                });
            },
        };
    };
}
