/** Activación y recuperación de cuentas creadas presencialmente; no ofrece autorregistro. */
// Activación por correo y recuperación para cuentas registradas por la clínica.
export function instalarVistasPacientes(interfaz) {
    const {vistas: vistas, principal: principal, panel, erroresFormulario: erroresFormulario, campo: campo, seleccionar: seleccionar, botonGuardar: botonGuardar, boton: boton, enlazarEvento: enlazarEvento, enlazarFormulario: enlazarFormulario, clienteApi: clienteApi, notificacion: notificacion, vistaInicioSesion: vistaInicioSesion} = interfaz;
    vistas.activation = (correo = '') => {
        principal.innerHTML = `<div class="login-wrap">${panel('Activar mi cuenta', `<p>Ingrese el código de 8 dígitos enviado a su correo. Hasta confirmarlo, su cuenta no podrá acceder a los módulos.</p>
            <form id="activation-form">${erroresFormulario}
            ${campo('Correo de la cuenta','email','email',correo,'required maxlength="255" autocomplete="email"')}
            ${campo('Código de activación','code','text','','required inputmode="numeric" pattern="[0-9]{8}" minlength="8" maxlength="8" autocomplete="one-time-code"')}
            ${botonGuardar('Confirmar código y activar')}</form>
            <p>El código vence en 15 minutos. Puede solicitar otro después de un minuto; el anterior dejará de funcionar.</p>
            ${boton('Enviar o reenviar código','id="resend-code"','link')}
            ${boton('Volver al inicio de sesión','id="activation-back"','link')}`)}</div>`;
        enlazarEvento('#activation-back','click',vistaInicioSesion);
        enlazarEvento('#resend-code','click',async()=>{
            const entrada=document.querySelector('#activation-form [name="email"]');
            if(!entrada.reportValidity())return;
            const resultado=await clienteApi('account/email','POST',{email:entrada.value,purpose:'verify'});notificacion(resultado.message);
        });
        enlazarFormulario('activation-form',async(datos,formularioLocal)=>{
            const resultado=await clienteApi('account/activate','POST',datos);formularioLocal.reset();vistaInicioSesion();notificacion(resultado.message);
        });
    };
    vistas.recovery = () => {
        principal.innerHTML = `<div class="login-wrap">${panel('Recuperar acceso', `<form id="recovery-form">${erroresFormulario}
            ${campo('Correo de la cuenta','email','email','','required maxlength="255" autocomplete="email"')}
            ${seleccionar('Operación','purpose',[['recover','Recuperar contraseña'],['verify','Solicitar código de activación']],'recover')}
            ${botonGuardar('Enviar instrucciones por correo')}</form>
            ${boton('Ingresar código de activación','id="recovery-activate"','link')}
            ${boton('Volver al inicio de sesión','id="recovery-back"','link')}`)}</div>`;
        enlazarEvento('#recovery-back','click',vistaInicioSesion);
        enlazarEvento('#recovery-activate','click',()=>vistas.activation(document.querySelector('#recovery-form [name="email"]').value));
        enlazarFormulario('recovery-form',async datos=>{const resultado=await clienteApi('account/email','POST',datos);notificacion(resultado.message);if(datos.purpose==='verify')vistas.activation(datos.email);});
    };
}
