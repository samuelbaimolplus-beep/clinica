export function installSessionViews(ui) {
    const {views, state, panel, details, esc, badge, button, on, logout, notice, field,
        formErrors, submit, bindForm, api, navigate, toast, textarea} = ui;
    views.CU02 = async () => {
        const profile=await api('account/profile');
        return ({
        html: panel('Sesión activa', details([
            ['Nombre',esc(state.user.name)],['Usuario de acceso',esc(state.user.username)],['Correo',esc(state.user.email)],['Rol',badge(state.user.role)],
        ]) + `<div class="actions">${button('Cerrar sesión','id="session-logout"','danger')}</div>`)
            + panel('Elegir mi usuario de acceso', notice('Este es el usuario que escribirás para iniciar sesión. Puede ser distinto de tu nombre y tu correo. Usa de 3 a 50 caracteres: letras sin acentos, números, punto, guion o guion bajo.')
                + `<form id="my-username-form">${formErrors}
                ${field('Usuario de acceso','username','text',state.user.username,'required minlength="3" maxlength="50" autocomplete="username"')}
                ${field('Confirma tu contraseña actual','current_password','password','','required maxlength="255" autocomplete="current-password"')}
                ${submit('Guardar usuario de acceso')}</form>`)
            + panel('Cambiar mi contraseña', `<form id="my-password-form">${formErrors}
                ${field('Contraseña actual','password_current','password','','required maxlength="255" autocomplete="current-password"')}
                ${field('Nueva contraseña','password','password','','required minlength="10" maxlength="128" autocomplete="new-password"')}
                ${field('Repetir nueva contraseña','password_confirmation','password','','required minlength="10" maxlength="128" autocomplete="new-password"')}
                ${submit('Cambiar contraseña')}</form>`)
            + panel('Mis datos de contacto',notice('Si cambia su correo, tendrá que confirmarlo con un nuevo código para volver a acceder.')
                +`<form id="my-profile-form">${formErrors}
                ${field('Mi correo electrónico','email','email',state.user.email,'required maxlength="255" autocomplete="email"')}
                ${state.user.role==='Paciente'?field('Mi teléfono','phone','tel',profile.patient?.phone,'required maxlength="30"')+textarea('Mi dirección','address',profile.patient?.address,'maxlength="1000"'):''}
                ${field('Contraseña para guardar mis datos','profile_password','password','','required maxlength="255" autocomplete="current-password"')}
                ${submit('Guardar mis datos')}</form>`),
        bind: () => {
            on('#session-logout','click',logout);
            bindForm('my-username-form',async(data, form)=>{
                const result=await api('account/username','PATCH',data);
                form.reset();state.user=result.user;
                await navigate('CU02');toast(result.message);
            });
            bindForm('my-password-form',async(data, form)=>{
                const result=await api('account/password','PATCH',{current_password:data.password_current,password:data.password,password_confirmation:data.password_confirmation});
                form.reset();state.user=result.user;
                await navigate('CU02');toast(result.message);
            });
            bindForm('my-profile-form',async(data,form)=>{
                data.current_password=data.profile_password;delete data.profile_password;
                const result=await api('account/profile','PATCH',data);form.reset();state.user=result.user;
                if(result.activation_required){await navigate('CU01');views.activation(result.email);toast(result.message);return;}
                await navigate('CU02');toast(result.message);
            });
        },
    });};
}
