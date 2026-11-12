/** Consultas globales de pagos, exenciones y cuentas para Superadmin. */
export function installSupervisionViews(ui) {
    const {views, api, panel, notice, table, esc, full, badge, button, on, navigate} = ui;
    const money = amount => `Bs ${(Number(amount) / 100).toFixed(2)}`;
    views.CU23 = async () => {
        const accounts = await api('supervision/patient-accounts');
        return {html: notice('Las cuentas se crean presencialmente en la clínica desde Pacientes. Recepción, Administrador y Superadmin pueden registrar al paciente y habilitar su acceso.')
            + panel('Cuentas de pacientes', table(['Paciente', 'Usuario', 'Correo', 'Estado de cuenta', 'Estado del paciente', 'Verificación', 'Términos aceptados'], accounts.map(a => [
                esc(full(a.patient)), esc(a.username), esc(a.email), badge(a.status), badge(a.patient?.status), a.email_verified_at ? 'Correo verificado' : 'Verificación pendiente', esc(a.terms_accepted_at ? a.terms_version : 'Sin aceptación registrada'),
            ])))};
    };
    views.CU19 = async () => {
        let page = 1;
        async function render() {
            const result = await api(`supervision/payments?page=${page}`);
            return {html: notice('Los cobros se registran al programar una cita presencial, reservar por internet o registrar un seguimiento. Aquí puede consultar su estado y referencia. Los pagos online se confirman automáticamente con el proveedor.')
                + panel('Pagos registrados', table(['Pago', 'Paciente', 'Medio', 'Importe', 'Estado', 'Referencia'], result.data.map(p => [
                    esc(p.id), esc(full(p.booking_request?.patient || p.appointments?.[0]?.patient)), esc(({cash:'Efectivo',qr:'QR presencial',transfer:'Transferencia',paypal:'PayPal Sandbox'})[p.method] || p.method), esc(money(p.amount_minor)), badge(({confirmed:'Confirmado',pending:'Pendiente',initializing:'Preparando',unknown:'En verificación',failed:'No completado'})[p.status] || p.status), esc(p.reference || p.bank_reference || '—'),
                ])) + `<div class="actions">${button('Anterior', `id="payments-prev" ${page <= 1 ? 'disabled' : ''}`)}${button('Siguiente', `id="payments-next" ${page >= result.last_page ? 'disabled' : ''}`)}</div>`)
                + panel('Seguimientos con exención (últimos 50)', table(['Cita', 'Consulta original', 'Paciente', 'Fecha', 'Estado'], (result.exemptions || []).map(a => [esc(a.id), esc(a.parent_appointment_id), esc(full(a.patient)), esc(a.date), badge(a.status)]))),
                bind: () => {
                    on('#payments-prev', 'click', async () => { page--; await refresh(); });
                    on('#payments-next', 'click', async () => { page++; await refresh(); });
                }};
        }
        async function refresh() {
            const content = await render();
            const container = document.querySelector('#supervision-payments');
            if (container) { container.innerHTML = content.html; content.bind(); }
        }
        const content = await render();
        return {html: `<div id="supervision-payments">${content.html}</div>`, bind: content.bind};
    };
}
