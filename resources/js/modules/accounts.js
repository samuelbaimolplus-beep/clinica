/** Administración de cuentas y contraseñas por personal autorizado, sin mostrar claves existentes. */
export function installAccountManagementViews(ui) {
    const {views, state, api, panel, notice, button, table, esc, badge, modal, on, field,
        formErrors, submit, bindForm, closeModal, toast, navigate} = ui;
    const staffView = views.CU03;

    async function changePassword(account) {
        modal(`Cambiar contraseña: ${account.username}`, `<form id="account-password-form">${formErrors}
            ${notice('La contraseña actual no se puede consultar. Guarde una contraseña nueva y comuníquela únicamente a la persona titular de esta cuenta.')}
            ${field('Nueva contraseña', 'password', 'password', '', 'required minlength="10" maxlength="128" autocomplete="new-password"')}
            ${field('Repetir contraseña nueva', 'password_confirmation', 'password', '', 'required minlength="10" maxlength="128" autocomplete="new-password"')}
            ${submit('Guardar nueva contraseña')}</form>`);
        bindForm('account-password-form', async (data, form) => {
            const result = await api(`supervision/accounts/${account.id}/password`, 'POST', data);
            form.reset(); closeModal(); toast(result.message);
            if (account.id === state.user.id) {
                state.user = (await api('session')).user;
                await navigate('CU01');
            }
        });
    }

    async function accounts() {
        const records = await api('supervision/accounts');
        modal('Todas las cuentas de acceso', notice('Consulta de personal y pacientes, exclusiva de Superadmin. Los datos de las contraseñas permanecen protegidos.')
            + table(['Usuario', 'Nombre', 'Correo', 'Rol', 'Estado', 'Activación', 'Acción'], records.map(account => [
                esc(account.username), esc(account.name), esc(account.email), esc(account.role), badge(account.status), esc(account.email_verified_at?'Correo confirmado':'Pendiente de código'), button('Cambiar contraseña', `data-account-password="${account.id}"`),
            ])));
        on('[data-account-password]', 'click', event => changePassword(records.find(account => account.id === Number(event.currentTarget.dataset.accountPassword))));
    }

    views.CU03 = async () => {
        const content = await staffView();
        if (state.user.role !== 'Superadmin') return content;
        return {
            html: panel('Acceso a todas las cuentas', notice('Consulte también las cuentas de pacientes y cambie sus contraseñas cuando corresponda.') + button('Consultar todas las cuentas', 'id="manage-all-accounts"')) + content.html,
            bind: () => { content.bind?.(); on('#manage-all-accounts', 'click', accounts); },
        };
    };
}
