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
const patient = {id:1,first_name:'Paciente',last_name:'Prueba',birth_date:'2000-01-01',phone:'70000000',status:'Activo'};
const paymentUrl = 'https://www.sandbox.paypal.com/checkoutnow?token=PP-PRUEBA-001';
const slot = {doctor_id:1,doctor_name:'Ana Prueba',start_time:'10:30',end_time:'11:00',modality:'online'};

async function mockClinic(page, role, options={}) {
    const user = role ? {id:1,name:`Cuenta ${role}`,username:'cuenta_prueba',email:'cuenta@example.test',role,status:'Activo'} : null;
    const state = {calls:[],pending:0,confirmed:false,authenticated:!options.initiallyGuest,activated:!options.requireActivation,patients:[{...patient}],schedules:options.schedules||[]};
    state.appointments=options.appointments||[];state.refunds=options.refunds||[];state.requests=[];state.unavailabilities=options.unavailabilities||[];
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
        else if(path==='workspace')data={today:{total:0,expected:0,waiting:0,attended:0},next:[],requests:0,refunds:0,setup:['Administrador','Superadmin'].includes(role)?{specialties:1,doctors:1,scheduled_doctors:1}:null};
        else if(path==='logout'){state.authenticated=false;data={message:'Sesión cerrada.'};}
        else if(path==='references') {
            if(options.referencesFailOnce && !state.failedReferences) {state.failedReferences=true;await route.fulfill({status:503,json:{message:'No se pudo cargar el panel.'}});return;}
            data={doctors:[doctor],specialties:[specialty],patients:state.patients};
        }
        else if(path==='medical-availability/options')data={online_payment_available:options.paymentAvailable!==false,bob_per_usd:'10',payment_provider:'paypal',doctors:[doctor],specialties:[specialty]};
        else if(path==='medical-availability')data={blocks:[{...slot,...options.slotOverrides}],modality:url.searchParams.get('modality')||'online'};
        else if(path==='appointments'&&route.request().method()==='POST'){data=appointment;state.appointments=[appointment];}
        else if(/^appointments\/\d+\/(attendance|attend|reschedule-request)$/.test(path)) {
            const [,id,action]=path.split('/'),a=state.appointments.find(a=>a.id===Number(id));
            if(action==='reschedule-request'){data={id:1,appointment_id:Number(id),status:'pending',reason:route.request().postDataJSON().reason};state.requests.push(data);}
            else {Object.assign(a,action==='attendance'?{status:'Confirmada',attendance_at:new Date().toISOString(),care_ready:true}:{status:'Atendida'});data=a;}
        }
        else if(/^refunds\/\d+\/decision$/.test(path)){data=state.refunds.find(r=>r.id===Number(path.split('/')[1]));data.status=route.request().postDataJSON().decision==='approve'?'approved':'rejected';}
        else if(/^refunds\/\d+\/complete$/.test(path)){data=state.refunds.find(r=>r.id===Number(path.split('/')[1]));Object.assign(data,route.request().postDataJSON(),{status:'completed'});}
        else if(path==='online-bookings'&&route.request().method()==='POST')data={booking,payment,approval_url:payment.approval_url};
        else if(path==='online-bookings/1/status')data={booking,payment,appointment:state.confirmed?appointment:null};
        else if(path==='online-bookings/1/cancel')data={booking:{...booking,status:'cancelled'},payment:{...payment,status:'failed'}};
        else if(path==='online-bookings')data=[];
        else if(path==='schedules'&&route.request().method()==='GET')data={schedules:state.schedules,unavailabilities:state.unavailabilities,quota:{presential_percent:50,online_percent:50}};
        else if(path==='schedules'||/^schedules\/\d+$/.test(path)){const input=route.request().postDataJSON();const id=path.includes('/')?Number(path.split('/')[1]):state.schedules.length+1;state.schedules=state.schedules.filter(s=>s.id!==id);state.schedules.push({...input,id});data={...input,id,affected_appointments:[]};}
        else if(path==='availability')data=[{start:'12:00',end:'12:30',status:'Disponible'}];
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
        else if(path==='entities/patients'&&route.request().method()==='POST'){data={...patient,...route.request().postDataJSON(),id:2};state.patients.push(data);}
        else if(path==='entities/patients')data=state.patients;
        else if(path==='appointments'&&route.request().method()==='GET')data=state.appointments;
        else if(path==='refunds')data=state.refunds;
        else if(path==='reschedule-requests')data=state.requests;
        else if(['appointments','reschedule-requests','refunds','schedule-impacts','agenda-adjustments','supervision/patient-accounts'].includes(path))data=[];
        else {
            await route.fulfill({status:500,json:{message:`Ruta no simulada: ${path}`}});
            return;
        }
        await route.fulfill({json:data});
    });
    return state;
}

test.describe('Auditoría con navegador en otra zona horaria', () => {
test.use({timezoneId:'Asia/Tokyo'});
test('bitácora: Administrador ve valores anteriores y nuevos, responsable histórico y datos relacionados', async ({page}) => {
    await mockClinic(page, 'Administrador');
    const event = {id: 901, occurred_at: '2026-11-13T02:30:00.000000Z', user_id: 1, module: 'specialties', action: 'updated',
        result: 'success', record_type: 'Specialty', record_id: 3, ip_address: '203.0.113.25', details: {
            actor: {id: 1, name: 'Administradora original', username: 'demo.admin', role: 'Administrador'},
            record: {id: 3, name: 'Pediatría', fee_minor: 15000, status: 'Inactivo'},
            fields: ['fee_minor', 'status'], changes: {fee_minor: {before: 10000, after: 15000}, status: {before: 'Activo', after: 'Inactivo'}},
            request: {method: 'PUT', route: 'api/entities/{module}/{id}'},
        }};
    await page.route('**/api/audit**', async route => {
        const path = new URL(route.request().url()).pathname;
        if (path.endsWith('/filters')) await route.fulfill({json: {users: [{id: 1, name: 'Nombre actual', role: 'Administrador'}], modules: ['specialties'], actions: ['updated'], results: ['success']}});
        else if (path.endsWith('/901')) await route.fulfill({json: event});
        else await route.fulfill({json: {data: [event], total: 1, current_page: 1, last_page: 1}});
    });
    await page.goto('/#auditoria');
    await expect(page.locator('#main')).toContainText('Administradora original');
    await expect(page.locator('#main')).toContainText('Pediatría');
    await expect(page.locator('#main')).toContainText('12/11/2026 22:30:00');
    await expect(page.locator('#main')).toContainText('Santa Cruz, Bolivia');
    await page.locator('[data-audit="901"]').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('12/11/2026 22:30:00');
    await expect(dialog).toContainText('Cambios realizados');
    const fee = dialog.getByRole('row').filter({hasText: 'Tarifa'});
    await expect(fee).toContainText(/100[,.]00 BOB/);
    await expect(fee).toContainText(/150[,.]00 BOB/);
    await expect(dialog).toContainText('203.0.113.25');
    await expect(dialog).toContainText('Administradora original');
    await expect(dialog).toContainText('Registro en el momento de la acción');
    await page.screenshot({path: 'storage/app/private/bitacora-detalle-local.png', fullPage: true});
});
});

test('bitácora: explica el límite de los eventos antiguos y escapa los valores registrados', async ({page}) => {
    await mockClinic(page, 'Administrador');
    const event = {id: 902, occurred_at: '2026-11-01', module: 'users', action: 'updated', result: 'success',
        record_type: 'User', record_id: 2, details: {fields: ['status'], reason: '<img src=x onerror="window.auditInjected=true">'}};
    await page.route('**/api/audit**', async route => {
        const path = new URL(route.request().url()).pathname;
        if (path.endsWith('/filters')) await route.fulfill({json: {users: [], modules: ['users'], actions: ['updated'], results: ['success']}});
        else if (path.endsWith('/902')) await route.fulfill({json: event});
        else await route.fulfill({json: {data: [event], total: 1, current_page: 1, last_page: 1}});
    });
    await page.goto('/#auditoria');
    await page.locator('[data-audit="902"]').click();
    await expect(page.getByRole('dialog')).toContainText('Este evento no conserva valores anteriores y nuevos');
    await expect(page.getByRole('dialog')).toContainText('<img src=x');
    expect(await page.evaluate(() => Boolean(window.auditInjected))).toBe(false);
});

for (const role of ['Recepcionista', 'Superadmin']) {
    test(`bloqueos provisionales: ${role} propone, selecciona, visualiza y libera`, async ({page}) => {
        await mockClinic(page, role);
        const records = [], calls = [];
        let date = '';
        await page.route('**/api/agenda-adjustments**', async route => {
            const url = new URL(route.request().url());
            const path = url.pathname.slice(5), method = route.request().method();
            const input = route.request().postDataJSON();
            calls.push({path, method, input});
            if (method === 'GET') {
                date = url.searchParams.get('date');
                await route.fulfill({json: records});
            } else if (path === 'agenda-adjustments/proposal') {
                await route.fulfill({json: {requested: 2, unfulfilled: 0, blocks: [
                    {doctor_id: 1, date, start_time: '22:00', end_time: '22:30'},
                    {doctor_id: 1, date, start_time: '22:30', end_time: '23:00'},
                ]}});
            } else if (path === 'agenda-adjustments/release') {
                records.forEach(record => { if (input.ids.map(Number).includes(record.id)) record.status = 'released'; });
                await route.fulfill({json: records});
            } else if (path === 'agenda-adjustments') {
                input.blocks.forEach((block, index) => records.push({...block, id: index + 1,
                    specialty_id: Number(input.specialty_id), kind: input.kind, status: 'active', created_by: 1}));
                await route.fulfill({status: 201, json: records});
            } else await route.fulfill({status: 500, json: {message: 'Ruta inesperada de bloqueos.'}});
        });
        await page.goto('/#bloqueos');
        await expect(page.getByRole('heading', {name: 'Bloqueos provisionales', exact: true})).toBeVisible();
        await page.locator('[data-demand-specialty="1"]').click();
        await expect(page.locator('#main')).not.toHaveAttribute('aria-busy', 'true');
        await page.getByRole('button', {name: 'Añadir una persona', exact: true}).click();
        await expect(page.locator('#demand-count')).toHaveText('2');
        await page.locator('#demand-proposal-form button[type=submit]').click();
        await expect(page.locator('#confirm-demand-form [name=blocks]')).toHaveCount(2);
        expect(calls.filter(call => call.path === 'agenda-adjustments' && call.method === 'POST')).toHaveLength(0);
        await page.locator('#confirm-demand-form [name=blocks]').nth(1).uncheck();
        await page.locator('#confirm-demand-form button[type=submit]').click();
        await expect(page.locator('#release-blocks-form')).toContainText('Activo');
        await expect(page.locator('#release-blocks-form')).toContainText('Medicina general');
        await expect(page.locator('#release-blocks-form')).toContainText('Ana Prueba');
        await expect(page.locator('#release-blocks-form')).toContainText('22:00–22:30');
        const created = calls.find(call => call.path === 'agenda-adjustments' && call.method === 'POST');
        expect(created.input.blocks).toHaveLength(1);
        expect(created.input.quantity).toBe(2);
        await page.reload();
        await expect(page.locator('#release-blocks-form')).toContainText('Activo');
        await page.screenshot({path: `storage/app/private/bloqueos-${role}.png`, fullPage: true});
        await page.locator('#release-blocks-form [name=ids]').check();
        await page.locator('#release-blocks-form button[type=submit]').click();
        await expect(page.getByRole('dialog')).toBeVisible();
        expect(calls.filter(call => call.path === 'agenda-adjustments/release')).toHaveLength(0);
        await page.locator('#confirm-action').click();
        await expect(page.getByRole('dialog')).not.toBeVisible();
        await expect(page.locator('#release-blocks-form')).toContainText('Liberado');
        await expect(page.locator('#release-blocks-form [name=ids]')).toHaveCount(0);
    });
}

for (const role of ['Administrador', 'Superadmin']) {
    test(`ausencias: ${role} revisa las citas, registra y retira conservando el historial`, async ({page}) => {
        const state = await mockClinic(page, role, {schedules: [{id:1,doctor_id:1,weekday:2,start_time:'08:00',end_time:'12:00',duration:30}]});
        const affected = [{id:8,patient,doctor,specialty,date:'2030-11-12',start_time:'08:00',end_time:'08:30',status:'Programada'}];
        const calls = [];
        await page.route('**/api/unavailabilities**', async route => {
            const path = new URL(route.request().url()).pathname.slice(5);
            const input = route.request().postDataJSON();
            calls.push({path, input});
            if (path.endsWith('/preview')) await route.fulfill({json: {unavailability:null, affected}});
            else if (path.endsWith('/impacts')) await route.fulfill({json: {unavailability:state.unavailabilities[0], affected}});
            else if (path.endsWith('/cancel')) {
                Object.assign(state.unavailabilities[0], {cancelled_at:'2030-11-12T07:30:00-04:00',cancelled_by:1,cancellation_reason:input.reason});
                await route.fulfill({json: {unavailability:state.unavailabilities[0], affected:[]}});
            } else {
                state.unavailabilities.push({...input,id:5,cancelled_at:null});
                await route.fulfill({status:201,json:{unavailability:state.unavailabilities[0],affected}});
            }
        });
        await page.goto('/#horarios');
        await page.locator('[data-tab=block]').click();
        await page.locator('#new-unavailability').click();
        await page.getByLabel('Desde', {exact:true}).fill('2030-11-12T08:00');
        await page.getByLabel('Hasta', {exact:true}).fill('2030-11-12T10:00');
        await page.locator('#preview-block').click();
        await expect(page.locator('#affected-preview')).toContainText('1 citas pendientes afectadas');
        await expect(page.locator('#affected-preview')).toContainText('Paciente Prueba');
        await expect(page.locator('#affected-preview')).toContainText('08:00–08:30');
        await page.locator('#unavailable-form button[type=submit]').click();
        await expect(page.getByRole('dialog')).toContainText('Resultado de la ausencia');
        await expect(page.getByRole('dialog')).toContainText('Paciente Prueba');
        await page.getByRole('dialog').press('Escape');
        await page.locator('[data-absence-impacts="5"]').click();
        await expect(page.getByRole('dialog')).toContainText('Paciente Prueba');
        await page.getByRole('dialog').press('Escape');
        await page.locator('[data-cancel-absence="5"]').click();
        await page.getByLabel('Motivo del retiro').fill('El médico confirmó que sí atenderá');
        expect(calls.filter(call => call.path.endsWith('/cancel'))).toHaveLength(0);
        await page.getByRole('button', {name:'Confirmar retiro'}).click();
        await expect(page.getByRole('dialog')).not.toBeVisible();
        await expect(page.locator('#main')).toContainText('Retirada');
        await expect(page.locator('#main')).toContainText('El médico confirmó que sí atenderá');
        await expect(page.locator('[data-cancel-absence="5"]')).toHaveCount(0);
        await page.screenshot({path:`storage/app/private/ausencia-retirada-${role}.png`,fullPage:true});
        await page.reload();
        await page.locator('[data-tab=block]').click();
        await expect(page.locator('#main')).toContainText('Retirada');
    });
}

test('horarios: primero muestra la semana, evita cruces y modifica el turno seleccionado',async({page})=>{
    const state=await mockClinic(page,'Administrador',{schedules:[{id:1,doctor_id:1,weekday:1,start_time:'08:00:00',end_time:'12:00:00',duration:30}]});
    await page.goto('/#horarios');
    await expect(page.locator('.week-grid')).toBeVisible();
    await expect(page.locator('[data-schedule="1"]')).toContainText('08:00–12:00');
    await expect(page.locator('#schedule-form')).toHaveCount(0);
    await page.locator('[data-new-shift="1"]').click();
    await expect(page.locator('#schedule-form [name=start_time]')).toHaveValue('12:00');
    await page.locator('#schedule-form [name=start_time]').fill('11:00');
    await expect(page.locator('#schedule-conflicts')).toContainText('Se cruza con 08:00–12:00');
    await page.locator('#schedule-form button[type=submit]').click();
    expect(state.calls.filter(c=>c.path==='schedules'&&c.method==='POST')).toHaveLength(0);
    await page.getByRole('button',{name:'Cancelar',exact:true}).click();
    await page.locator('[data-schedule="1"]').click();
    await page.locator('#schedule-form [name=end_time]').fill('13:00');
    await page.locator('#schedule-form button[type=submit]').click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page.locator('[data-schedule="1"]')).toContainText('08:00–13:00');
    expect(state.calls.find(c=>c.path==='schedules/1').method).toBe('PUT');
    await page.screenshot({path:'storage/app/private/horarios-semana-local.png',fullPage:true});
});

test('el inicio ofrece tareas con permisos y abre horarios sin mostrar un formulario vacío',async({page})=>{
    await mockClinic(page,'Administrador');await page.goto('/');
    await expect(page.locator('h1')).toHaveText('Inicio y acciones');
    await page.locator('.setup-step[data-open-view="CU07"]').click();
    await expect(page.locator('.week-grid')).toBeVisible();
    await expect(page.locator('#schedule-form')).toHaveCount(0);
});

test('la reprogramación usa el médico y fecha del diálogo aunque los filtros estén vacíos',async({page})=>{
    const a={id:3,patient,doctor,specialty,patient_id:1,doctor_id:1,specialty_id:1,date:'2030-11-11',start_time:'10:30',end_time:'11:00',status:'Programada',modality:'presential',payment:{status:'confirmed'}};
    await mockClinic(page,'Recepcionista',{appointments:[a]});await page.goto('/#reprogramaciones');
    await page.locator('[data-change="3"]').click();
    await expect(page.locator('#clinical-reschedule [name=start_time] option[value="12:00"]')).toHaveCount(1);
    const url=await page.evaluate(()=>performance.getEntriesByType('resource').filter(r=>r.name.includes('/api/availability?')).at(-1)?.name);
    expect(new URL(url).searchParams.get('doctor_id')).toBe('1');
    expect(new URL(url).searchParams.get('date')).toBe('2030-11-11');
});

for(const [role,home] of [['Superadmin','CU03'],['Administrador','CU03'],['Recepcionista','CU06'],['Médico','CU12'],['Paciente','CU18']]) {
    test(`${role}: inicio y menú restringidos`, async ({page}) => {
        const errors=[];page.on('pageerror',e=>errors.push(e.message));
        await mockClinic(page,role);await page.goto('/');
        await expect(page.locator('[data-home]')).toHaveAttribute('aria-current','page');
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
        await expect(page.locator('#menu-toggle')).toBeFocused();
        await expect(page.locator('#nav-search')).not.toBeFocused();
        await page.getByRole('button',{name:'Inicio y acciones',exact:true}).click();
        await expect(page.getByRole('heading',{name:'Inicio y acciones',exact:true})).toBeVisible();
        await page.locator('#menu-toggle').click();
        await expect(page.locator('#nav-search')).not.toBeFocused();
        await page.getByRole('searchbox',{name:'Buscar en el menú'}).click();
        await expect(page.locator('#nav-search')).toBeFocused();
        await page.getByRole('searchbox',{name:'Buscar en el menú'}).fill('medicos');
        await expect(page.locator('.nav-btn:visible')).toHaveCount(3);
        await page.getByRole('button',{name:'Médicos',exact:true}).click();
        await expect(page.getByRole('heading',{name:'Médicos',exact:true})).toBeVisible();
        await expect(page.locator('#menu-backdrop')).toBeHidden();
        await expect(page).toHaveURL(/#medicos$/);
        await page.locator('#menu-toggle').click();await page.keyboard.press('Escape');
        await expect(page.locator('#menu-toggle')).toBeFocused();
        await expect(page.locator('#menu-toggle')).toHaveAttribute('aria-expanded','false');
        await page.goto('/#pacientes');
        await page.getByRole('button',{name:'+ Registrar paciente',exact:true}).click();
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
        await mockClinic(page,role);await page.goto('/#usuarios');
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
    await expect(page.locator('[data-home]')).toHaveAttribute('aria-current','page');
    expect(state.calls.find(c=>c.path==='login').data.email).toBe('ana.paciente');
});

async function submitLogin(page) {
    await page.locator('#email').fill('superadmin');
    await page.locator('#password').fill('ClaveDePrueba2026');
    await page.getByRole('button',{name:'Iniciar sesión',exact:true}).click();
}

async function selectBookingSlot(page,{staff=false,consult=false}={}) {
    if(staff){await page.locator('[data-pick="patient"]').first().click();await page.locator('#flow-next').click();}
    await page.locator('[data-pick="specialty"]').first().click();
    await page.locator('#flow-next').click();
    await page.locator('#booking-date').fill('2030-11-11');
    await page.locator('[data-flow-slot]').first().click();
    if(!consult)await expect(page.locator('.confirmation-ticket')).toBeVisible();
}

test('el login actualiza los permisos de una pestaña abierta antes de agregar el rol', async ({page}) => {
    await mockClinic(page,'Superadmin',{initiallyGuest:true,staleAccessBeforeLogin:true});await page.goto('/');
    await submitLogin(page);
    await expect(page.locator('[data-home]')).toHaveAttribute('aria-current','page');
    await expect(page.locator('#login-form')).toHaveCount(0);
});

test('el login recupera la sesión activa sin atascarse en el formulario', async ({page}) => {
    await mockClinic(page,'Superadmin',{initiallyGuest:true,alreadyActive:true});await page.goto('/');
    await submitLogin(page);
    await expect(page.locator('[data-home]')).toHaveAttribute('aria-current','page');
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
    await expect(page.locator('[data-home]')).toHaveAttribute('aria-current','page');
    expect(state.calls.filter(c=>c.path==='login')).toHaveLength(1);
});

test('Superadmin puede cambiar la contraseña de un paciente sin consultar la contraseña anterior', async ({page}) => {
    const state=await mockClinic(page,'Superadmin');await page.goto('/#usuarios');
    await page.getByRole('button',{name:'Consultar todas las cuentas'}).click();
    await page.getByRole('row').filter({hasText:'ana.paciente'}).getByRole('button',{name:'Cambiar contraseña'}).click();
    await expect(page.getByRole('dialog')).toContainText('La contraseña actual no se puede consultar');
    await page.getByLabel('Nueva contraseña',{exact:true}).fill('123456');
    await page.getByLabel('Repetir contraseña nueva',{exact:true}).fill('123456');
    await page.getByRole('button',{name:'Guardar nueva contraseña'}).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    expect(state.calls.find(c=>c.path==='supervision/accounts/2/password').data.password_confirmation).toBe('123456');
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
    const state=await mockClinic(page,'Paciente');await page.goto('/#reservar-cita');
    await selectBookingSlot(page);
    expect(state.calls.filter(c=>c.path==='online-bookings'&&c.method==='POST')).toHaveLength(0);
    await page.getByRole('button',{name:'Continuar a PayPal Sandbox',exact:true}).click();
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
        await selectBookingSlot(page,{staff:true});
        await page.locator('[name="payment_confirmed"]').check();
        await page.getByRole('button',{name:'Confirmar cita y emitir ficha',exact:true}).click();
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
    const state=await mockClinic(page,'Paciente',{paymentAvailable:false});await page.goto('/#reservar-cita');
    await expect(page.locator('#main')).toContainText('El pago online no está habilitado');
    await selectBookingSlot(page);
    await expect(page.getByRole('button',{name:'Continuar a PayPal Sandbox',exact:true})).toBeDisabled();
    expect(state.calls.some(c=>c.path==='online-bookings'&&c.method==='POST')).toBe(false);
});

test('Paciente puede descartar la solicitud cuando el servidor confirma que no existe pago en proceso', async ({page}) => {
    await mockClinic(page,'Paciente');await page.goto('/#reservar-cita');
    await selectBookingSlot(page);
    await page.getByRole('button',{name:'Continuar a PayPal Sandbox',exact:true}).click();
    await page.getByRole('button',{name:'Descartar solicitud sin pago'}).click();
    await expect(page.getByRole('dialog')).toContainText('Solicitud descartada');
    await expect(page.getByRole('dialog')).not.toContainText('Ficha CIT-');
});

test('Superadmin supervisa reservas sin emitirlas en nombre de un paciente', async ({page}) => {
    await mockClinic(page,'Superadmin');await page.goto('/#CU18');
    await expect(page.locator('#main')).toContainText('Vista de supervisión');
    await selectBookingSlot(page);
    await expect(page.getByRole('button',{name:'Continuar a PayPal Sandbox',exact:true})).toBeDisabled();
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
        await page.getByRole('button',{name:'+ Registrar paciente',exact:true}).click();
        await page.getByLabel('Nombres',{exact:true}).fill('Ana');
        await page.getByLabel('Apellidos',{exact:true}).fill('Paciente');
        await page.getByLabel('Teléfono',{exact:true}).fill('70000000');
        await page.getByLabel('Fecha de nacimiento',{exact:true}).fill('2000-01-01');
        await page.locator('#create-account').check();
        await page.getByLabel('Correo electrónico para activar la cuenta',{exact:true}).fill('ana@example.test');
        await page.getByLabel('Nombre de usuario',{exact:true}).fill('ana.paciente');
        await page.getByLabel('Contraseña inicial',{exact:true}).fill('ClaveDePrueba2026');
        await page.getByLabel('Repetir contraseña inicial',{exact:true}).fill('ClaveDePrueba2026');
        await page.locator('[name="identity_verified"]').check();
        await page.locator('[name="terms_accepted"]').check();
        await page.getByRole('button',{name:'Guardar',exact:true}).click();
        await expect(page.getByRole('dialog')).not.toBeVisible();
        await expect(page.locator('#records')).toContainText('ana.paciente · Pendiente de código');
        expect(state.calls.find(c=>c.path==='patients/with-account').data.identity_verified).toBe('1');
    });
}

test('el login bloqueado abre la activación y solo permite acceder después del código',async({page})=>{
    const state=await mockClinic(page,'Administrador',{initiallyGuest:true,requireActivation:true});await page.goto('/');
    await submitLogin(page);
    await expect(page.locator('#activation-form')).toBeVisible();
    await expect(page.locator('.nav-btn')).toHaveCount(0);
    await page.getByLabel('Código de activación',{exact:true}).fill('12345678');
    await page.getByRole('button',{name:'Confirmar código y activar'}).click();
    await expect(page.locator('#login-form')).toBeVisible();
    await submitLogin(page);
    await expect(page.locator('[data-home]')).toHaveAttribute('aria-current','page');
    expect(state.calls.filter(c=>c.path==='login')).toHaveLength(2);
});

test('el historial del paciente se presenta como propio y explica sus cambios sin pedir elegir paciente',async({page})=>{
    const ownAppointment={
        id:25,patient,doctor,specialty,date:'2030-11-11',start_time:'10:30',end_time:'11:00',
        status:'Atendida',created_at:'2030-11-01T10:00:00Z',attendance_at:'2030-11-11T10:25:00Z',
        events:[{created_at:'2030-11-02T10:00:00Z',action:'Reprogramación',details:{
            before:{doctor_id:2,date:'2030-11-10',start_time:'09:00',end_time:'09:30'},
            after:{doctor_id:1,date:'2030-11-11',start_time:'10:30',end_time:'11:00'},
            cause:'patient',reason:'El paciente solicitó otro horario',
        }}],
    };
    const state=await mockClinic(page,'Paciente',{appointments:[ownAppointment]});
    await page.goto('/#mis-citas');
    await expect(page.locator('h1')).toHaveText('Mis citas e historial');
    await expect(page.locator('#main')).toContainText('Sus citas y su atención');
    await expect(page.locator('#board-search')).toBeVisible();
    await expect(page.locator('.appointment-card')).toContainText('Ana Prueba');
    await expect(page.locator('#main')).not.toContainText('Selecciona un paciente');
    await expect(page.locator('#patient_id')).toHaveCount(0);
    await page.getByRole('button',{name:'Ver detalle',exact:true}).click();
    await expect(page.getByRole('dialog')).toContainText('Cita reprogramada');
    await expect(page.getByRole('dialog')).toContainText('Programación anterior');
    await expect(page.getByRole('dialog')).toContainText('Nueva programación');
    await expect(page.getByRole('dialog')).toContainText('Origen: Solicitud del paciente');
    await expect(page.getByRole('dialog')).not.toContainText('doctor_id');
    await expect(page.getByRole('dialog')).not.toContainText('{"before"');
    await expect(page.getByRole('dialog')).not.toContainText('2030-11-01T10:00:00Z');
    expect(state.calls.find(c=>c.path==='appointments').method).toBe('GET');
});

test('el médico consulta los datos necesarios de su agenda sin información de pago',async({page})=>{
    const ownAppointment={
        id:26,patient:{...patient,identity:'PRIVATE-CI',phone:'70000000',email:'private@example.test',address:'Dirección privada'},
        doctor,specialty,date:'2030-11-11',start_time:'10:30',end_time:'11:00',status:'Atendida',
        created_at:'2030-11-01T10:00:00Z',events:[],
        payment:{amount_minor:10000,status:'confirmed',bank_reference:'BANK-PRIVATE'},
    };
    await mockClinic(page,'Médico',{appointments:[ownAppointment]});
    await page.goto('/#agenda');
    await page.getByRole('button',{name:'Ver detalle',exact:true}).click();
    const dialog=page.getByRole('dialog');
    await expect(dialog).toContainText('Paciente Prueba');
    await expect(dialog).not.toContainText('Pago');
    await expect(dialog).not.toContainText('PRIVATE-CI');
    await expect(dialog).not.toContainText('70000000');
    await expect(dialog).not.toContainText('BANK-PRIVATE');
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
    await selectBookingSlot(page,{consult:true});
    await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU18');
    await expect(page.locator('.confirmation-ticket')).toContainText('10:30');
});

test('Recepción registra un menor sin cuenta ni correo y continúa la cita con esa misma ficha',async({page})=>{
    const state=await mockClinic(page,'Recepcionista');await page.goto('/#CU09');
    await page.locator('#booking-register-patient').click();
    await expect(page.locator('#create-account')).not.toBeChecked();
    await page.getByLabel('Nombres',{exact:true}).fill('Menor');
    await page.getByLabel('Apellidos',{exact:true}).fill('Demostración');
    await page.getByLabel('Teléfono',{exact:true}).fill('70000022');
    await page.getByLabel('Fecha de nacimiento',{exact:true}).fill('2018-01-01');
    await page.getByRole('combobox',{name:'Sexo',exact:true}).selectOption('Femenino');
    await expect(page.locator('#create-account')).toBeDisabled();
    await page.getByRole('button',{name:'Guardar',exact:true}).click();
    await expect(page.locator('[data-pick="patient"][data-value="2"]')).toHaveAttribute('aria-pressed','true');
    await expect(page.locator('.flow-summary')).toContainText('Menor Demostración');
    expect(state.calls.filter(c=>c.path==='patients/with-account')).toHaveLength(0);
    const request=state.calls.find(c=>c.path==='entities/patients'&&c.method==='POST');
    expect(request.data.birth_date).toBe('2018-01-01');expect(request.data.email).toBe('');
    expect(request.data.password).toBeUndefined();expect(request.data.terms_accepted).toBeUndefined();
    await page.locator('#flow-next').click();await expect(page.locator('[data-pick="specialty"]')).toBeVisible();
});

test('la cita presencial exige selección y recepción del dinero antes de emitir la ficha',async({page})=>{
    const state=await mockClinic(page,'Recepcionista');await page.goto('/#CU09');
    await page.locator('#flow-next').click();await expect(page.locator('#flow-error')).toContainText('Seleccione una ficha');
    await selectBookingSlot(page,{staff:true});
    await page.getByRole('button',{name:'Confirmar cita y emitir ficha',exact:true}).click();
    expect(state.calls.filter(c=>c.path==='appointments'&&c.method==='POST')).toHaveLength(0);
    await page.locator('[name=payment_confirmed]').check();
    await page.screenshot({path:'storage/app/private/recorrido-cita-confirmacion.png',fullPage:true});
    await page.getByRole('button',{name:'Confirmar cita y emitir ficha',exact:true}).click();
    await expect(page.getByRole('dialog')).toContainText('No necesita crearle una cuenta');
    expect(state.calls.find(c=>c.path==='appointments'&&c.method==='POST').data.payment_confirmed).toBe(true);
});

const today=()=>new Date().toLocaleDateString('en-CA',{timeZone:'America/La_Paz'});
const careAppointment=overrides=>({id:60,patient,doctor,specialty,patient_id:1,doctor_id:1,specialty_id:1,date:today(),start_time:'10:30',end_time:'11:00',status:'Programada',payment:{status:'confirmed'},...overrides});

test('la agenda de Recepción muestra la llegada como acción y después el siguiente paso',async({page})=>{
    const state=await mockClinic(page,'Recepcionista',{appointments:[careAppointment({})]});await page.goto('/#agenda');
    await page.getByRole('button',{name:'Registrar asistencia',exact:true}).click();
    await expect(page.getByRole('dialog')).toContainText('Paciente Prueba');
    await page.getByRole('button',{name:'Guardar',exact:true}).click();
    await expect(page.locator('.stage-badge')).toContainText('En espera de atención');
    await expect(page.locator('.operation-success')).toContainText('lista de espera para el médico');
    await expect(page.getByRole('button',{name:'Finalizar consulta',exact:true})).toHaveCount(0);
    expect(state.calls.some(c=>c.path==='appointments/60/attendance')).toBe(true);
    await page.screenshot({path:'storage/app/private/recorrido-agenda-llegada.png',fullPage:true});
});

test('el médico puede finalizar al paciente presente y no al que todavía no llegó',async({page})=>{
    const a=careAppointment({status:'Confirmada',attendance_at:new Date().toISOString(),care_ready:true});
    const state=await mockClinic(page,'Médico',{appointments:[a,{...careAppointment({}),id:61}]});await page.goto('/#CU14');
    await expect(page.locator('[data-record="61"] [data-care="attend"]')).toHaveCount(0);
    await page.getByRole('button',{name:'Finalizar consulta',exact:true}).click();
    await page.getByRole('button',{name:'Guardar',exact:true}).click();
    await expect(page.locator('[data-record="60"]')).toContainText('Consulta finalizada');
    expect(state.calls.find(c=>c.path==='appointments/60/attend').data.consultation_finished).toBe('1');
});

test('la solicitud del paciente conserva la cita y el identificador al enviar el diálogo',async({page})=>{
    const state=await mockClinic(page,'Paciente',{appointments:[careAppointment({})]});await page.goto('/#CU10');
    await page.getByRole('button',{name:'Solicitar cambio',exact:true}).click();
    await page.getByLabel('Motivo y disponibilidad propuesta').fill('Solicito el día siguiente por la mañana.');
    await page.getByRole('button',{name:'Enviar solicitud a Recepción',exact:true}).click();
    await expect(page.locator('.request-card')).toContainText('Pendiente de Recepción');
    expect(state.calls.find(c=>c.path==='appointments/60/reschedule-request').data.reason).toContain('día siguiente');
    expect(state.appointments[0].status).toBe('Programada');
});

test('aprobar el reembolso no registra la entrega hasta confirmar sus datos',async({page})=>{
    const state=await mockClinic(page,'Administrador',{refunds:[{id:5,appointment_id:60,status:'pending',amount_minor:10000,reason:'Cancelación de la clínica'}],appointments:[careAppointment({status:'Cancelada'})]});
    await page.goto('/#CU26');await page.getByRole('button',{name:'Aprobar',exact:true}).click();
    await page.getByRole('button',{name:'Aprobar y dejar devolución pendiente',exact:true}).click();
    await expect(page.locator('.refund-card')).toContainText('falta registrar la entrega');
    expect(state.calls.some(c=>c.path==='refunds/5/complete')).toBe(false);
    await page.getByRole('button',{name:'Registrar dinero devuelto',exact:true}).click();
    await page.getByLabel('Fecha y hora de entrega',{exact:true}).fill('2026-11-08T08:00');
    await page.getByLabel('Medio utilizado',{exact:true}).fill('Efectivo de prueba');
    await page.getByRole('button',{name:'Registrar devolución realizada',exact:true}).click();
    expect(state.calls.some(c=>c.path==='refunds/5/complete')).toBe(false);
    await page.locator('[name=delivery_confirmed]').check();
    await page.getByRole('button',{name:'Registrar devolución realizada',exact:true}).click();
    await expect(page.locator('.refund-card')).toContainText('Devolución registrada');
});

test('cerrar sesión limpia el diálogo y el borrador del paciente',async({page})=>{
    await mockClinic(page,'Recepcionista');await page.goto('/#CU09');
    await page.locator('[data-pick="patient"]').first().click();
    await page.locator('#logout-button').click();await page.locator('#confirm-action').click();
    await expect(page.locator('#login-form')).toBeVisible();
    await expect(page.locator('#modal-body')).toBeEmpty();
    await submitLogin(page);await page.goto('/#CU09');
    await expect(page.locator('[data-pick="patient"][aria-pressed="true"]')).toHaveCount(0);
});

async function expectReadableTheme(page) {
    const failures = await page.evaluate(() => {
        const rgb = color => color.match(/[\d.]+/g)?.map(Number);
        const luminance = color => rgb(color).slice(0,3).map(v => {
            const s = v / 255; return s <= .04045 ? s / 12.92 : ((s + .055) / 1.055) ** 2.4;
        }).reduce((sum,v,i) => sum + v * [.2126,.7152,.0722][i],0);
        return [...document.querySelectorAll('button,p,small,strong,span,label,td,th,dt,dd,a,h1,h2,h3,h4')]
            .filter(el => !el.children.length && el.textContent.trim() && el.getClientRects().length)
            .flatMap(el => {
                const style = getComputedStyle(el);
                let ancestor = el, background;
                do { background = getComputedStyle(ancestor).backgroundColor; ancestor = ancestor.parentElement; }
                while (ancestor && (rgb(background)?.[3] ?? 1) < 1);
                const a = luminance(style.color), b = luminance(background);
                const ratio = (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
                const large = parseFloat(style.fontSize) >= 24 || (parseFloat(style.fontSize) >= 18.66 && Number(style.fontWeight) >= 700);
                return ratio + .01 < (large ? 3 : 4.5) ? [{text:el.textContent.slice(0,70),ratio,color:style.color,background}] : [];
            });
    });
    expect(failures).toEqual([]);
}

test('tema: sigue al equipo hasta guardar una preferencia y la conserva entre páginas', async ({page, context}) => {
    await page.emulateMedia({colorScheme:'dark'});
    await mockClinic(page, null);
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('data-theme','dark');
    await expect(page.getByRole('button',{name:'Activar modo claro'})).toHaveAttribute('aria-pressed','true');
    await page.emulateMedia({colorScheme:'light'});
    await expect(page.locator('html')).toHaveAttribute('data-theme','light');
    await page.getByRole('button',{name:'Activar modo oscuro'}).click();
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme','dark');
    await page.goto('/terms');
    await expect(page.locator('html')).toHaveAttribute('data-theme','dark');
    const other = await context.newPage();
    await other.goto('/password/reset/theme-test?email=theme@example.test');
    await expect(other.locator('html')).toHaveAttribute('data-theme','dark');
    await other.getByRole('button',{name:'Activar modo claro'}).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme','light');
    await page.emulateMedia({colorScheme:'dark'});
    await expect(page.locator('html')).toHaveAttribute('data-theme','light');
    await other.close();
});

test('tema: funciona sin almacenamiento y se puede activar con teclado', async ({page}) => {
    await page.emulateMedia({colorScheme:'light'});
    await page.addInitScript(() => {
        Object.defineProperty(window,'localStorage',{get(){throw new DOMException('Blocked','SecurityError');}});
    });
    await mockClinic(page,null);
    await page.goto('/');
    const toggle = page.getByRole('button',{name:'Activar modo oscuro'});
    await toggle.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('html')).toHaveAttribute('data-theme','dark');
    await page.emulateMedia({colorScheme:'dark'});
    await page.emulateMedia({colorScheme:'light'});
    await expect(page.locator('html')).toHaveAttribute('data-theme','dark');
    await expect(page.locator('#login-form')).toBeVisible();
});

test('tema: acceso y páginas de correo mantienen el contraste y el tema guardado', async ({page}) => {
    await page.emulateMedia({colorScheme:'dark'});
    await mockClinic(page,null);
    for (const path of ['/', '/terms', '/password/reset/theme-test?email=theme@example.test']) {
        await page.goto(path);
        await expect(page.locator('[data-theme-toggle]')).toHaveAttribute('aria-pressed','true');
        await expectReadableTheme(page);
    }
    await page.goto('/');
    await page.screenshot({path:'storage/app/private/tema-oscuro-acceso.png',fullPage:true});
});

for (const width of [360,1440]) test(`tema: pantallas, diálogo e impresión a ${width}px`, async ({page}) => {
    await page.setViewportSize({width,height:1000});
    await page.emulateMedia({colorScheme:'dark'});
    await mockClinic(page,'Superadmin');
    await page.goto('/');
    await expect(page.locator('.welcome-panel')).toBeVisible();
    await expect(page.locator('[data-theme-toggle]')).toBeVisible();
    await expect(page.locator('html')).toHaveCSS('background-color','rgb(16, 28, 37)');
    await expectReadableTheme(page);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await page.screenshot({path:`storage/app/private/tema-oscuro-inicio-${width}.png`,fullPage:true});
    await page.goto('/#CU03');
    await expect(page.locator('table')).toBeVisible();
    await expect(page.locator('td').first()).toHaveCSS('color','rgb(215, 228, 236)');
    await expectReadableTheme(page);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await page.screenshot({path:`storage/app/private/tema-oscuro-usuarios-${width}.png`,fullPage:true});
    await page.locator('[data-edit="1"]').click();
    await expect(page.getByRole('dialog')).toHaveCSS('background-color','rgb(25, 42, 54)');
    await expect(page.getByRole('dialog').locator('input').first()).toHaveCSS('color','rgb(215, 228, 236)');
    await page.screenshot({path:`storage/app/private/tema-oscuro-dialogo-${width}.png`,fullPage:true});
    await page.locator('#close-modal').click();
    await page.emulateMedia({media:'print'});
    await expect(page.locator('[data-theme-toggle]')).toBeHidden();
    await expect(page.locator('html')).toHaveCSS('background-color','rgb(245, 247, 250)');
});

for(const width of [360,1440])test(`tema: recorrido completo de cita a ${width}px sin desbordamiento`,async({page})=>{
    await page.emulateMedia({colorScheme:'dark'});
    await page.setViewportSize({width,height:900});await mockClinic(page,'Recepcionista');await page.goto('/#CU09');
    for(const step of ['patient','specialty']){
        await expectReadableTheme(page);
        await page.locator(`[data-pick="${step}"]`).first().click();
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        await page.locator('#flow-next').click();
    }
    await expect(page.locator('[data-flow-slot]').first()).toBeVisible();
    await expectReadableTheme(page);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await page.locator('[data-flow-slot]').first().click();
    await expect(page.locator('.confirmation-ticket')).toBeVisible();
    await expectReadableTheme(page);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await page.screenshot({path:`storage/app/private/tema-oscuro-cita-${width}.png`,fullPage:true});
});
