/** CU22: consulta paginada e inmutable de eventos, actor, dirección IP y metadatos permitidos. */
export function installAuditViews(ui) {
    const { views, api, panel, field, select, formErrors, submit, table, esc, button, on, bindForm, navigate, modal, details } = ui;
    let filters = {};
    let page = 1;
    views.CU22 = async () => {
        const options = await api('audit/filters');
        const result = await api('audit?' + new URLSearchParams({...filters, page}));
        const labels = Object.fromEntries(options.users.map(user => [user.id, user.name]));
        const choices = values => [['', 'Todos'], ...values.map(value => [value, value])];
        return {
            html: panel('Consultar auditoría', `<form id="audit-form">${formErrors}<div class="grid two">
                ${field('Desde', 'from', 'date', filters.from)}${field('Hasta', 'to', 'date', filters.to)}
                ${field('Dirección IP', 'ip_address', 'text', filters.ip_address,'maxlength="45" placeholder="IPv4 o IPv6"')}
                ${select('Usuario responsable', 'user_id', [['', 'Todos'], ...options.users.map(user => [user.id, `${user.name} (${user.role})`])], filters.user_id)}
                ${select('Módulo', 'module', choices(options.modules), filters.module)}
                ${select('Acción', 'action', choices(options.actions), filters.action)}
                ${select('Resultado', 'result', choices(options.results), filters.result)}
                </div>${submit('Buscar eventos')}</form>`)
                + panel('Eventos registrados', table(['Fecha', 'Responsable', 'Dirección IP', 'Módulo', 'Acción', 'Resultado', 'Registro', ''], result.data.map(event => [
                    esc(event.occurred_at), esc(labels[event.user_id] || 'Sistema / sin sesión'), esc(event.ip_address || 'Sin IP registrada'), esc(event.module), esc(event.action),
                    esc(event.result), esc(`${event.record_type || '—'} ${event.record_id || ''}`), button('Consultar', `data-audit="${event.id}"`, 'link'),
                ])) + `<p>${result.total} eventos · Página ${result.current_page} de ${result.last_page}</p><div class="actions">`
                + button('Anterior', `id="audit-prev" ${page <= 1 ? 'disabled' : ''}`, '')
                + button('Siguiente', `id="audit-next" ${page >= result.last_page ? 'disabled' : ''}`, '') + '</div>'),
            bind: () => {
                bindForm('audit-form', async values => { filters = Object.fromEntries(Object.entries(values).filter(([, value]) => value)); page = 1; await navigate('CU22'); });
                on('#audit-prev', 'click', async () => { page--; await navigate('CU22'); });
                on('#audit-next', 'click', async () => { page++; await navigate('CU22'); });
                on('[data-audit]', 'click', async event => {
                    const record = await api(`audit/${event.currentTarget.dataset.audit}`);
                    modal('Detalle del evento', details([
                        ['Fecha', esc(record.occurred_at)], ['Responsable', esc(labels[record.user_id] || 'Sistema / sin sesión')],
                        ['Dirección IP', esc(record.ip_address || 'No disponible: proceso interno o evento anterior a este registro')],
                        ['Módulo y acción', esc(`${record.module} · ${record.action}`)], ['Resultado', esc(record.result)],
                        ['Registro afectado', esc(`${record.record_type || '—'} ${record.record_id || ''}`)],
                        ['Detalles', `<pre style="white-space:pre-wrap;overflow-wrap:anywhere">${esc(JSON.stringify(record.details, null, 2))}</pre>`],
                    ]));
                });
            },
        };
    };
}
