/** Consultas globales de pagos, exenciones y cuentas para Superadmin. */
export function instalarVistasSupervision(interfaz) {
    const {vistas: vistas, clienteApi: clienteApi, panel, aviso: aviso, tabla: tabla, escapar: escapar, nombreCompleto: nombreCompleto, distintivo: distintivo, boton: boton, enlazarEvento: enlazarEvento, navegar: navegar} = interfaz;
    const importeMonetario = importe => `Bs ${(Number(importe) / 100).toFixed(2)}`;
    vistas.CU23 = async () => {
        const cuentas = await clienteApi('supervision/patient-accounts');
        return {html: aviso('Las cuentas se crean presencialmente en la clínica desde Pacientes. Recepción, Administrador y Superadmin pueden registrar al paciente y habilitar su acceso.')
            + panel('Cuentas de pacientes', tabla(['Paciente', 'Usuario', 'Correo', 'Estado de cuenta', 'Estado del paciente', 'Verificación', 'Términos aceptados'], cuentas.map(a => [
                escapar(nombreCompleto(a.patient)), escapar(a.username), escapar(a.email), distintivo(a.status), distintivo(a.patient?.status), a.email_verified_at ? 'Correo verificado' : 'Verificación pendiente', escapar(a.terms_accepted_at ? a.terms_version : 'Sin aceptación registrada'),
            ])))};
    };
    vistas.CU19 = async () => {
        let pagina = 1;
        async function representar() {
            const resultado = await clienteApi(`supervision/payments?page=${pagina}`);
            return {html: aviso('Los cobros se registran al programar una cita presencial, reservar por internet o registrar un seguimiento. Aquí puede consultar su estado y referencia. Los pagos online se confirman automáticamente con el proveedor.')
                + panel('Pagos registrados', tabla(['Pago', 'Paciente', 'Medio', 'Importe', 'Estado', 'Referencia'], resultado.data.map(p => [
                    escapar(p.id), escapar(nombreCompleto(p.booking_request?.patient || p.appointments?.[0]?.patient)), escapar(({cash:'Efectivo',qr:'QR presencial',transfer:'Transferencia',paypal:'PayPal Sandbox'})[p.method] || p.method), escapar(importeMonetario(p.amount_minor)), distintivo(({confirmed:'Confirmado',pending:'Pendiente',initializing:'Preparando',unknown:'En verificación',failed:'No completado'})[p.status] || p.status), escapar(p.reference || p.bank_reference || '—'),
                ])) + `<div class="actions">${boton('Anterior', `id="payments-prev" ${pagina <= 1 ? 'disabled' : ''}`)}${boton('Siguiente', `id="payments-next" ${pagina >= resultado.last_page ? 'disabled' : ''}`)}</div>`)
                + panel('Seguimientos con exención (últimos 50)', tabla(['Cita', 'Consulta original', 'Paciente', 'Fecha', 'Estado'], (resultado.exemptions || []).map(a => [escapar(a.id), escapar(a.parent_appointment_id), escapar(nombreCompleto(a.patient)), escapar(a.date), distintivo(a.status)]))),
                bind: () => {
                    enlazarEvento('#payments-prev', 'click', async () => { pagina--; await actualizar(); });
                    enlazarEvento('#payments-next', 'click', async () => { pagina++; await actualizar(); });
                }};
        }
        async function actualizar() {
            const contenidoTexto = await representar();
            const contenedor = document.querySelector('#supervision-payments');
            if (contenedor) { contenedor.innerHTML = contenidoTexto.html; contenidoTexto.bind(); }
        }
        const contenidoTexto = await representar();
        return {html: `<div id="supervision-payments">${contenidoTexto.html}</div>`, bind: contenidoTexto.bind};
    };
}
