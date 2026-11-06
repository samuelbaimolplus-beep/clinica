// CU23 propuesto y consulta del perfil propio. La sesión determina el paciente en el servidor.
export function installPatientViews(ui) {
    const { state, views, main, panel, formErrors, field, select, textarea, localDate,
        submit, button, on, bindForm, api, references, navigate, toast, loginView,
        details, esc, full, code, getTermsVersion } = ui;

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
            ${field('Correo de acceso', 'email', 'email', '', 'required maxlength="255" autocomplete="email"')}
            ${textarea('Dirección (opcional)', 'address', '', 'maxlength="1000"')}
            ${field('Contraseña (mínimo 10 caracteres)', 'password', 'password', '', 'required minlength="10" maxlength="128" autocomplete="new-password"')}
            ${field('Repetir contraseña', 'password_confirmation', 'password', '', 'required minlength="10" maxlength="128" autocomplete="new-password"')}
            </div><label class="terms-accept"><input type="checkbox" name="terms_accepted" value="1" required>
            Acepto las <a href="/terms" target="_blank" rel="noopener">condiciones de la cuenta</a> (versión ${esc(getTermsVersion())}).</label>
            ${submit('Crear mi cuenta')}</form>${button('Volver al inicio de sesión', 'id="back-login"', 'link')}`)}</div>`;
        on('#back-login', 'click', loginView);
        bindForm('registration-form', async data => {
            const result = await api('register', 'POST', {...data, terms_version: getTermsVersion()});
            state.user = result.user;
            await references();
            await navigate('PERFIL');
            toast('Cuenta de paciente creada correctamente.');
        });
    };

    views.PERFIL = async () => {
        const result = await api('profile');
        const person = result.patient || result.doctor;
        const info = [['Nombre', esc(person ? full(person) : result.user.name)],
            ['Correo de acceso', esc(result.user.email)], ['Rol', esc(result.user.role)]];
        if (person) info.push(['Código', esc(code(result.patient ? 'PAC' : 'MED', person.id))],
            ['Estado', esc(person.status)], ['Teléfono', esc(person.phone)]);
        if (result.doctor) info.push(['Especialidades', esc(result.doctor.specialties.map(s => s.name).join(', '))]);
        let contact = '';
        if (result.patient) {
            info.push(['CI', esc(person.identity || 'Sin documento')], ['Nacimiento', esc(person.birth_date)]);
            contact = panel('Actualizar contacto',
                `<form id="contact-form">${formErrors}<div class="grid two">
                ${field('Teléfono', 'phone', 'tel', person.phone, 'required maxlength="30"')}
                ${textarea('Dirección', 'address', person.address, 'maxlength="1000"')}
                </div>${submit('Guardar contacto')}</form><p>Para corregir sus datos de identidad, solicite la verificación en recepción. Las reservas online se habilitarán en una etapa posterior.</p>`);
        }
        const password = panel('Cambiar contraseña',
            `<form id="password-form">${formErrors}<div class="grid two">
            ${field('Contraseña actual', 'current_password', 'password', '', 'required maxlength="128" autocomplete="current-password"')}
            ${field('Nueva contraseña (mínimo 10 caracteres)', 'password', 'password', '', 'required minlength="10" maxlength="128" autocomplete="new-password"')}
            ${field('Repetir nueva contraseña', 'password_confirmation', 'password', '', 'required minlength="10" maxlength="128" autocomplete="new-password"')}
            </div><p>Su sesión actual continuará abierta. Las demás deberán volver a iniciar sesión.</p>
            ${submit('Actualizar contraseña')}</form>`);
        return {
            html: panel('Mi perfil', details(info)) + contact + password,
            bind: () => {
                if (result.patient) bindForm('contact-form', async data => {
                    await api('profile', 'PUT', data);
                    await navigate('PERFIL');
                    toast('Datos de contacto actualizados.');
                });
                bindForm('password-form', async data => {
                    const response = await api('profile/password', 'PUT', data);
                    await navigate('PERFIL');
                    toast(response.message);
                });
            },
        };
    };
}
