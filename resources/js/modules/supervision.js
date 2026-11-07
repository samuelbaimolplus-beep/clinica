export function installSupervisionViews(ui) {
    const {views, api, panel, notice, table, esc, full, badge, button, on, navigate} = ui;
    const money = amount => `Bs ${(Number(amount) / 100).toFixed(2)}`;
    views.CU23 = async () => {
        const accounts = await api('supervision/patient-accounts');
        return {html: notice('Supervisión de CU23. El paciente crea su propia cuenta desde «Crear cuenta de paciente» en el inicio de sesión y verifica su correo.')
            + panel('Cuentas de pacientes', table(['Paciente', 'Usuario', 'Correo', 'Estado de cuenta', 'Estado del paciente', 'Verificación', 'Términos aceptados'], accounts.map(a => [
                esc(full(a.patient)), esc(a.username), esc(a.email), badge(a.status), badge(a.patient?.status), a.email_verified_at ? 'Correo verificado' : 'Verificación pendiente', esc(a.terms_accepted_at ? a.terms_version : 'Sin aceptación registrada'),
            ])))};
    };
    views.CU19 = async () => {
        let page = 1;
        async function render() {
            const result = await api(`supervision/payments?page=${page}`);
            return {html: notice('CU19 se ejecuta dentro de la programación presencial, la reserva online o el seguimiento. Esta vista permite supervisar los pagos; no permite confirmar pagos online manualmente.')
                + panel('Pagos registrados', table(['Pago', 'Paciente', 'Medio', 'Importe', 'Estado', 'Referencia'], result.data.map(p => [
                    esc(p.id), esc(full(p.booking_request?.patient || p.appointments?.[0]?.patient)), esc(p.method), esc(money(p.amount_minor)), esc(p.status), esc(p.reference || p.bank_reference || '—'),
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
