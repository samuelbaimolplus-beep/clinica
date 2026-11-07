import {test, expect} from '@playwright/test';

// Navegador real + interfaz compilada; API simulada sin tocar pacientes ni hacer pagos.
// La autorización y persistencia reales se comprueban por separado en Feature con PostgreSQL.
const access = {
    Superadmin: Array.from({length:26},(_,i)=>`CU${String(i+1).padStart(2,'0')}`),
    Administrador: ['CU01','CU02','CU03','CU04','CU05','CU07','CU22','CU15','CU16','CU26'],
    Recepcionista: ['CU01','CU02','CU06','CU24','CU25','CU08','CU17','CU09','CU10','CU11','CU12','CU13','CU15','CU20','CU21'],
    Médico: ['CU01','CU02','CU12','CU14'],
    Paciente: ['CU01','CU02','CU08','CU10','CU15','CU18','CU20'],
};
const specialty = {id:1,name:'Medicina general',status:'Activo',fee_minor:10000};
const doctor = {id:1,first_name:'Ana',last_name:'Prueba',registration:'BROWSER-1',status:'Activo',specialties:[specialty]};
const patient = {id:1,first_name:'Paciente',last_name:'Prueba',status:'Activo'};
const slot = {doctor_id:1,doctor_name:'Ana Prueba',start_time:'10:30',end_time:'11:00',modality:'online'};

async function mockClinic(page, role, options={}) {
    const user = role ? {id:1,name:`Cuenta ${role}`,username:'cuenta_prueba',email:'cuenta@example.test',role,status:'Activo'} : null;
    const state = {calls:[],pending:0,confirmed:false,authenticated:!options.initiallyGuest};
    const booking = {id:1,date:'2030-11-11',start_time:'10:30',end_time:'11:00',status:'processing',attempt:1,amount_minor:10000,expires_at:new Date(Date.now()+300000).toISOString()};
    const payment = {id:1,status:'pending',amount_minor:10000};
    const appointment = {id:1,patient,doctor,specialty,date:booking.date,start_time:booking.start_time,end_time:booking.end_time,status:'Programada',payment:{...payment,method:'wizpay',reference:'WP-PRUEBA-001',status:'confirmed'},...options.appointmentOverrides};
    await page.route('**/api/**', async route => {
        const url = new URL(route.request().url()), path=url.pathname.slice(5);
        state.calls.push({path,method:route.request().method(),data:route.request().postDataJSON()});
        let data;
        if(path==='session')data={user:state.authenticated?user:null,access:options.staleAccessBeforeLogin&&!state.authenticated?{}:access,csrf:'browser-test-csrf',terms_version:'2026-11-02'};
        else if(path==='login') {
            state.authenticated=!options.sessionNotPersisted;
            if(options.alreadyActive) {await route.fulfill({status:409,json:{message:'Ya tiene una sesión activa.'}});return;}
            data={user,csrf:'browser-test-csrf'};
        }
        else if(path==='references') {
            if(options.referencesFailOnce && !state.failedReferences) {state.failedReferences=true;await route.fulfill({status:503,json:{message:'No se pudo cargar el panel.'}});return;}
            data={doctors:[doctor],specialties:[specialty],patients:[patient]};
        }
        else if(path==='medical-availability/options')data={online_payment_available:options.paymentAvailable!==false,doctors:[doctor],specialties:[specialty]};
        else if(path==='medical-availability')data={blocks:[{...slot,...options.slotOverrides}],modality:url.searchParams.get('modality')||'online'};
        else if(path==='appointments'&&route.request().method()==='POST')data=appointment;
        else if(path==='online-bookings'&&route.request().method()==='POST')data={booking,payment,qr_image:'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg=='};
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
        else if(path==='entities/doctors')data=[doctor];
        else if(path==='entities/specialties')data=[specialty];
        else if(path==='entities/patients')data=[patient];
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
        await expect(page.locator('#main .empty').filter({hasText:'Cargando'})).toHaveCount(0);
        await expect(page.locator('#retry')).toHaveCount(0);
        await expect(page.locator('#main')).not.toContainText('undefined');
    }
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
    await page.getByRole('button',{name:'Retener cupo y generar QR de pruebas'}).click();
    await expect(page.getByRole('dialog')).toContainText('Pago online de pruebas');
    await expect(page.getByRole('dialog')).not.toContainText('Ficha CIT-');
    const request=state.calls.find(c=>c.path==='online-bookings'&&c.method==='POST');
    expect(request.data.doctor_id).toBe(1);expect(request.data.patient_id).toBeUndefined();expect(request.data.idempotency_key).toMatch(/^[a-f0-9-]{36}$/);
    state.confirmed=true;await page.getByRole('button',{name:'Consultar estado',exact:true}).click();
    await expect(page.getByRole('dialog')).toContainText('Ficha CIT-0001');
    await expect(page.getByRole('dialog')).toContainText('WP-PRUEBA-001');
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

test('sin pasarela hay horarios y aviso pero no un botón para iniciar un cobro', async ({page}) => {
    const state=await mockClinic(page,'Paciente',{paymentAvailable:false});await page.goto('/');
    await expect(page.locator('#main')).toContainText('El pago online está temporalmente indisponible');
    await expect(page.getByRole('button',{name:'Retener cupo y generar QR de pruebas'})).toHaveCount(0);
    expect(state.calls.some(c=>c.path==='online-bookings'&&c.method==='POST')).toBe(false);
});

test('Paciente puede descartar la solicitud cuando el servidor confirma que no existe pago en proceso', async ({page}) => {
    await mockClinic(page,'Paciente');await page.goto('/');
    await page.locator('#specialty_id').selectOption('1');await page.locator('#date').fill('2030-11-11');
    await page.getByRole('button',{name:'Seleccionar',exact:true}).click();
    await page.getByRole('button',{name:'Retener cupo y generar QR de pruebas'}).click();
    await page.getByRole('button',{name:'Descartar solicitud sin pago'}).click();
    await expect(page.getByRole('dialog')).toContainText('Solicitud descartada');
    await expect(page.getByRole('dialog')).not.toContainText('Ficha CIT-');
});

test('Superadmin supervisa reservas sin emitirlas en nombre de un paciente', async ({page}) => {
    await mockClinic(page,'Superadmin');await page.goto('/#CU18');
    await expect(page.locator('#main')).toContainText('Operaciones online de todos los pacientes');
    await expect(page.getByRole('button',{name:'Retener cupo y generar QR de pruebas'})).toHaveCount(0);
});

test('registro exige aceptar términos y permite mostrar y ocultar contraseña', async ({page}) => {
    await mockClinic(page,null);await page.goto('/');
    await page.getByRole('button',{name:'Crear cuenta de paciente'}).click();
    const create=page.getByRole('button',{name:'Crear mi cuenta'});await expect(create).toBeDisabled();
    await page.locator('[name="terms_accepted"]').check();await expect(create).toBeEnabled();
    await page.locator('#password').fill('ClaveDePrueba2026');
    await page.getByRole('button',{name:'Mostrar contraseña',exact:true}).first().click();
    await expect(page.locator('#password')).toHaveAttribute('type','text');
    await page.getByRole('button',{name:'Ocultar contraseña',exact:true}).click();
    await expect(page.locator('#password')).toHaveAttribute('type','password');
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
