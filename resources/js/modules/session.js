export function installSessionViews(ui) {
    const {views, state, panel, details, esc, badge, button, on, logout, notice, field,
        formErrors, submit, bindForm, api, navigate, toast} = ui;
    views.CU02 = async () => ({
        html: panel('Sesión activa', details([
            ['Nombre',esc(state.user.name)],['Usuario de acceso',esc(state.user.username)],['Correo',esc(state.user.email)],['Rol',badge(state.user.role)],
        ]) + `<div class="actions">${button('Cerrar sesión','id="session-logout"','danger')}</div>`)
            + panel('Elegir mi usuario de acceso', notice('Este es el usuario que escribirás para iniciar sesión. Puede ser distinto de tu nombre y tu correo. Usa de 3 a 50 caracteres: letras sin acentos, números, punto, guion o guion bajo.')
                + `<form id="my-username-form">${formErrors}
                ${field('Usuario de acceso','username','text',state.user.username,'required minlength="3" maxlength="50" autocomplete="username"')}
                ${field('Confirma tu contraseña actual','current_password','password','','required maxlength="255" autocomplete="current-password"')}
                ${submit('Guardar usuario de acceso')}</form>`),
        bind: () => {
            on('#session-logout','click',logout);
            bindForm('my-username-form',async(data, form)=>{
                const result=await api('account/username','PATCH',data);
                form.reset();state.user=result.user;
                await navigate('CU02');toast(result.message);
            });
        },
    });
}
