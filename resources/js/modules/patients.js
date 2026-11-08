// Activación por correo y recuperación para cuentas registradas por la clínica.
export function installPatientViews(ui) {
    const {views, main, panel, formErrors, field, select, submit, button, on, bindForm, api, toast, loginView} = ui;
    views.activation = (email = '') => {
        main.innerHTML = `<div class="login-wrap">${panel('Activar mi cuenta', `<p>Ingrese el código de 8 dígitos enviado a su correo. Hasta confirmarlo, su cuenta no podrá acceder a los módulos.</p>
            <form id="activation-form">${formErrors}
            ${field('Correo de la cuenta','email','email',email,'required maxlength="255" autocomplete="email"')}
            ${field('Código de activación','code','text','','required inputmode="numeric" pattern="[0-9]{8}" minlength="8" maxlength="8" autocomplete="one-time-code"')}
            ${submit('Confirmar código y activar')}</form>
            <p>El código vence en 15 minutos. Puede solicitar otro después de un minuto; el anterior dejará de funcionar.</p>
            ${button('Enviar o reenviar código','id="resend-code"','link')}
            ${button('Volver al inicio de sesión','id="activation-back"','link')}`)}</div>`;
        on('#activation-back','click',loginView);
        on('#resend-code','click',async()=>{
            const input=document.querySelector('#activation-form [name="email"]');
            if(!input.reportValidity())return;
            const result=await api('account/email','POST',{email:input.value,purpose:'verify'});toast(result.message);
        });
        bindForm('activation-form',async(data,form)=>{
            const result=await api('account/activate','POST',data);form.reset();loginView();toast(result.message);
        });
    };
    views.recovery = () => {
        main.innerHTML = `<div class="login-wrap">${panel('Recuperar acceso', `<form id="recovery-form">${formErrors}
            ${field('Correo de la cuenta','email','email','','required maxlength="255" autocomplete="email"')}
            ${select('Operación','purpose',[['recover','Recuperar contraseña'],['verify','Solicitar código de activación']],'recover')}
            ${submit('Enviar instrucciones por correo')}</form>
            ${button('Ingresar código de activación','id="recovery-activate"','link')}
            ${button('Volver al inicio de sesión','id="recovery-back"','link')}`)}</div>`;
        on('#recovery-back','click',loginView);
        on('#recovery-activate','click',()=>views.activation(document.querySelector('#recovery-form [name="email"]').value));
        bindForm('recovery-form',async data=>{const result=await api('account/email','POST',data);toast(result.message);if(data.purpose==='verify')views.activation(data.email);});
    };
}
