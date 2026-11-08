import {test, expect} from '@playwright/test';

// Navegador real + interfaz compilada; API simulada sin tocar pacientes ni hacer pagos.
// La autorización y persistencia reales se comprueban por separado en Feature con PostgreSQL.
const access = {
    Superadmin: Array.from({length:26},(_,i)=>`CU${String(i+1).padStart(2,'0')}`),
    Administrador: ['CU01','CU02','CU03','CU04','CU05','CU06','CU07','CU22','CU15','CU16','CU26'],
    Recepcionista: ['CU01','CU02','CU06','CU24','CU25','CU08','CU17','CU09','CU10','CU11','CU12','CU13','CU15','CU20','CU21'],
    Médico: ['CU01','CU02','CU12','CU14'],
    Paciente: ['CU01','CU02','CU08','CU10','CU15','CU18','CU20'],
};
const specialty = {id:1,name:'Medicina general',status:'Activo',fee_minor:10000};
const doctor = {id:1,first_name:'Ana',last_name:'Prueba',registration:'BROWSER-1',status:'Activo',specialties:[specialty]};
const patient = {id:1,first_name:'Paciente',last_name:'Prueba',status:'Activo'};
const paymentUrl = 'https://www.sandbox.paypal.com/checkoutnow?token=PP-PRUEBA-001';
const slot = {doctor_id:1,doctor_name:'Ana Prueba',start_time:'10:30',end_time:'11:00',modality:'online'};

async function mockClinic(page, role, options={}) {
    const user = role ? {id:1,name:`Cuenta ${role}`,username:'cuenta_prueba',email:'cuenta@example.test',role,status:'Activo'} : null;
    const state = {calls:[],pending:0,confirmed:false,authenticated:!options.initiallyGuest,activated:!options.requireActivation,patients:[{...patient}]};
    const booking = {id:1,date:'2030-11-11',start_time:'10:30',end_time:'11:00',status:'processing',attempt:1,amount_minor:10000,expires_at:new Date(Date.now()+300000).toISOString()};
    const payment = {id:1,method:'paypal',status:'pending',amount_minor:10000,provider_amount_minor:1000,provider_currency:'USD',exchange_rate:'10',approval_url:'https://www.sandbox.paypal.com/checkoutnow?token=PP-PRUEBA-001'};
    const appointment = {id:1,patient,doctor,specialty,date:booking.date,start_time:booking.start_time,end_time:booking.end_time,status:'Programada',payment:{...payment,method:'paypal',reference:'PP-PRUEBA-001',status:'confirmed'},...options.appointmentOverrides};
    await page.route('**/api/**', async route => {
        const url = new URL(route.request().url()), path=url.pathname.slice(5);
        state.calls.push({path,method:route.request().method(),data:route.request().postDataJSON()});
        let data;
        if(path==='session')data={user:state.authenticated?user:null,access:options.staleAccessBeforeLogin&&!state.authenticated?{}:access,csrf:'browser-test-csrf',terms_version:'2026-11-02'};
        else if(path==='login') {
            if(!state.activated){await route.fulfill({status:403,json:{activation_required:true,email:user.email,message:'Debe activar su cuenta con el código enviado a su correo.'}});return;}
            state.authenticated=!options.sessionNotPersisted;
            if(options.alreadyActive) {await route.fulfill({status:409,json:{message:'Ya tiene una sesión activa.'}});return;}
            data={user,csrf:'browser-test-csrf'};
        }
        else if(path==='references') {
            if(options.referencesFailOnce && !state.failedReferences) {state.failedReferences=true;await route.fulfill({status:503,json:{message:'No se pudo cargar el panel.'}});return;}
            data={doctors:[doctor],specialties:[specialty],patients:[patient]};
        }
        else if(path==='medical-availability/options')data={online_payment_available:options.paymentAvailable!==false,bob_per_usd:'10',payment_provider:'paypal',doctors:[doctor],specialties:[specialty]};
        else if(path==='medical-availability')data={blocks:[{...slot,...options.slotOverrides}],modality:url.searchParams.get('modality')||'online'};
        else if(path==='appointments'&&route.request().method()==='POST')data=appointment;
        else if(path==='online-bookings'&&route.request().method()==='POST')data={booking,payment,approval_url:payment.approval_url};
        else if(path==='online-bookings/1/status')data={booking,payment,appointment:state.confirmed?appointment:null};
        else if(path==='online-bookings/1/cancel')data={booking:{...booking,status:'cancelled'},payment:{...payment,status:'failed'}};
        else if(path==='online-bookings')data=[];
        else if(path==='schedules')data={schedules:[],unavailabilities:[],quota:{presential_percent:50,online_percent:50}};
        else if(path==='reception-inbox')data={pending:state.pending,latest_id:state.pending?1:null};
        else if(path==='audit/filters')data={users:[],modules:[],actions:[],results:[]};
        else if(path==='audit'||path==='supervision/payments')data={data:[],total:0,current_page:1,last_page:1};
        else if(path==='entities/users')data=[{...user,id:1}];
        else if(path==='supervision/accounts')data=[{...user,id:1},{id:2,name:'Ana paciente',username:'ana.paciente',email:'ana@example.test',role:'Paciente',status:'Activo'}];
        else if(path==='supervision/accounts/2/password')data={message:'Contraseña cambiada.'};
        else if(path==='account/username') {user.username=route.request().postDataJSON().username;data={user,csrf:'renewed-username-csrf',message:'Tu usuario de acceso se actualizó correctamente.'};}
        else if(path==='account/profile')data={user,patient:{...patient,phone:'70000000',address:'Dirección de prueba'}};
        else if(path==='account/password')data={user,csrf:'renewed-password-csrf',message:'Contraseña actualizada.'};
        else if(path==='account/activate'){state.activated=true;data={message:'Cuenta activada. Ya puede iniciar sesión.'};}
        else if(path==='account/email')data={message:'Si la cuenta está habilitada, recibirá las instrucciones en su correo.'};
        else if(path==='patients/with-account') {
            const input=route.request().postDataJSON();
            data={...patient,...input,account:{id:2,username:input.username,email:input.email,email_verified_at:null},activation_email_sent:true};
            delete data.password;delete data.password_confirmation;state.patients=[data];
        }
        else if(path==='patients/1/account') {
            const input=route.request().postDataJSON();data={user:{id:2,username:input.username,email:input.email,email_verified_at:null},activation_email_sent:true,message:'Cuenta creada. Confirme el código enviado por correo.'};
            state.patients[0].account=data.user;
        }
        else if(path==='entities/doctors')data=[doctor];
        else if(path==='entities/specialties')data=[specialty];
        else if(path==='entities/patients')data=state.patients;
        else if(['appointments','reschedule-requests','refunds','schedule-impacts','agenda-adjustments','supervision/patient-accounts'].includes(path))data=[];
        else {
            await route.fulfill({status:500,json:{message:`Ruta no simulada: ${path}`}});
            return;
        }
        await route.fulfill({json:data});
    });
    return state;
}

for(const [role,home] of [['Superadmin','CU03'],['Administrador','CU03'],['Recepcionista','CU06'],['Médico','CU12'],['Paciente','CU18']]) {
    test(`${role}: inicio y menú restringidos`, async ({page}) => {
        const errors=[];page.on('pageerror',e=>errors.push(e.message));
        await mockClinic(page,role);await page.goto('/');
        await expect(page.locator(`.nav-btn[data-view="${home}"]`)).toHaveAttribute('aria-current','page');
        const visible=await page.locator('.nav-btn').evaluateAll(nodes=>nodes.map(n=>n.dataset.view));
        expect(visible.sort()).toEqual(access[role].filter(id=>id!=='CU01').sort());
        if (['Superadmin','Paciente'].includes(role)) await page.screenshot({path:`storage/app/private/vista-${role.toLowerCase()}.png`,fullPage:true});
        expect(errors).toEqual([]);
    });
}

test('Superadmin abre todas las vistas sin fallos de ejecución', async ({page}) => {
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await mockClinic(page,'Superadmin');await page.goto('/');
    for(let number=2;number<=26;number++) {
        const id=`CU${String(number).padStart(2,'0')}`;
        await page.locator(`.nav-btn[data-view="${id}"]`).click();
        await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view',id);
        await expect(page.locator('#main')).not.toHaveAttribute('aria-busy','true');
        await expect(page.locator('body')).not.toContainText(/\bCU\d{2}\b/);
        await expect(page.locator('#retry')).toHaveCount(0);
        await expect(page.locator('#main')).not.toContainText('undefined');
    }
    expect(errors).toEqual([]);
});

for(const width of [360,768]) {
    test(`menú y pacientes adaptables a ${width}px, sin códigos técnicos ni desbordamiento`,async({page})=>{
        await page.setViewportSize({width,height:900});
        await mockClinic(page,'Superadmin');await page.goto('/#pacientes');
        await expect(page.getByRole('heading',{name:'Pacientes',exact:true})).toBeVisible();
        await expect(page.locator('#main')).not.toHaveAttribute('aria-busy','true');
        await expect(page.locator('body')).not.toContainText(/\bCU\d{2}\b/);
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        if(width===360)expect(await page.locator('tbody tr').first().evaluate(el=>getComputedStyle(el).display)).toBe('block');
        await page.locator('#menu-toggle').click();
        await expect(page.locator('#menu-toggle')).toHaveAttribute('aria-expanded','true');
        await expect(page.locator('#nav-search')).toBeFocused();
        await page.getByRole('searchbox',{name:'Buscar en el menú'}).fill('medicos');
        await expect(page.locator('.nav-btn:visible')).toHaveCount(2);
        await page.getByRole('button',{name:'Médicos',exact:true}).click();
        await expect(page.getByRole('heading',{name:'Médicos',exact:true})).toBeVisible();
        await expect(page.locator('#menu-backdrop')).toBeHidden();
        await expect(page).toHaveURL(/#medicos$/);
        await page.locator('#menu-toggle').click();await page.keyboard.press('Escape');
        await expect(page.locator('#menu-toggle')).toBeFocused();
        await expect(page.locator('#menu-toggle')).toHaveAttribute('aria-expanded','false');
        await page.goto('/#pacientes');
        await page.getByRole('button',{name:'+ Registrar paciente y cuenta',exact:true}).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        const dialog=await page.getByRole('dialog').boundingBox();
        expect(dialog.x).toBeGreaterThanOrEqual(0);expect(dialog.x+dialog.width).toBeLessThanOrEqual(width);
        expect(await page.getByRole('dialog').evaluate(el=>el.scrollWidth<=el.clientWidth)).toBe(true);
        await page.screenshot({path:`storage/app/private/interfaz-pacientes-${width}.png`,fullPage:true});
    });
}

for(const width of [390,1440]) {
    test(`inicio de sesión terminado y adaptable a ${width}px`,async({page})=>{
        await page.setViewportSize({width,height:1000});
        await mockClinic(page,null);await page.goto('/');
        await expect(page.locator('#login-form')).toBeVisible();
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        await expect(page.locator('#menu-toggle')).toBeHidden();
        await expect(page.locator('body')).not.toContainText(/\bCU\d{2}\b/);
        await page.screenshot({path:`storage/app/private/interfaz-acceso-${width}.png`,fullPage:true});
    });
}

test('todos los módulos se adaptan a móvil y el historial del navegador conserva la navegación',async({page})=>{
    await page.setViewportSize({width:390,height:844});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await mockClinic(page,'Superadmin');await page.goto('/#pacientes');
    for(let number=2;number<=26;number++) {
        const id=`CU${String(number).padStart(2,'0')}`;
        await page.evaluate(id=>{location.hash=id;},id);
        await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view',id);
        await expect(page.locator('#main')).not.toHaveAttribute('aria-busy','true');
        await expect(page.locator('#retry')).toHaveCount(0);
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),id).toBe(true);
    }
    await page.locator('#menu-toggle').click();await page.getByRole('button',{name:'Pacientes',exact:true}).click();
    await expect(page).toHaveURL(/#pacientes$/);
    await page.goBack();await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU26');
    await page.goForward();await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU06');
    expect(errors).toEqual([]);
});

for (const role of ['Superadmin','Administrador']) {
    test(`${role}: formulario de personal respeta la asignación de Superadmin`, async ({page}) => {
        await mockClinic(page,role);await page.goto('/');
        await page.getByRole('button',{name:'+ Registrar usuario',exact:true}).click();
        await expect(page.getByLabel('Nombre de usuario', {exact:true})).toBeVisible();
        await expect(page.locator('#role option[value="Superadmin"]')).toHaveCount(role==='Superadmin'?1:0);
        await page.locator('#role').selectOption('Médico');
        await expect(page.locator('#doctor-link')).toBeVisible();
        await expect(page.locator('#doctor_id')).toHaveAttribute('required','');
    });
}

test('el acceso admite un nombre de usuario sin formato de correo electrónico', async ({page}) => {
    const state=await mockClinic(page,'Paciente',{initiallyGuest:true});await page.goto('/');
    await expect(page.locator('#email')).toHaveAttribute('type','text');
    await page.getByLabel('Usuario o correo electrónico', {exact:true}).fill('ana.paciente');
    await page.locator('#password').fill('ClaveDePrueba2026');
    await page.getByRole('button',{name:'Iniciar sesión',exact:true}).click();
    await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU18');
    expect(state.calls.find(c=>c.path==='login').data.email).toBe('ana.paciente');
});

async function submitLogin(page) {
    await page.locator('#email').fill('superadmin');
    await page.locator('#password').fill('ClaveDePrueba2026');
    await page.getByRole('button',{name:'Iniciar sesión',exact:true}).click();
}

test('el login actualiza los permisos de una pestaña abierta antes de agregar el rol', async ({page}) => {
    await mockClinic(page,'Superadmin',{initiallyGuest:true,staleAccessBeforeLogin:true});await page.goto('/');
    await submitLogin(page);
    await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU03');
    await expect(page.locator('#login-form')).toHaveCount(0);
});

test('el login recupera la sesión activa sin atascarse en el formulario', async ({page}) => {
    await mockClinic(page,'Superadmin',{initiallyGuest:true,alreadyActive:true});await page.goto('/');
    await submitLogin(page);
    await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU03');
});

test('si no se conserva la sesión no se muestra bienvenida y se explica el fallo', async ({page}) => {
    await mockClinic(page,'Superadmin',{initiallyGuest:true,sessionNotPersisted:true});await page.goto('/');
    await submitLogin(page);
    await expect(page.locator('#main')).toContainText('No se pudo conservar la sesión');
    await expect(page.locator('#toasts')).not.toContainText('Bienvenido');
});

test('un fallo al cargar el panel se puede reintentar sin volver a enviar credenciales', async ({page}) => {
    const state=await mockClinic(page,'Superadmin',{initiallyGuest:true,referencesFailOnce:true});await page.goto('/');
    await submitLogin(page);
    await page.locator('#boot-retry').click();
    await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU03');
    expect(state.calls.filter(c=>c.path==='login')).toHaveLength(1);
});

test('Superadmin puede cambiar la contraseña de un paciente sin consultar la contraseña anterior', async ({page}) => {
    const state=await mockClinic(page,'Superadmin');await page.goto('/');
    await page.getByRole('button',{name:'Consultar todas las cuentas'}).click();
    await page.getByRole('row').filter({hasText:'ana.paciente'}).getByRole('button',{name:'Cambiar contraseña'}).click();
    await expect(page.getByRole('dialog')).toContainText('La contraseña actual no se puede consultar');
    await page.getByLabel('Nueva contraseña',{exact:true}).fill('NuevaSegura2026');
    await page.getByLabel('Repetir contraseña nueva',{exact:true}).fill('NuevaSegura2026');
    await page.getByRole('button',{name:'Guardar nueva contraseña'}).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    expect(state.calls.find(c=>c.path==='supervision/accounts/2/password').data.password_confirmation).toBe('NuevaSegura2026');
});

test('Administrador no tiene acceso a la consulta global de cuentas', async ({page}) => {
    await mockClinic(page,'Administrador');await page.goto('/');
    await expect(page.getByRole('button',{name:'Consultar todas las cuentas'})).toHaveCount(0);
});

test('cualquier cuenta puede elegir su usuario desde Mi sesión', async ({page}) => {
    const state=await mockClinic(page,'Paciente');await page.goto('/#CU02');
    await page.getByLabel('Usuario de acceso',{exact:true}).fill('mi.nuevo.usuario');
    await page.getByLabel('Confirma tu contraseña actual',{exact:true}).fill('ClaveDePrueba2026');
    await page.getByRole('button',{name:'Guardar usuario de acceso'}).click();
    await expect(page.getByLabel('Usuario de acceso',{exact:true})).toHaveValue('mi.nuevo.usuario');
    expect(state.calls.find(c=>c.path==='account/username').method).toBe('PATCH');
});

test('Paciente consulta, retiene y obtiene ficha únicamente después de la confirmación del servidor', async ({page}) => {
    const state=await mockClinic(page,'Paciente');await page.goto('/');
    await page.locator('#specialty_id').selectOption('1');await page.locator('#date').fill('2030-11-11');
    await page.getByRole('button',{name:'Seleccionar',exact:true}).click();
    expect(state.calls.filter(c=>c.path==='online-bookings'&&c.method==='POST')).toHaveLength(0);
    await page.getByRole('button',{name:'Reservar y pagar con PayPal Sandbox'}).click();
    await expect(page.getByRole('dialog')).toContainText('Pago con PayPal Sandbox');
    await expect(page.getByRole('dialog')).not.toContainText('Ficha CIT-');
    await expect(page.getByRole('link',{name:'Continuar en PayPal Sandbox'})).toHaveAttribute('href',paymentUrl);
    const request=state.calls.find(c=>c.path==='online-bookings'&&c.method==='POST');
    expect(request.data.doctor_id).toBe(1);expect(request.data.patient_id).toBeUndefined();expect(request.data.idempotency_key).toMatch(/^[a-f0-9-]{36}$/);
    state.confirmed=true;await page.getByRole('button',{name:'Consultar estado',exact:true}).click();
    await expect(page.getByRole('dialog')).toContainText('Ficha CIT-0001');
    await expect(page.getByRole('dialog')).toContainText('PP-PRUEBA-001');
    await expect(page.getByRole('button',{name:'Imprimir ficha'})).toBeVisible();
});

for (const [name,appointmentOverrides,expected] of [
    ['efectivo',{payment:{id:17,method:'cash',status:'confirmed',amount_minor:10000}},'Pago n.º 17'],
    ['transferencia',{payment:{id:18,method:'transfer',status:'confirmed',amount_minor:10000,bank_reference:'BANCO-PRUEBA-018'}},'BANCO-PRUEBA-018'],
    ['exención',{payment:null,complimentary:true,parent_appointment_id:9},'Consulta original CIT-0009'],
]) {
    test(`ficha de ${name} muestra su referencia y distingue un bloqueo provisional`,async({page})=>{
        await mockClinic(page,'Recepcionista',{appointmentOverrides,slotOverrides:{modality:'presential',demand_blocked:true}});
        await page.goto('/#CU09');
        await page.locator('#patient_id').selectOption('1');
        await page.locator('#specialty_id').selectOption('1');
        await page.locator('#date').fill('2030-11-11');
        await expect(page.locator('#clinical-slots')).toContainText('Bloqueo provisional de esta especialidad');
        await page.getByRole('button',{name:'Seleccionar',exact:true}).click();
        await page.locator('[name="payment_confirmed"]').check();
        await page.getByRole('button',{name:'Verificar y emitir ficha',exact:true}).click();
        await expect(page.getByRole('dialog')).toContainText(expected);
    });
}

test('el retorno de PayPal consulta al servidor y no confirma por el token de la URL', async ({page}) => {
    const state=await mockClinic(page,'Paciente');
    await page.goto('/?paypal_booking=1&token=NO-CONFIRMA&PayerID=NO-CONFIRMA#CU18');
    await expect(page.getByRole('dialog')).toContainText('Pago con PayPal Sandbox');
    await expect(page.getByRole('dialog')).not.toContainText('Ficha CIT-');
    expect(state.calls.filter(c=>c.path==='online-bookings/1/status'&&c.method==='POST')).toHaveLength(1);
    expect(state.calls.some(c=>c.path==='online-bookings'&&c.method==='POST')).toBe(false);
    expect(new URL(page.url()).searchParams.has('paypal_booking')).toBe(false);
});

test('sin pasarela hay horarios y aviso pero no un botón para iniciar un cobro', async ({page}) => {
    const state=await mockClinic(page,'Paciente',{paymentAvailable:false});await page.goto('/');
    await expect(page.locator('#main')).toContainText('El pago online está temporalmente indisponible');
    await expect(page.getByRole('button',{name:'Reservar y pagar con PayPal Sandbox'})).toHaveCount(0);
    expect(state.calls.some(c=>c.path==='online-bookings'&&c.method==='POST')).toBe(false);
});

test('Paciente puede descartar la solicitud cuando el servidor confirma que no existe pago en proceso', async ({page}) => {
    await mockClinic(page,'Paciente');await page.goto('/');
    await page.locator('#specialty_id').selectOption('1');await page.locator('#date').fill('2030-11-11');
    await page.getByRole('button',{name:'Seleccionar',exact:true}).click();
    await page.getByRole('button',{name:'Reservar y pagar con PayPal Sandbox'}).click();
    await page.getByRole('button',{name:'Descartar solicitud sin pago'}).click();
    await expect(page.getByRole('dialog')).toContainText('Solicitud descartada');
    await expect(page.getByRole('dialog')).not.toContainText('Ficha CIT-');
});

test('Superadmin supervisa reservas sin emitirlas en nombre de un paciente', async ({page}) => {
    await mockClinic(page,'Superadmin');await page.goto('/#CU18');
    await expect(page.locator('#main')).toContainText('Operaciones online de todos los pacientes');
    await expect(page.getByRole('button',{name:'Reservar y pagar con PayPal Sandbox'})).toHaveCount(0);
});

test('el inicio no permite registrarse y presenta la activación por código de correo', async ({page}) => {
    const state=await mockClinic(page,null);await page.goto('/');
    await expect(page.getByRole('button',{name:'Crear cuenta de paciente'})).toHaveCount(0);
    await expect(page.locator('#main')).toContainText('acuda a la clínica');
    await page.getByRole('button',{name:'Activar mi cuenta',exact:true}).click();
    await page.getByLabel('Correo de la cuenta',{exact:true}).fill('paciente@example.test');
    await page.getByLabel('Código de activación',{exact:true}).fill('12345678');
    await page.getByRole('button',{name:'Confirmar código y activar'}).click();
    await expect(page.locator('#login-form')).toBeVisible();
    expect(state.calls.some(c=>c.path==='register')).toBe(false);
    expect(state.calls.find(c=>c.path==='account/activate').data).toEqual({email:'paciente@example.test',code:'12345678'});
    await page.locator('#password').fill('ClaveDePrueba2026');
    await page.getByRole('button',{name:'Mostrar contraseña',exact:true}).first().click();
    await expect(page.locator('#password')).toHaveAttribute('type','text');
    await page.getByRole('button',{name:'Ocultar contraseña',exact:true}).click();
    await expect(page.locator('#password')).toHaveAttribute('type','password');
});

for(const role of ['Superadmin','Administrador','Recepcionista']) {
    test(`${role}: registra paciente y cuenta pendiente de activación en un formulario`,async({page})=>{
        const state=await mockClinic(page,role);await page.goto('/#CU06');
        await page.getByRole('button',{name:'+ Registrar paciente y cuenta',exact:true}).click();
        await page.getByLabel('Nombres',{exact:true}).fill('Ana');
        await page.getByLabel('Apellidos',{exact:true}).fill('Paciente');
        await page.getByLabel('Teléfono',{exact:true}).fill('70000000');
        await page.getByLabel('Fecha de nacimiento',{exact:true}).fill('2000-01-01');
        await page.getByLabel('Correo electrónico para activar la cuenta',{exact:true}).fill('ana@example.test');
        await page.getByLabel('Nombre de usuario',{exact:true}).fill('ana.paciente');
        await page.getByLabel('Contraseña inicial',{exact:true}).fill('ClaveDePrueba2026');
        await page.getByLabel('Repetir contraseña inicial',{exact:true}).fill('ClaveDePrueba2026');
        await page.locator('[name="identity_verified"]').check();
        await page.getByRole('button',{name:'Guardar',exact:true}).click();
        await expect(page.getByRole('dialog')).not.toBeVisible();
        await expect(page.locator('#records')).toContainText('ana.paciente · Pendiente de código');
        expect(state.calls.find(c=>c.path==='patients/with-account').data.identity_verified).toBe('1');
    });
}

test('el login bloqueado abre la activación y solo permite acceder después del código',async({page})=>{
    await mockClinic(page,'Administrador',{initiallyGuest:true,requireActivation:true});await page.goto('/');
    await submitLogin(page);
    await expect(page.locator('#activation-form')).toBeVisible();
    await expect(page.locator('.nav-btn')).toHaveCount(0);
    await page.getByLabel('Código de activación',{exact:true}).fill('12345678');
    await page.getByRole('button',{name:'Confirmar código y activar'}).click();
    await submitLogin(page);
    await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU03');
});

test('Paciente puede cambiar su contraseña y consultar sus datos tras activar su cuenta',async({page})=>{
    const state=await mockClinic(page,'Paciente');await page.goto('/#CU02');
    await page.getByLabel('Contraseña actual',{exact:true}).fill('AnteriorSegura2026');
    await page.getByLabel('Nueva contraseña',{exact:true}).fill('ClaveNueva2026');
    await page.getByLabel('Repetir nueva contraseña',{exact:true}).fill('ClaveNueva2026');
    await page.getByRole('button',{name:'Cambiar contraseña',exact:true}).click();
    await expect(page.locator('#my-profile-form')).toBeVisible();
    expect(state.calls.find(c=>c.path==='account/password').data).toEqual({current_password:'AnteriorSegura2026',password:'ClaveNueva2026',password_confirmation:'ClaveNueva2026'});
});

test('Recepción recibe aviso de solicitud nueva y puede abrir su bandeja', async ({page}) => {
    await page.clock.install();const state=await mockClinic(page,'Recepcionista');await page.goto('/');
    await expect(page.locator('#reception-inbox')).toBeHidden();state.pending=1;
    await page.clock.fastForward(45000);await expect(page.locator('#reception-inbox')).toContainText('1 solicitud');
    await page.locator('#reception-inbox').click();await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU10');
});

test('Paciente llega a reservas también desde la consulta de disponibilidad', async ({page}) => {
    await mockClinic(page,'Paciente');await page.goto('/#CU08');
    await page.locator('#go-online-booking').click();await expect(page.locator('#clinical-booking')).toBeVisible();
});
