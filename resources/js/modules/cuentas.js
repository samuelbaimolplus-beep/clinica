/** Administración de cuentas y contraseñas por personal autorizado, sin mostrar claves existentes. */
export function instalarVistasCuentas(interfaz) {
    const {vistas: vistas, estadoLocal: estadoLocal, clienteApi: clienteApi, panel, aviso: aviso, boton: boton, tabla: tabla, escapar: escapar, distintivo: distintivo, modal, enlazarEvento: enlazarEvento, campo: campo,
        erroresFormulario: erroresFormulario, botonGuardar: botonGuardar, enlazarFormulario: enlazarFormulario, cerrarModal: cerrarModal, notificacion: notificacion, navegar: navegar} = interfaz;
    const vistaPersonal = vistas.CU03;

    async function cambiarContrasena(cuenta) {
        modal(`Cambiar contraseña: ${cuenta.username}`, `<form id="account-password-form">${erroresFormulario}
            ${aviso('La contraseña actual no se puede consultar. Guarde una contraseña nueva y comuníquela únicamente a la persona titular de esta cuenta.')}
            <p class="help-text">Puede asignar una contraseña sencilla de al menos 6 caracteres. No se exigen mayúsculas, números ni símbolos.</p>
            ${campo('Nueva contraseña', 'password', 'password', '', 'required minlength="6" maxlength="128" autocomplete="new-password"')}
            ${campo('Repetir contraseña nueva', 'password_confirmation', 'password', '', 'required minlength="6" maxlength="128" autocomplete="new-password"')}
            ${botonGuardar('Guardar nueva contraseña')}</form>`);
        enlazarFormulario('account-password-form', async (datos, formularioLocal) => {
            const resultado = await clienteApi(`supervision/accounts/${cuenta.id}/password`, 'POST', datos);
            formularioLocal.reset(); cerrarModal(); notificacion(resultado.message);
            if (cuenta.id === estadoLocal.user.id) {
                estadoLocal.user = (await clienteApi('session')).user;
                await navegar('CU01');
            }
        });
    }

    async function cuentas() {
        const registros = await clienteApi('supervision/accounts');
        modal('Todas las cuentas de acceso', aviso('Consulta de personal y pacientes, exclusiva de Superadmin. Los datos de las contraseñas permanecen protegidos.')
            + tabla(['Usuario', 'Nombre', 'Correo', 'Rol', 'Estado', 'Activación', 'Acción'], registros.map(cuenta => [
                escapar(cuenta.username), escapar(cuenta.name), escapar(cuenta.email), escapar(cuenta.role), distintivo(cuenta.status), escapar(cuenta.email_verified_at?'Correo confirmado':'Pendiente de código'), boton('Cambiar contraseña', `data-account-password="${cuenta.id}"`),
            ])));
        enlazarEvento('[data-account-password]', 'click', evento => cambiarContrasena(registros.find(cuenta => cuenta.id === Number(evento.currentTarget.dataset.accountPassword))));
    }

    vistas.CU03 = async () => {
        const contenidoTexto = await vistaPersonal();
        if (estadoLocal.user.role !== 'Superadmin') return contenidoTexto;
        return {
            html: panel('Acceso a todas las cuentas', aviso('Consulte también las cuentas de pacientes y cambie sus contraseñas cuando corresponda.') + boton('Consultar todas las cuentas', 'id="manage-all-accounts"')) + contenidoTexto.html,
            bind: () => { contenidoTexto.bind?.(); enlazarEvento('#manage-all-accounts', 'click', cuentas); },
        };
    };
}
