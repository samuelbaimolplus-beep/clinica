/** Gestión personal de usuario, contraseña y contacto; exige confirmar la contraseña actual. */
export function instalarVistasSesion(interfaz) {
    const {vistas: vistas, estadoLocal: estadoLocal, panel, detalles: detalles, escapar: escapar, distintivo: distintivo, boton: boton, enlazarEvento: enlazarEvento, cerrarSesion: cerrarSesion, aviso: aviso, campo: campo,
        erroresFormulario: erroresFormulario, botonGuardar: botonGuardar, enlazarFormulario: enlazarFormulario, clienteApi: clienteApi, navegar: navegar, notificacion: notificacion, areaTexto: areaTexto} = interfaz;
    vistas.CU02 = async () => {
        const perfil=await clienteApi('account/profile');
        return ({
        html: panel('Sesión activa', detalles([
            ['Nombre',escapar(estadoLocal.user.name)],['Usuario de acceso',escapar(estadoLocal.user.username)],['Correo',escapar(estadoLocal.user.email)],['Rol',distintivo(estadoLocal.user.role)],
        ]) + `<div class="actions">${boton('Cerrar sesión','id="session-logout"','danger')}</div>`)
            + panel('Elegir mi usuario de acceso', aviso('Este es el usuario que escribirás para iniciar sesión. Puede ser distinto de tu nombre y tu correo. Usa de 3 a 50 caracteres: letras sin acentos, números, punto, guion o guion bajo.')
                + `<form id="my-username-form">${erroresFormulario}
                ${campo('Usuario de acceso','username','text',estadoLocal.user.username,'required minlength="3" maxlength="50" autocomplete="username"')}
                ${campo('Confirma tu contraseña actual','current_password','password','','required maxlength="255" autocomplete="current-password"')}
                ${botonGuardar('Guardar usuario de acceso')}</form>`)
            + panel('Cambiar mi contraseña', `<form id="my-password-form">${erroresFormulario}
                ${campo('Contraseña actual','password_current','password','','required maxlength="255" autocomplete="current-password"')}
                ${campo('Nueva contraseña','password','password','','required minlength="10" maxlength="128" autocomplete="new-password"')}
                ${campo('Repetir nueva contraseña','password_confirmation','password','','required minlength="10" maxlength="128" autocomplete="new-password"')}
                ${botonGuardar('Cambiar contraseña')}</form>`)
            + panel('Mis datos de contacto',aviso('Si cambia su correo, tendrá que confirmarlo con un nuevo código para volver a acceder.')
                +`<form id="my-profile-form">${erroresFormulario}
                ${campo('Mi correo electrónico','email','email',estadoLocal.user.email,'required maxlength="255" autocomplete="email"')}
                ${estadoLocal.user.role==='Paciente'?campo('Mi teléfono','phone','tel',perfil.patient?.phone,'required maxlength="30"')+areaTexto('Mi dirección','address',perfil.patient?.address,'maxlength="1000"'):''}
                ${campo('Contraseña para guardar mis datos','profile_password','password','','required maxlength="255" autocomplete="current-password"')}
                ${botonGuardar('Guardar mis datos')}</form>`),
        bind: () => {
            enlazarEvento('#session-logout','click',cerrarSesion);
            enlazarFormulario('my-username-form',async(datos, formularioLocal)=>{
                const resultado=await clienteApi('account/username','PATCH',datos);
                formularioLocal.reset();estadoLocal.user=resultado.user;
                await navegar('CU02');notificacion(resultado.message);
            });
            enlazarFormulario('my-password-form',async(datos, formularioLocal)=>{
                const resultado=await clienteApi('account/password','PATCH',{current_password:datos.password_current,password:datos.password,password_confirmation:datos.password_confirmation});
                formularioLocal.reset();estadoLocal.user=resultado.user;
                await navegar('CU02');notificacion(resultado.message);
            });
            enlazarFormulario('my-profile-form',async(datos,formularioLocal)=>{
                datos.current_password=datos.profile_password;delete datos.profile_password;
                const resultado=await clienteApi('account/profile','PATCH',datos);formularioLocal.reset();estadoLocal.user=resultado.user;
                if(resultado.activation_required){await navegar('CU01');vistas.activation(resultado.email);notificacion(resultado.message);return;}
                await navegar('CU02');notificacion(resultado.message);
            });
        },
    });};
}
