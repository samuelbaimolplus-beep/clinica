// CU23: registro y recuperación por correo según V6.
export function installPatientViews(ui) {
    const { state, views, main, panel, formErrors, field, select, textarea, localDate,
        submit, button, on, bindForm, api, toast, loginView, esc, getTermsVersion } = ui;

    views.registration = () => {
        main.innerHTML = `<div class="registration-wrap">${panel('Crear cuenta de paciente',
            `<p>Si ya fue registrado en la clínica, solicite la verificación de su cuenta en recepción.</p>
            <form id="registration-form">${formErrors}<div class="grid two">
            ${field('Nombres', 'first_name', 'text', '', 'required maxlength="100" autocomplete="given-name"')}
            ${field('Apellidos', 'last_name', 'text', '', 'required maxlength="150" autocomplete="family-name"')}
            ${field('CI (opcional)', 'identity', 'text', '', 'maxlength="40"')}
            ${field('Código de vinculación (solo si recepción se lo entregó)', 'activation_code', 'text', '', 'minlength="48" maxlength="48" autocomplete="off"')}
            ${field('Fecha de nacimiento', 'birth_date', 'date', '', `required max="${localDate()}"`)}
            ${select('Sexo', 'sex', [['', 'Seleccione…'], 'Femenino', 'Masculino'], '', 'required')}
            ${field('Teléfono', 'phone', 'tel', '', 'required maxlength="30" autocomplete="tel"')}
            ${field('Nombre de usuario (3–50 letras, números, punto o guion)', 'username', 'text', '', 'required minlength="3" maxlength="50" autocomplete="username"')}
            ${field('Correo electrónico (para verificar y recuperar acceso)', 'email', 'email', '', 'required maxlength="255" autocomplete="email"')}
            ${textarea('Dirección (opcional)', 'address', '', 'maxlength="1000"')}
            ${field('Contraseña (mínimo 10 caracteres)', 'password', 'password', '', 'required minlength="10" maxlength="128" autocomplete="new-password"')}
            ${field('Repetir contraseña', 'password_confirmation', 'password', '', 'required minlength="10" maxlength="128" autocomplete="new-password"')}
            </div><label class="terms-accept"><input type="checkbox" name="terms_accepted" value="1" required>
            Acepto las <a href="/terms" target="_blank" rel="noopener">condiciones de la cuenta</a> (versión ${esc(getTermsVersion())}).</label>
            ${submit('Crear mi cuenta')}</form>${button('Volver al inicio de sesión', 'id="back-login"', 'link')}`)}</div>`;
        on('#back-login', 'click', loginView);
        const accept = document.querySelector('#registration-form [name="terms_accepted"]');
        const create = document.querySelector('#registration-form [type="submit"]');
        create.disabled = !accept.checked;
        accept.addEventListener('change', () => { create.disabled = !accept.checked; });
        bindForm('registration-form', async data => {
            const result = await api('register', 'POST', {...data, terms_version: getTermsVersion()});
            state.user = null;
            loginView();
            toast(result.message);
        });
    };

    views.recovery = () => {
        main.innerHTML = `<div class="login-wrap">${panel('Recuperar acceso', `<form id="recovery-form">${formErrors}
            ${field('Correo de la cuenta', 'email', 'email', '', 'required maxlength="255" autocomplete="email"')}
            ${select('Operación', 'purpose', [['recover', 'Recuperar contraseña'], ['verify', 'Verificar correo']], 'recover')}
            ${submit('Enviar instrucciones por correo')}</form>${button('Volver al inicio de sesión', 'id="recovery-back"', 'link')}`)}</div>`;
        on('#recovery-back', 'click', loginView);
        bindForm('recovery-form', async data => { const result = await api('account/email', 'POST', data); toast(result.message); });
    };
}
