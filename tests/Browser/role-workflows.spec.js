import {test, expect} from '@playwright/test';

// Navegador real + interfaz compilada; API simulada sin tocar pacientes ni hacer pagos.
// La autorización y persistencia reales se comprueban por separado en Feature con PostgreSQL.
const acceso = {
    Superadmin: Array.from({length:26},(_,i)=>`CU${String(i+1).padStart(2,'0')}`),
    Administrador: ['CU01','CU02','CU03','CU04','CU05','CU06','CU07','CU22','CU15','CU16','CU26'],
    Recepcionista: ['CU01','CU02','CU06','CU24','CU25','CU08','CU17','CU09','CU10','CU11','CU12','CU13','CU15','CU20','CU21'],
    Médico: ['CU01','CU02','CU12','CU14'],
    Paciente: ['CU01','CU02','CU08','CU10','CU15','CU18','CU20'],
};
const especialidad = {id:1,name:'Medicina general',status:'Activo',fee_minor:10000};
const medico = {id:1,first_name:'Ana',last_name:'Prueba',registration:'BROWSER-1',status:'Activo',specialties:[especialidad]};
const paciente = {id:1,first_name:'Paciente',last_name:'Prueba',birth_date:'2000-01-01',phone:'70000000',status:'Activo'};
const paymentUrl = 'https://www.sandbox.paypal.com/checkoutnow?token=PP-PRUEBA-001';
const slot = {doctor_id:1,doctor_name:'Ana Prueba',start_time:'10:30',end_time:'11:00',modality:'online'};

async function mockClinic(pagina, rolLocal, opciones={}) {
    const usuario = rolLocal ? {id:1,name:`Cuenta ${rolLocal}`,username:'cuenta_prueba',email:'cuenta@example.test',role: rolLocal,status:'Activo'} : null;
    const estadoLocal = {calls:[],pending:0,confirmed:false,authenticated:!opciones.initiallyGuest,activated:!opciones.requireActivation,patients:[{...paciente}],schedules:opciones.schedules||[]};
    estadoLocal.appointments=opciones.appointments||[];estadoLocal.refunds=opciones.refunds||[];estadoLocal.requests=[];estadoLocal.unavailabilities=opciones.unavailabilities||[];
    const reserva = {id:1,date:'2030-11-11',start_time:'10:30',end_time:'11:00',status:'processing',attempt:1,amount_minor:10000,expires_at:new Date(Date.now()+300000).toISOString()};
    const pago = {id:1,method:'paypal',status:'pending',amount_minor:10000,provider_amount_minor:1000,provider_currency:'USD',exchange_rate:'10',approval_url:'https://www.sandbox.paypal.com/checkoutnow?token=PP-PRUEBA-001'};
    const citaLocal = {id:1,patient: paciente,doctor: medico,specialty: especialidad,date:reserva.date,start_time:reserva.start_time,end_time:reserva.end_time,status:'Programada',payment:{...pago,method:'paypal',reference:'PP-PRUEBA-001',status:'confirmed'},...opciones.appointmentOverrides};
    await pagina.route('**/api/**', async route => {
        const url = new URL(route.request().url()), ruta=url.pathname.slice(5);
        estadoLocal.calls.push({path: ruta,method:route.request().method(),data:route.request().postDataJSON()});
        let datos;
        if(ruta==='session')datos={user:estadoLocal.authenticated?usuario:null,access:opciones.staleAccessBeforeLogin&&!estadoLocal.authenticated?{}:acceso,csrf:'browser-test-csrf',terms_version:'2026-11-02'};
        else if(ruta==='login') {
            if(!estadoLocal.activated){await route.fulfill({status:403,json:{activation_required:true,email:usuario.email,message:'Debe activar su cuenta con el código enviado a su correo.'}});return;}
            estadoLocal.authenticated=!opciones.sessionNotPersisted;
            if(opciones.alreadyActive) {await route.fulfill({status:409,json:{message:'Ya tiene una sesión activa.'}});return;}
            datos={user: usuario,csrf:'browser-test-csrf'};
        }
        else if(ruta==='workspace')datos={today:{total:0,expected:0,waiting:0,attended:0},next:[],requests:0,refunds:0,setup:['Administrador','Superadmin'].includes(rolLocal)?{specialties:1,doctors:1,scheduled_doctors:1}:null};
        else if(ruta==='logout'){estadoLocal.authenticated=false;datos={message:'Sesión cerrada.'};}
        else if(ruta==='references') {
            if(opciones.referencesFailOnce && !estadoLocal.failedReferences) {estadoLocal.failedReferences=true;await route.fulfill({status:503,json:{message:'No se pudo cargar el panel.'}});return;}
            datos={doctors:[medico],specialties:[especialidad],patients:estadoLocal.patients};
        }
        else if(ruta==='medical-availability/options')datos={online_payment_available:opciones.paymentAvailable!==false,bob_per_usd:'10',payment_provider:'paypal',doctors:[medico],specialties:[especialidad]};
        else if(ruta==='medical-availability')datos={blocks:[{...slot,...opciones.slotOverrides}],modality:url.searchParams.get('modality')||'online'};
        else if(ruta==='appointments'&&route.request().method()==='POST'){datos=citaLocal;estadoLocal.appointments=[citaLocal];}
        else if(/^appointments\/\d+\/(attendance|attend|reschedule-request)$/.test(ruta)) {
            const [,id,accion]=ruta.split('/'),a=estadoLocal.appointments.find(a=>a.id===Number(id));
            if(accion==='reschedule-request'){datos={id:1,appointment_id:Number(id),status:'pending',reason:route.request().postDataJSON().reason};estadoLocal.requests.push(datos);}
            else {Object.assign(a,accion==='attendance'?{status:'Confirmada',attendance_at:new Date().toISOString(),care_ready:true}:{status:'Atendida'});datos=a;}
        }
        else if(/^refunds\/\d+\/decision$/.test(ruta)){datos=estadoLocal.refunds.find(r=>r.id===Number(ruta.split('/')[1]));datos.status=route.request().postDataJSON().decision==='approve'?'approved':'rejected';}
        else if(/^refunds\/\d+\/complete$/.test(ruta)){datos=estadoLocal.refunds.find(r=>r.id===Number(ruta.split('/')[1]));Object.assign(datos,route.request().postDataJSON(),{status:'completed'});}
        else if(ruta==='online-bookings'&&route.request().method()==='POST')datos={booking: reserva,payment: pago,approval_url:pago.approval_url};
        else if(ruta==='online-bookings/1/status')datos={booking: reserva,payment: pago,appointment:estadoLocal.confirmed?citaLocal:null};
        else if(ruta==='online-bookings/1/cancel')datos={booking:{...reserva,status:'cancelled'},payment:{...pago,status:'failed'}};
        else if(ruta==='online-bookings')datos=[];
        else if(ruta==='schedules'&&route.request().method()==='GET')datos={schedules:estadoLocal.schedules,unavailabilities:estadoLocal.unavailabilities,quota:{presential_percent:50,online_percent:50}};
        else if(ruta==='schedules'||/^schedules\/\d+$/.test(ruta)){const entrada=route.request().postDataJSON();const id=ruta.includes('/')?Number(ruta.split('/')[1]):estadoLocal.schedules.length+1;estadoLocal.schedules=estadoLocal.schedules.filter(s=>s.id!==id);estadoLocal.schedules.push({...entrada,id});datos={...entrada,id,affected_appointments:[]};}
        else if(ruta==='availability')datos=[{start:'12:00',end:'12:30',status:'Disponible'}];
        else if(ruta==='reception-inbox')datos={pending:estadoLocal.pending,latest_id:estadoLocal.pending?1:null};
        else if(ruta==='audit/filters')datos={users:[],modules:[],actions:[],results:[]};
        else if(ruta==='audit'||ruta==='supervision/payments')datos={data:[],total:0,current_page:1,last_page:1};
        else if(ruta==='entities/users')datos=[{...usuario,id:1}];
        else if(ruta==='supervision/accounts')datos=[{...usuario,id:1},{id:2,name:'Ana paciente',username:'ana.paciente',email:'ana@example.test',role:'Paciente',status:'Activo'}];
        else if(ruta==='supervision/accounts/2/password')datos={message:'Contraseña cambiada.'};
        else if(ruta==='account/username') {usuario.username=route.request().postDataJSON().username;datos={user: usuario,csrf:'renewed-username-csrf',message:'Tu usuario de acceso se actualizó correctamente.'};}
        else if(ruta==='account/profile')datos={user: usuario,patient:{...paciente,phone:'70000000',address:'Dirección de prueba'}};
        else if(ruta==='account/password')datos={user: usuario,csrf:'renewed-password-csrf',message:'Contraseña actualizada.'};
        else if(ruta==='account/activate'){estadoLocal.activated=true;datos={message:'Cuenta activada. Ya puede iniciar sesión.'};}
        else if(ruta==='account/email')datos={message:'Si la cuenta está habilitada, recibirá las instrucciones en su correo.'};
        else if(ruta==='patients/with-account') {
            const entrada=route.request().postDataJSON();
            datos={...paciente,...entrada,account:{id:2,username:entrada.username,email:entrada.email,email_verified_at:null},activation_email_sent:true};
            delete datos.password;delete datos.password_confirmation;estadoLocal.patients=[datos];
        }
        else if(ruta==='patients/1/account') {
            const entrada=route.request().postDataJSON();datos={user:{id:2,username:entrada.username,email:entrada.email,email_verified_at:null},activation_email_sent:true,message:'Cuenta creada. Confirme el código enviado por correo.'};
            estadoLocal.patients[0].account=datos.user;
        }
        else if(ruta==='entities/doctors')datos=[medico];
        else if(ruta==='entities/specialties')datos=[especialidad];
        else if(ruta==='entities/patients'&&route.request().method()==='POST'){datos={...paciente,...route.request().postDataJSON(),id:2};estadoLocal.patients.push(datos);}
        else if(ruta==='entities/patients')datos=estadoLocal.patients;
        else if(ruta==='appointments'&&route.request().method()==='GET')datos=estadoLocal.appointments;
        else if(ruta==='refunds')datos=estadoLocal.refunds;
        else if(ruta==='reschedule-requests')datos=estadoLocal.requests;
        else if(['appointments','reschedule-requests','refunds','schedule-impacts','agenda-adjustments','supervision/patient-accounts'].includes(ruta))datos=[];
        else {
            await route.fulfill({status:500,json:{message:`Ruta no simulada: ${ruta}`}});
            return;
        }
        await route.fulfill({json:datos});
    });
    return estadoLocal;
}

test.describe('Auditoría con navegador en otra zona horaria', () => {
test.use({timezoneId:'Asia/Tokyo'});
test('bitácora: Administrador ve valores anteriores y nuevos, responsable histórico y datos relacionados', async ({page: pagina}) => {
    await mockClinic(pagina, 'Administrador');
    const evento = {id: 901, occurred_at: '2026-11-13T02:30:00.000000Z', user_id: 1, module: 'specialties', action: 'updated',
        result: 'success', record_type: 'Specialty', record_id: 3, ip_address: '203.0.113.25', details: {
            actor: {id: 1, name: 'Administradora original', username: 'demo.admin', role: 'Administrador'},
            record: {id: 3, name: 'Pediatría', fee_minor: 15000, status: 'Inactivo'},
            fields: ['fee_minor', 'status'], changes: {fee_minor: {before: 10000, after: 15000}, status: {before: 'Activo', after: 'Inactivo'}},
            request: {method: 'PUT', route: 'api/entities/{module}/{id}'},
        }};
    await pagina.route('**/api/audit**', async route => {
        const ruta = new URL(route.request().url()).pathname;
        if (ruta.endsWith('/filters')) await route.fulfill({json: {users: [{id: 1, name: 'Nombre actual', role: 'Administrador'}], modules: ['specialties'], actions: ['updated'], results: ['success']}});
        else if (ruta.endsWith('/901')) await route.fulfill({json: evento});
        else await route.fulfill({json: {data: [evento], total: 1, current_page: 1, last_page: 1}});
    });
    await pagina.goto('/#auditoria');
    await expect(pagina.locator('#main')).toContainText('Administradora original');
    await expect(pagina.locator('#main')).toContainText('Pediatría');
    await expect(pagina.locator('#main')).toContainText('12/11/2026 22:30:00');
    await expect(pagina.locator('#main')).toContainText('Santa Cruz, Bolivia');
    await pagina.locator('[data-audit="901"]').click();
    const dialog = pagina.getByRole('dialog');
    await expect(dialog).toContainText('12/11/2026 22:30:00');
    await expect(dialog).toContainText('Cambios realizados');
    const fee = dialog.getByRole('row').filter({hasText: 'Tarifa'});
    await expect(fee).toContainText(/100[,.]00 BOB/);
    await expect(fee).toContainText(/150[,.]00 BOB/);
    await expect(dialog).toContainText('203.0.113.25');
    await expect(dialog).toContainText('Administradora original');
    await expect(dialog).toContainText('Registro en el momento de la acción');
    await pagina.screenshot({path: 'storage/app/private/bitacora-detalle-local.png', fullPage: true});
});
});

test('bitácora: explica el límite de los eventos antiguos y escapa los valores registrados', async ({page: pagina}) => {
    await mockClinic(pagina, 'Administrador');
    const evento = {id: 902, occurred_at: '2026-11-01', module: 'users', action: 'updated', result: 'success',
        record_type: 'User', record_id: 2, details: {fields: ['status'], reason: '<img src=x onerror="window.auditInjected=true">'}};
    await pagina.route('**/api/audit**', async route => {
        const ruta = new URL(route.request().url()).pathname;
        if (ruta.endsWith('/filters')) await route.fulfill({json: {users: [], modules: ['users'], actions: ['updated'], results: ['success']}});
        else if (ruta.endsWith('/902')) await route.fulfill({json: evento});
        else await route.fulfill({json: {data: [evento], total: 1, current_page: 1, last_page: 1}});
    });
    await pagina.goto('/#auditoria');
    await pagina.locator('[data-audit="902"]').click();
    await expect(pagina.getByRole('dialog')).toContainText('Este evento no conserva valores anteriores y nuevos');
    await expect(pagina.getByRole('dialog')).toContainText('<img src=x');
    expect(await pagina.evaluate(() => Boolean(window.auditInjected))).toBe(false);
});

for (const rolLocal of ['Recepcionista', 'Superadmin']) {
    test(`bloqueos provisionales: ${rolLocal} propone, selecciona, visualiza y libera`, async ({page: pagina}) => {
        await mockClinic(pagina, rolLocal);
        const registros = [], calls = [];
        let fechaLocal = '';
        await pagina.route('**/api/agenda-adjustments**', async route => {
            const url = new URL(route.request().url());
            const ruta = url.pathname.slice(5), metodo = route.request().method();
            const entrada = route.request().postDataJSON();
            calls.push({path: ruta, method: metodo, input: entrada});
            if (metodo === 'GET') {
                fechaLocal = url.searchParams.get('date');
                await route.fulfill({json: registros});
            } else if (ruta === 'agenda-adjustments/proposal') {
                await route.fulfill({json: {requested: 2, unfulfilled: 0, blocks: [
                    {doctor_id: 1, date: fechaLocal, start_time: '22:00', end_time: '22:30'},
                    {doctor_id: 1, date: fechaLocal, start_time: '22:30', end_time: '23:00'},
                ]}});
            } else if (ruta === 'agenda-adjustments/release') {
                registros.forEach(registro => { if (entrada.ids.map(Number).includes(registro.id)) registro.status = 'released'; });
                await route.fulfill({json: registros});
            } else if (ruta === 'agenda-adjustments') {
                entrada.blocks.forEach((bloque, indice) => registros.push({...bloque, id: indice + 1,
                    specialty_id: Number(entrada.specialty_id), kind: entrada.kind, status: 'active', created_by: 1}));
                await route.fulfill({status: 201, json: registros});
            } else await route.fulfill({status: 500, json: {message: 'Ruta inesperada de bloqueos.'}});
        });
        await pagina.goto('/#bloqueos');
        await expect(pagina.getByRole('heading', {name: 'Bloqueos provisionales', exact: true})).toBeVisible();
        await pagina.locator('[data-demand-specialty="1"]').click();
        await expect(pagina.locator('#main')).not.toHaveAttribute('aria-busy', 'true');
        await pagina.getByRole('button', {name: 'Añadir una persona', exact: true}).click();
        await expect(pagina.locator('#demand-count')).toHaveText('2');
        await pagina.locator('#demand-proposal-form button[type=submit]').click();
        await expect(pagina.locator('#confirm-demand-form [name=blocks]')).toHaveCount(2);
        expect(calls.filter(call => call.path === 'agenda-adjustments' && call.method === 'POST')).toHaveLength(0);
        await pagina.locator('#confirm-demand-form [name=blocks]').nth(1).uncheck();
        await pagina.locator('#confirm-demand-form button[type=submit]').click();
        await expect(pagina.locator('#release-blocks-form')).toContainText('Activo');
        await expect(pagina.locator('#release-blocks-form')).toContainText('Medicina general');
        await expect(pagina.locator('#release-blocks-form')).toContainText('Ana Prueba');
        await expect(pagina.locator('#release-blocks-form')).toContainText('22:00–22:30');
        const created = calls.find(call => call.path === 'agenda-adjustments' && call.method === 'POST');
        expect(created.input.blocks).toHaveLength(1);
        expect(created.input.quantity).toBe(2);
        await pagina.reload();
        await expect(pagina.locator('#release-blocks-form')).toContainText('Activo');
        await pagina.screenshot({path: `storage/app/private/bloqueos-${rolLocal}.png`, fullPage: true});
        await pagina.locator('#release-blocks-form [name=ids]').check();
        await pagina.locator('#release-blocks-form button[type=submit]').click();
        await expect(pagina.getByRole('dialog')).toBeVisible();
        expect(calls.filter(call => call.path === 'agenda-adjustments/release')).toHaveLength(0);
        await pagina.locator('#confirm-action').click();
        await expect(pagina.getByRole('dialog')).not.toBeVisible();
        await expect(pagina.locator('#release-blocks-form')).toContainText('Liberado');
        await expect(pagina.locator('#release-blocks-form [name=ids]')).toHaveCount(0);
    });
}

for (const rolLocal of ['Administrador', 'Superadmin']) {
    test(`ausencias: ${rolLocal} revisa las citas, registra y retira conservando el historial`, async ({page: pagina}) => {
        const estadoLocal = await mockClinic(pagina, rolLocal, {schedules: [{id:1,doctor_id:1,weekday:2,start_time:'08:00',end_time:'12:00',duration:30}]});
        const afectadas = [{id:8,patient: paciente,doctor: medico,specialty: especialidad,date:'2030-11-12',start_time:'08:00',end_time:'08:30',status:'Programada'}];
        const calls = [];
        await pagina.route('**/api/unavailabilities**', async route => {
            const ruta = new URL(route.request().url()).pathname.slice(5);
            const entrada = route.request().postDataJSON();
            calls.push({path: ruta, input: entrada});
            if (ruta.endsWith('/preview')) await route.fulfill({json: {unavailability:null, affected: afectadas}});
            else if (ruta.endsWith('/impacts')) await route.fulfill({json: {unavailability:estadoLocal.unavailabilities[0], affected: afectadas}});
            else if (ruta.endsWith('/cancel')) {
                Object.assign(estadoLocal.unavailabilities[0], {cancelled_at:'2030-11-12T07:30:00-04:00',cancelled_by:1,cancellation_reason:entrada.reason});
                await route.fulfill({json: {unavailability:estadoLocal.unavailabilities[0], affected:[]}});
            } else {
                estadoLocal.unavailabilities.push({...entrada,id:5,cancelled_at:null});
                await route.fulfill({status:201,json:{unavailability:estadoLocal.unavailabilities[0],affected: afectadas}});
            }
        });
        await pagina.goto('/#horarios');
        await pagina.locator('[data-tab=block]').click();
        await pagina.locator('#new-unavailability').click();
        await pagina.getByLabel('Desde', {exact:true}).fill('2030-11-12T08:00');
        await pagina.getByLabel('Hasta', {exact:true}).fill('2030-11-12T10:00');
        await pagina.locator('#preview-block').click();
        await expect(pagina.locator('#affected-preview')).toContainText('1 citas pendientes afectadas');
        await expect(pagina.locator('#affected-preview')).toContainText('Paciente Prueba');
        await expect(pagina.locator('#affected-preview')).toContainText('08:00–08:30');
        await pagina.locator('#unavailable-form button[type=submit]').click();
        await expect(pagina.getByRole('dialog')).toContainText('Resultado de la ausencia');
        await expect(pagina.getByRole('dialog')).toContainText('Paciente Prueba');
        await pagina.getByRole('dialog').press('Escape');
        await pagina.locator('[data-absence-impacts="5"]').click();
        await expect(pagina.getByRole('dialog')).toContainText('Paciente Prueba');
        await pagina.getByRole('dialog').press('Escape');
        await pagina.locator('[data-cancel-absence="5"]').click();
        await pagina.getByLabel('Motivo del retiro').fill('El médico confirmó que sí atenderá');
        expect(calls.filter(call => call.path.endsWith('/cancel'))).toHaveLength(0);
        await pagina.getByRole('button', {name:'Confirmar retiro'}).click();
        await expect(pagina.getByRole('dialog')).not.toBeVisible();
        await expect(pagina.locator('#main')).toContainText('Retirada');
        await expect(pagina.locator('#main')).toContainText('El médico confirmó que sí atenderá');
        await expect(pagina.locator('[data-cancel-absence="5"]')).toHaveCount(0);
        await pagina.screenshot({path:`storage/app/private/ausencia-retirada-${rolLocal}.png`,fullPage:true});
        await pagina.reload();
        await pagina.locator('[data-tab=block]').click();
        await expect(pagina.locator('#main')).toContainText('Retirada');
    });
}

test('horarios: primero muestra la semana, evita cruces y modifica el turno seleccionado',async({page: pagina})=>{
    const estadoLocal=await mockClinic(pagina,'Administrador',{schedules:[{id:1,doctor_id:1,weekday:1,start_time:'08:00:00',end_time:'12:00:00',duration:30}]});
    await pagina.goto('/#horarios');
    await expect(pagina.locator('.week-grid')).toBeVisible();
    await expect(pagina.locator('[data-schedule="1"]')).toContainText('08:00–12:00');
    await expect(pagina.locator('#schedule-form')).toHaveCount(0);
    await pagina.locator('[data-new-shift="1"]').click();
    await expect(pagina.locator('#schedule-form [name=start_time]')).toHaveValue('12:00');
    await pagina.locator('#schedule-form [name=start_time]').fill('11:00');
    await expect(pagina.locator('#schedule-conflicts')).toContainText('Se cruza con 08:00–12:00');
    await pagina.locator('#schedule-form button[type=submit]').click();
    expect(estadoLocal.calls.filter(c=>c.path==='schedules'&&c.method==='POST')).toHaveLength(0);
    await pagina.getByRole('button',{name:'Cancelar',exact:true}).click();
    await pagina.locator('[data-schedule="1"]').click();
    await pagina.locator('#schedule-form [name=end_time]').fill('13:00');
    await pagina.locator('#schedule-form button[type=submit]').click();
    await expect(pagina.getByRole('dialog')).not.toBeVisible();
    await expect(pagina.locator('[data-schedule="1"]')).toContainText('08:00–13:00');
    expect(estadoLocal.calls.find(c=>c.path==='schedules/1').method).toBe('PUT');
    await pagina.screenshot({path:'storage/app/private/horarios-semana-local.png',fullPage:true});
});

test('el inicio ofrece tareas con permisos y abre horarios sin mostrar un formulario vacío',async({page: pagina})=>{
    await mockClinic(pagina,'Administrador');await pagina.goto('/');
    await expect(pagina.locator('h1')).toHaveText('Inicio y acciones');
    await pagina.locator('.setup-step[data-open-view="CU07"]').click();
    await expect(pagina.locator('.week-grid')).toBeVisible();
    await expect(pagina.locator('#schedule-form')).toHaveCount(0);
});

for(const rol of ['Recepcionista', 'Superadmin']) {
test(`WhatsApp: ${rol} prepara el aviso solo después de confirmar la reprogramación`, async ({page: pagina}) => {
    const cita={id:3,patient: paciente,doctor: medico,specialty: especialidad,patient_id:1,doctor_id:1,specialty_id:1,date:'2030-11-11',start_time:'10:30',end_time:'11:00',status:'Programada',modality:'presential',payment:{status:'confirmed'}};
    await mockClinic(pagina,rol,{appointments:[cita]});
    let guardado;
    await pagina.route('**/api/appointments/3/reschedule', async route => {
        guardado=route.request().postDataJSON();
        Object.assign(cita,{...guardado,start_time:'12:00:00',end_time:'12:30:00',doctor:{...medico,last_name:'Confirmada'},events:[{action:'Reprogramación'}]});
        await route.fulfill({json:cita});
    });
    await pagina.goto('/#reprogramaciones');
    await expect(pagina.locator('[data-whatsapp-reprogramacion]')).toHaveCount(0);
    await pagina.locator('[data-change="3"]').click();
    const formulario=pagina.locator('#clinical-reschedule');
    await formulario.locator('[name=date]').fill('2030-11-12');
    await formulario.locator('[name=start_time]').selectOption('12:00');
    await formulario.locator('[name=change_reason]').fill('El paciente aceptó el nuevo horario.');
    await formulario.locator('[name=patient_accepted]').check();
    await formulario.getByRole('button',{name:'Confirmar reprogramación'}).click();
    const enlace=pagina.getByRole('link',{name:'Abrir WhatsApp'});
    await expect(enlace).toBeVisible();
    const url=new URL(await enlace.getAttribute('href'));
    expect(url.origin).toBe('https://wa.me');
    expect(url.pathname).toBe('/59170000000');
    expect(url.searchParams.get('text')).toContain('12/11/2030');
    expect(url.searchParams.get('text')).toContain('12:00–12:30');
    expect(url.searchParams.get('text')).toContain('Ana Confirmada');
    expect(guardado.patient_accepted).toBe('1');
    await expect(enlace).toHaveAttribute('rel','noopener noreferrer');
    expect(pagina.context().pages()).toHaveLength(1);
});
}

test('WhatsApp: una solicitud aceptada permite recuperar el aviso y un teléfono inválido impide abrirlo', async ({page: pagina}) => {
    const cita={id:3,patient:{...paciente,phone:''},doctor: medico,specialty: especialidad,date:'2030-11-12',start_time:'12:00',end_time:'12:30',status:'Programada'};
    const estado=await mockClinic(pagina,'Recepcionista');
    estado.requests.push({id:5,appointment_id:3,status:'accepted',appointment:cita,reason:'Cambio acordado'});
    await pagina.goto('/#reprogramaciones');
    await pagina.locator('[data-notify-request="5"]').click();
    await expect(pagina.getByRole('dialog')).toContainText('Actualice el teléfono móvil');
    await expect(pagina.getByRole('link',{name:'Abrir WhatsApp'})).toHaveCount(0);
    await expect(pagina.locator('[name=mensaje_whatsapp]')).toHaveValue(/12:00–12:30/);
});

test('WhatsApp: una reprogramación rechazada por el servidor no prepara un aviso de confirmación', async ({page: pagina}) => {
    const cita={id:3,patient: paciente,doctor: medico,specialty: especialidad,patient_id:1,doctor_id:1,specialty_id:1,date:'2030-11-11',start_time:'10:30',end_time:'11:00',status:'Programada',modality:'presential',payment:{status:'confirmed'}};
    await mockClinic(pagina,'Recepcionista',{appointments:[cita]});
    await pagina.route('**/api/appointments/3/reschedule', route => route.fulfill({status:422,json:{message:'El horario ya está ocupado.'}}));
    await pagina.goto('/#reprogramaciones');
    await pagina.locator('[data-change="3"]').click();
    const formulario=pagina.locator('#clinical-reschedule');
    await formulario.locator('[name=start_time]').selectOption('12:00');
    await formulario.locator('[name=change_reason]').fill('Acuerdo de prueba');
    await formulario.locator('[name=patient_accepted]').check();
    await formulario.getByRole('button',{name:'Confirmar reprogramación'}).click();
    await expect(formulario).toContainText('El horario ya está ocupado.');
    await expect(pagina.locator('[data-whatsapp-reprogramacion]')).toHaveCount(0);
});

test('la reprogramación usa el médico y fecha del diálogo aunque los filtros estén vacíos',async({page: pagina})=>{
    const a={id:3,patient: paciente,doctor: medico,specialty: especialidad,patient_id:1,doctor_id:1,specialty_id:1,date:'2030-11-11',start_time:'10:30',end_time:'11:00',status:'Programada',modality:'presential',payment:{status:'confirmed'}};
    await mockClinic(pagina,'Recepcionista',{appointments:[a]});await pagina.goto('/#reprogramaciones');
    await pagina.locator('[data-change="3"]').click();
    await expect(pagina.locator('#clinical-reschedule [name=start_time] option[value="12:00"]')).toHaveCount(1);
    const url=await pagina.evaluate(()=>performance.getEntriesByType('resource').filter(r=>r.name.includes('/api/availability?')).at(-1)?.name);
    expect(new URL(url).searchParams.get('doctor_id')).toBe('1');
    expect(new URL(url).searchParams.get('date')).toBe('2030-11-11');
});

for(const [rolLocal,inicioLocal] of [['Superadmin','CU03'],['Administrador','CU03'],['Recepcionista','CU06'],['Médico','CU12'],['Paciente','CU18']]) {
    test(`${rolLocal}: inicio y menú restringidos`, async ({page: pagina}) => {
        const errores=[];pagina.on('pageerror',e=>errores.push(e.message));
        await mockClinic(pagina,rolLocal);await pagina.goto('/');
        await expect(pagina.locator('[data-home]')).toHaveAttribute('aria-current','page');
        const visible=await pagina.locator('.nav-btn').evaluateAll(nodes=>nodes.map(n=>n.dataset.view));
        expect(visible.sort()).toEqual(acceso[rolLocal].filter(id=>id!=='CU01').sort());
        if (['Superadmin','Paciente'].includes(rolLocal)) await pagina.screenshot({path:`storage/app/private/vista-${rolLocal.toLowerCase()}.png`,fullPage:true});
        expect(errores).toEqual([]);
    });
}

test('Superadmin abre todas las vistas sin fallos de ejecución', async ({page: pagina}) => {
    const errores=[];pagina.on('pageerror',e=>errores.push(e.message));
    await mockClinic(pagina,'Superadmin');await pagina.goto('/');
    for(let number=2;number<=26;number++) {
        const id=`CU${String(number).padStart(2,'0')}`;
        await pagina.locator(`.nav-btn[data-view="${id}"]`).click();
        await expect(pagina.locator('.nav-btn.selected')).toHaveAttribute('data-view',id);
        await expect(pagina.locator('#main')).not.toHaveAttribute('aria-busy','true');
        await expect(pagina.locator('body')).not.toContainText(/\bCU\d{2}\b/);
        await expect(pagina.locator('#retry')).toHaveCount(0);
        await expect(pagina.locator('#main')).not.toContainText('undefined');
    }
    expect(errores).toEqual([]);
});

for(const width of [360,768]) {
    test(`menú y pacientes adaptables a ${width}px, sin códigos técnicos ni desbordamiento`,async({page: pagina})=>{
        await pagina.setViewportSize({width,height:900});
        await mockClinic(pagina,'Superadmin');await pagina.goto('/#pacientes');
        await expect(pagina.getByRole('heading',{name:'Pacientes',exact:true})).toBeVisible();
        await expect(pagina.locator('#main')).not.toHaveAttribute('aria-busy','true');
        await expect(pagina.locator('body')).not.toContainText(/\bCU\d{2}\b/);
        expect(await pagina.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        if(width===360)expect(await pagina.locator('tbody tr').first().evaluate(el=>getComputedStyle(el).display)).toBe('block');
        await pagina.locator('#menu-toggle').click();
        await expect(pagina.locator('#menu-toggle')).toHaveAttribute('aria-expanded','true');
        await expect(pagina.locator('#menu-toggle')).toBeFocused();
        await expect(pagina.locator('#nav-search')).not.toBeFocused();
        await pagina.getByRole('button',{name:'Inicio y acciones',exact:true}).click();
        await expect(pagina.getByRole('heading',{name:'Inicio y acciones',exact:true})).toBeVisible();
        await pagina.locator('#menu-toggle').click();
        await expect(pagina.locator('#nav-search')).not.toBeFocused();
        await pagina.getByRole('searchbox',{name:'Buscar en el menú'}).click();
        await expect(pagina.locator('#nav-search')).toBeFocused();
        await pagina.getByRole('searchbox',{name:'Buscar en el menú'}).fill('medicos');
        await expect(pagina.locator('.nav-btn:visible')).toHaveCount(3);
        await pagina.getByRole('button',{name:'Médicos',exact:true}).click();
        await expect(pagina.getByRole('heading',{name:'Médicos',exact:true})).toBeVisible();
        await expect(pagina.locator('#menu-backdrop')).toBeHidden();
        await expect(pagina).toHaveURL(/#medicos$/);
        await pagina.locator('#menu-toggle').click();await pagina.keyboard.press('Escape');
        await expect(pagina.locator('#menu-toggle')).toBeFocused();
        await expect(pagina.locator('#menu-toggle')).toHaveAttribute('aria-expanded','false');
        await pagina.goto('/#pacientes');
        await pagina.getByRole('button',{name:'+ Registrar paciente',exact:true}).click();
        await expect(pagina.getByRole('dialog')).toBeVisible();
        const dialog=await pagina.getByRole('dialog').boundingBox();
        expect(dialog.x).toBeGreaterThanOrEqual(0);expect(dialog.x+dialog.width).toBeLessThanOrEqual(width);
        expect(await pagina.getByRole('dialog').evaluate(el=>el.scrollWidth<=el.clientWidth)).toBe(true);
        await pagina.screenshot({path:`storage/app/private/interfaz-pacientes-${width}.png`,fullPage:true});
    });
}

for(const width of [390,1440]) {
    test(`inicio de sesión terminado y adaptable a ${width}px`,async({page: pagina})=>{
        await pagina.setViewportSize({width,height:1000});
        await mockClinic(pagina,null);await pagina.goto('/');
        await expect(pagina.locator('#login-form')).toBeVisible();
        expect(await pagina.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        await expect(pagina.locator('#menu-toggle')).toBeHidden();
        await expect(pagina.locator('body')).not.toContainText(/\bCU\d{2}\b/);
        await pagina.screenshot({path:`storage/app/private/interfaz-acceso-${width}.png`,fullPage:true});
    });
}

test('todos los módulos se adaptan a móvil y el historial del navegador conserva la navegación',async({page: pagina})=>{
    await pagina.setViewportSize({width:390,height:844});
    const errores=[];pagina.on('pageerror',fallo=>errores.push(fallo.message));
    await mockClinic(pagina,'Superadmin');await pagina.goto('/#pacientes');
    for(let number=2;number<=26;number++) {
        const id=`CU${String(number).padStart(2,'0')}`;
        await pagina.evaluate(id=>{location.hash=id;},id);
        await expect(pagina.locator('.nav-btn.selected')).toHaveAttribute('data-view',id);
        await expect(pagina.locator('#main')).not.toHaveAttribute('aria-busy','true');
        await expect(pagina.locator('#retry')).toHaveCount(0);
        expect(await pagina.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),id).toBe(true);
    }
    await pagina.locator('#menu-toggle').click();await pagina.getByRole('button',{name:'Pacientes',exact:true}).click();
    await expect(pagina).toHaveURL(/#pacientes$/);
    await pagina.goBack();await expect(pagina.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU26');
    await pagina.goForward();await expect(pagina.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU06');
    expect(errores).toEqual([]);
});

for (const rolLocal of ['Superadmin','Administrador']) {
    test(`${rolLocal}: formulario de personal respeta la asignación de Superadmin`, async ({page: pagina}) => {
        await mockClinic(pagina,rolLocal);await pagina.goto('/#usuarios');
        await pagina.getByRole('button',{name:'+ Registrar usuario',exact:true}).click();
        await expect(pagina.getByLabel('Nombre de usuario', {exact:true})).toBeVisible();
        await expect(pagina.locator('#role option[value="Superadmin"]')).toHaveCount(rolLocal==='Superadmin'?1:0);
        await pagina.locator('#role').selectOption('Médico');
        await expect(pagina.locator('#doctor-link')).toBeVisible();
        await expect(pagina.locator('#doctor_id')).toHaveAttribute('required','');
    });
}

test('el acceso admite un nombre de usuario sin formato de correo electrónico', async ({page: pagina}) => {
    const estadoLocal=await mockClinic(pagina,'Paciente',{initiallyGuest:true});await pagina.goto('/');
    await expect(pagina.locator('#email')).toHaveAttribute('type','text');
    await pagina.getByLabel('Usuario o correo electrónico', {exact:true}).fill('ana.paciente');
    await pagina.locator('#password').fill('ClaveDePrueba2026');
    await pagina.getByRole('button',{name:'Iniciar sesión',exact:true}).click();
    await expect(pagina.locator('[data-home]')).toHaveAttribute('aria-current','page');
    expect(estadoLocal.calls.find(c=>c.path==='login').data.email).toBe('ana.paciente');
});

async function submitLogin(pagina) {
    await pagina.locator('#email').fill('superadmin');
    await pagina.locator('#password').fill('ClaveDePrueba2026');
    await pagina.getByRole('button',{name:'Iniciar sesión',exact:true}).click();
}

async function selectBookingSlot(pagina,{staff: personal=false,consult: consultar=false}={}) {
    if(personal){await pagina.locator('[data-pick="patient"]').first().click();await pagina.locator('#flow-next').click();}
    await pagina.locator('[data-pick="specialty"]').first().click();
    await pagina.locator('#flow-next').click();
    await pagina.locator('#booking-date').fill('2030-11-11');
    await pagina.locator('[data-flow-slot]').first().click();
    if(!consultar)await expect(pagina.locator('.confirmation-ticket')).toBeVisible();
}

test('el login actualiza los permisos de una pestaña abierta antes de agregar el rol', async ({page: pagina}) => {
    await mockClinic(pagina,'Superadmin',{initiallyGuest:true,staleAccessBeforeLogin:true});await pagina.goto('/');
    await submitLogin(pagina);
    await expect(pagina.locator('[data-home]')).toHaveAttribute('aria-current','page');
    await expect(pagina.locator('#login-form')).toHaveCount(0);
});

test('el login recupera la sesión activa sin atascarse en el formulario', async ({page: pagina}) => {
    await mockClinic(pagina,'Superadmin',{initiallyGuest:true,alreadyActive:true});await pagina.goto('/');
    await submitLogin(pagina);
    await expect(pagina.locator('[data-home]')).toHaveAttribute('aria-current','page');
});

test('si no se conserva la sesión no se muestra bienvenida y se explica el fallo', async ({page: pagina}) => {
    await mockClinic(pagina,'Superadmin',{initiallyGuest:true,sessionNotPersisted:true});await pagina.goto('/');
    await submitLogin(pagina);
    await expect(pagina.locator('#main')).toContainText('No se pudo conservar la sesión');
    await expect(pagina.locator('#toasts')).not.toContainText('Bienvenido');
});

test('un fallo al cargar el panel se puede reintentar sin volver a enviar credenciales', async ({page: pagina}) => {
    const estadoLocal=await mockClinic(pagina,'Superadmin',{initiallyGuest:true,referencesFailOnce:true});await pagina.goto('/');
    await submitLogin(pagina);
    await pagina.locator('#boot-retry').click();
    await expect(pagina.locator('[data-home]')).toHaveAttribute('aria-current','page');
    expect(estadoLocal.calls.filter(c=>c.path==='login')).toHaveLength(1);
});

test('Superadmin puede cambiar la contraseña de un paciente sin consultar la contraseña anterior', async ({page: pagina}) => {
    const estadoLocal=await mockClinic(pagina,'Superadmin');await pagina.goto('/#usuarios');
    await pagina.getByRole('button',{name:'Consultar todas las cuentas'}).click();
    await pagina.getByRole('row').filter({hasText:'ana.paciente'}).getByRole('button',{name:'Cambiar contraseña'}).click();
    await expect(pagina.getByRole('dialog')).toContainText('La contraseña actual no se puede consultar');
    await pagina.getByLabel('Nueva contraseña',{exact:true}).fill('123456');
    await pagina.getByLabel('Repetir contraseña nueva',{exact:true}).fill('123456');
    await pagina.getByRole('button',{name:'Guardar nueva contraseña'}).click();
    await expect(pagina.getByRole('dialog')).not.toBeVisible();
    expect(estadoLocal.calls.find(c=>c.path==='supervision/accounts/2/password').data.password_confirmation).toBe('123456');
});

test('Administrador no tiene acceso a la consulta global de cuentas', async ({page: pagina}) => {
    await mockClinic(pagina,'Administrador');await pagina.goto('/');
    await expect(pagina.getByRole('button',{name:'Consultar todas las cuentas'})).toHaveCount(0);
});

test('cualquier cuenta puede elegir su usuario desde Mi sesión', async ({page: pagina}) => {
    const estadoLocal=await mockClinic(pagina,'Paciente');await pagina.goto('/#CU02');
    await pagina.getByLabel('Usuario de acceso',{exact:true}).fill('mi.nuevo.usuario');
    await pagina.getByLabel('Confirma tu contraseña actual',{exact:true}).fill('ClaveDePrueba2026');
    await pagina.getByRole('button',{name:'Guardar usuario de acceso'}).click();
    await expect(pagina.getByLabel('Usuario de acceso',{exact:true})).toHaveValue('mi.nuevo.usuario');
    expect(estadoLocal.calls.find(c=>c.path==='account/username').method).toBe('PATCH');
});

test('Paciente consulta, retiene y obtiene ficha únicamente después de la confirmación del servidor', async ({page: pagina}) => {
    const estadoLocal=await mockClinic(pagina,'Paciente');await pagina.goto('/#reservar-cita');
    await selectBookingSlot(pagina);
    expect(estadoLocal.calls.filter(c=>c.path==='online-bookings'&&c.method==='POST')).toHaveLength(0);
    await pagina.getByRole('button',{name:'Continuar a PayPal Sandbox',exact:true}).click();
    await expect(pagina.getByRole('dialog')).toContainText('Pago con PayPal Sandbox');
    await expect(pagina.getByRole('dialog')).not.toContainText('Ficha CIT-');
    await expect(pagina.getByRole('link',{name:'Continuar en PayPal Sandbox'})).toHaveAttribute('href',paymentUrl);
    const peticion=estadoLocal.calls.find(c=>c.path==='online-bookings'&&c.method==='POST');
    expect(peticion.data.doctor_id).toBe(1);expect(peticion.data.patient_id).toBeUndefined();expect(peticion.data.idempotency_key).toMatch(/^[a-f0-9-]{36}$/);
    estadoLocal.confirmed=true;await pagina.getByRole('button',{name:'Consultar estado',exact:true}).click();
    await expect(pagina.getByRole('dialog')).toContainText('Ficha CIT-0001');
    await expect(pagina.getByRole('dialog')).toContainText('PP-PRUEBA-001');
    await expect(pagina.getByRole('button',{name:'Imprimir ficha'})).toBeVisible();
});

for (const [nombreLocal,appointmentOverrides,expected] of [
    ['efectivo',{payment:{id:17,method:'cash',status:'confirmed',amount_minor:10000}},'Pago n.º 17'],
    ['transferencia',{payment:{id:18,method:'transfer',status:'confirmed',amount_minor:10000,bank_reference:'BANCO-PRUEBA-018'}},'BANCO-PRUEBA-018'],
    ['exención',{payment:null,complimentary:true,parent_appointment_id:9},'Consulta original CIT-0009'],
]) {
    test(`ficha de ${nombreLocal} muestra su referencia y distingue un bloqueo provisional`,async({page: pagina})=>{
        await mockClinic(pagina,'Recepcionista',{appointmentOverrides,slotOverrides:{modality:'presential',demand_blocked:true}});
        await pagina.goto('/#CU09');
        await selectBookingSlot(pagina,{staff:true});
        await pagina.locator('[name="payment_confirmed"]').check();
        await pagina.getByRole('button',{name:'Confirmar cita y emitir ficha',exact:true}).click();
        await expect(pagina.getByRole('dialog')).toContainText(expected);
    });
}

test('el retorno de PayPal consulta al servidor y no confirma por el token de la URL', async ({page: pagina}) => {
    const estadoLocal=await mockClinic(pagina,'Paciente');
    await pagina.goto('/?paypal_booking=1&token=NO-CONFIRMA&PayerID=NO-CONFIRMA#CU18');
    await expect(pagina.getByRole('dialog')).toContainText('Pago con PayPal Sandbox');
    await expect(pagina.getByRole('dialog')).not.toContainText('Ficha CIT-');
    expect(estadoLocal.calls.filter(c=>c.path==='online-bookings/1/status'&&c.method==='POST')).toHaveLength(1);
    expect(estadoLocal.calls.some(c=>c.path==='online-bookings'&&c.method==='POST')).toBe(false);
    expect(new URL(pagina.url()).searchParams.has('paypal_booking')).toBe(false);
});

test('sin pasarela hay horarios y aviso pero no un botón para iniciar un cobro', async ({page: pagina}) => {
    const estadoLocal=await mockClinic(pagina,'Paciente',{paymentAvailable:false});await pagina.goto('/#reservar-cita');
    await expect(pagina.locator('#main')).toContainText('El pago online no está habilitado');
    await selectBookingSlot(pagina);
    await expect(pagina.getByRole('button',{name:'Continuar a PayPal Sandbox',exact:true})).toBeDisabled();
    expect(estadoLocal.calls.some(c=>c.path==='online-bookings'&&c.method==='POST')).toBe(false);
});

test('Paciente puede descartar la solicitud cuando el servidor confirma que no existe pago en proceso', async ({page: pagina}) => {
    await mockClinic(pagina,'Paciente');await pagina.goto('/#reservar-cita');
    await selectBookingSlot(pagina);
    await pagina.getByRole('button',{name:'Continuar a PayPal Sandbox',exact:true}).click();
    await pagina.getByRole('button',{name:'Descartar solicitud sin pago'}).click();
    await expect(pagina.getByRole('dialog')).toContainText('Solicitud descartada');
    await expect(pagina.getByRole('dialog')).not.toContainText('Ficha CIT-');
});

test('Superadmin supervisa reservas sin emitirlas en nombre de un paciente', async ({page: pagina}) => {
    await mockClinic(pagina,'Superadmin');await pagina.goto('/#CU18');
    await expect(pagina.locator('#main')).toContainText('Vista de supervisión');
    await selectBookingSlot(pagina);
    await expect(pagina.getByRole('button',{name:'Continuar a PayPal Sandbox',exact:true})).toBeDisabled();
});

test('el inicio no permite registrarse y presenta la activación por código de correo', async ({page: pagina}) => {
    const estadoLocal=await mockClinic(pagina,null);await pagina.goto('/');
    await expect(pagina.getByRole('button',{name:'Crear cuenta de paciente'})).toHaveCount(0);
    await expect(pagina.locator('#main')).toContainText('acuda a la clínica');
    await pagina.getByRole('button',{name:'Activar mi cuenta',exact:true}).click();
    await pagina.getByLabel('Correo de la cuenta',{exact:true}).fill('paciente@example.test');
    await pagina.getByLabel('Código de activación',{exact:true}).fill('12345678');
    await pagina.getByRole('button',{name:'Confirmar código y activar'}).click();
    await expect(pagina.locator('#login-form')).toBeVisible();
    expect(estadoLocal.calls.some(c=>c.path==='register')).toBe(false);
    expect(estadoLocal.calls.find(c=>c.path==='account/activate').data).toEqual({email:'paciente@example.test',code:'12345678'});
    await pagina.locator('#password').fill('ClaveDePrueba2026');
    await pagina.getByRole('button',{name:'Mostrar contraseña',exact:true}).first().click();
    await expect(pagina.locator('#password')).toHaveAttribute('type','text');
    await pagina.getByRole('button',{name:'Ocultar contraseña',exact:true}).click();
    await expect(pagina.locator('#password')).toHaveAttribute('type','password');
});

for(const rolLocal of ['Superadmin','Administrador','Recepcionista']) {
    test(`${rolLocal}: registra paciente y cuenta pendiente de activación en un formulario`,async({page: pagina})=>{
        const estadoLocal=await mockClinic(pagina,rolLocal);await pagina.goto('/#CU06');
        await pagina.getByRole('button',{name:'+ Registrar paciente',exact:true}).click();
        await pagina.getByLabel('Nombres',{exact:true}).fill('Ana');
        await pagina.getByLabel('Apellidos',{exact:true}).fill('Paciente');
        await pagina.getByLabel('Teléfono',{exact:true}).fill('70000000');
        await pagina.getByLabel('Fecha de nacimiento',{exact:true}).fill('2000-01-01');
        await pagina.locator('#create-account').check();
        await pagina.getByLabel('Correo electrónico para activar la cuenta',{exact:true}).fill('ana@example.test');
        await pagina.getByLabel('Nombre de usuario',{exact:true}).fill('ana.paciente');
        await pagina.getByLabel('Contraseña inicial',{exact:true}).fill('ClaveDePrueba2026');
        await pagina.getByLabel('Repetir contraseña inicial',{exact:true}).fill('ClaveDePrueba2026');
        await pagina.locator('[name="identity_verified"]').check();
        await pagina.locator('[name="terms_accepted"]').check();
        await pagina.getByRole('button',{name:'Guardar',exact:true}).click();
        await expect(pagina.getByRole('dialog')).not.toBeVisible();
        await expect(pagina.locator('#records')).toContainText('ana.paciente · Pendiente de código');
        expect(estadoLocal.calls.find(c=>c.path==='patients/with-account').data.identity_verified).toBe('1');
    });
}

test('el login bloqueado abre la activación y solo permite acceder después del código',async({page: pagina})=>{
    const estadoLocal=await mockClinic(pagina,'Administrador',{initiallyGuest:true,requireActivation:true});await pagina.goto('/');
    await submitLogin(pagina);
    await expect(pagina.locator('#activation-form')).toBeVisible();
    await expect(pagina.locator('.nav-btn')).toHaveCount(0);
    await pagina.getByLabel('Código de activación',{exact:true}).fill('12345678');
    await pagina.getByRole('button',{name:'Confirmar código y activar'}).click();
    await expect(pagina.locator('#login-form')).toBeVisible();
    await submitLogin(pagina);
    await expect(pagina.locator('[data-home]')).toHaveAttribute('aria-current','page');
    expect(estadoLocal.calls.filter(c=>c.path==='login')).toHaveLength(2);
});

test('el historial del paciente se presenta como propio y explica sus cambios sin pedir elegir paciente',async({page: pagina})=>{
    const ownAppointment={
        id:25,patient: paciente,doctor: medico,specialty: especialidad,date:'2030-11-11',start_time:'10:30',end_time:'11:00',
        status:'Atendida',created_at:'2030-11-01T10:00:00Z',attendance_at:'2030-11-11T10:25:00Z',
        events:[{created_at:'2030-11-02T10:00:00Z',action:'Reprogramación',details:{
            before:{doctor_id:2,date:'2030-11-10',start_time:'09:00',end_time:'09:30'},
            after:{doctor_id:1,date:'2030-11-11',start_time:'10:30',end_time:'11:00'},
            cause:'patient',reason:'El paciente solicitó otro horario',
        }}],
    };
    const estadoLocal=await mockClinic(pagina,'Paciente',{appointments:[ownAppointment]});
    await pagina.goto('/#mis-citas');
    await expect(pagina.locator('h1')).toHaveText('Mis citas e historial');
    await expect(pagina.locator('#main')).toContainText('Sus citas y su atención');
    await expect(pagina.locator('#board-search')).toBeVisible();
    await expect(pagina.locator('.appointment-card')).toContainText('Ana Prueba');
    await expect(pagina.locator('#main')).not.toContainText('Selecciona un paciente');
    await expect(pagina.locator('#patient_id')).toHaveCount(0);
    await pagina.getByRole('button',{name:'Ver detalle',exact:true}).click();
    await expect(pagina.getByRole('dialog')).toContainText('Cita reprogramada');
    await expect(pagina.getByRole('dialog')).toContainText('Programación anterior');
    await expect(pagina.getByRole('dialog')).toContainText('Nueva programación');
    await expect(pagina.getByRole('dialog')).toContainText('Origen: Solicitud del paciente');
    await expect(pagina.getByRole('dialog')).not.toContainText('doctor_id');
    await expect(pagina.getByRole('dialog')).not.toContainText('{"before"');
    await expect(pagina.getByRole('dialog')).not.toContainText('2030-11-01T10:00:00Z');
    expect(estadoLocal.calls.find(c=>c.path==='appointments').method).toBe('GET');
});

test('el médico consulta los datos necesarios de su agenda sin información de pago',async({page: pagina})=>{
    const ownAppointment={
        id:26,patient:{...paciente,identity:'PRIVATE-CI',phone:'70000000',email:'private@example.test',address:'Dirección privada'},
        doctor: medico,specialty: especialidad,date:'2030-11-11',start_time:'10:30',end_time:'11:00',status:'Atendida',
        created_at:'2030-11-01T10:00:00Z',events:[],
        payment:{amount_minor:10000,status:'confirmed',bank_reference:'BANK-PRIVATE'},
    };
    await mockClinic(pagina,'Médico',{appointments:[ownAppointment]});
    await pagina.goto('/#agenda');
    await pagina.getByRole('button',{name:'Ver detalle',exact:true}).click();
    const dialog=pagina.getByRole('dialog');
    await expect(dialog).toContainText('Paciente Prueba');
    await expect(dialog).not.toContainText('Pago');
    await expect(dialog).not.toContainText('PRIVATE-CI');
    await expect(dialog).not.toContainText('70000000');
    await expect(dialog).not.toContainText('BANK-PRIVATE');
});

test('Paciente puede cambiar su contraseña y consultar sus datos tras activar su cuenta',async({page: pagina})=>{
    const estadoLocal=await mockClinic(pagina,'Paciente');await pagina.goto('/#CU02');
    await pagina.getByLabel('Contraseña actual',{exact:true}).fill('AnteriorSegura2026');
    await pagina.getByLabel('Nueva contraseña',{exact:true}).fill('ClaveNueva2026');
    await pagina.getByLabel('Repetir nueva contraseña',{exact:true}).fill('ClaveNueva2026');
    await pagina.getByRole('button',{name:'Cambiar contraseña',exact:true}).click();
    await expect(pagina.locator('#my-profile-form')).toBeVisible();
    expect(estadoLocal.calls.find(c=>c.path==='account/password').data).toEqual({current_password:'AnteriorSegura2026',password:'ClaveNueva2026',password_confirmation:'ClaveNueva2026'});
});

test('Recepción recibe aviso de solicitud nueva y puede abrir su bandeja', async ({page: pagina}) => {
    await pagina.clock.install();const estadoLocal=await mockClinic(pagina,'Recepcionista');await pagina.goto('/');
    await expect(pagina.locator('#reception-inbox')).toBeHidden();estadoLocal.pending=1;
    await pagina.clock.fastForward(45000);await expect(pagina.locator('#reception-inbox')).toContainText('1 solicitud');
    await pagina.locator('#reception-inbox').click();await expect(pagina.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU10');
});

test('Paciente llega a reservas también desde la consulta de disponibilidad', async ({page: pagina}) => {
    await mockClinic(pagina,'Paciente');await pagina.goto('/#CU08');
    await selectBookingSlot(pagina,{consult:true});
    await expect(pagina.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU18');
    await expect(pagina.locator('.confirmation-ticket')).toContainText('10:30');
});

test('Recepción registra un menor sin cuenta ni correo y continúa la cita con esa misma ficha',async({page: pagina})=>{
    const estadoLocal=await mockClinic(pagina,'Recepcionista');await pagina.goto('/#CU09');
    await pagina.locator('#booking-register-patient').click();
    await expect(pagina.locator('#create-account')).not.toBeChecked();
    await pagina.getByLabel('Nombres',{exact:true}).fill('Menor');
    await pagina.getByLabel('Apellidos',{exact:true}).fill('Demostración');
    await pagina.getByLabel('Teléfono',{exact:true}).fill('70000022');
    await pagina.getByLabel('Fecha de nacimiento',{exact:true}).fill('2018-01-01');
    await pagina.getByRole('combobox',{name:'Sexo',exact:true}).selectOption('Femenino');
    await expect(pagina.locator('#create-account')).toBeDisabled();
    await pagina.getByRole('button',{name:'Guardar',exact:true}).click();
    await expect(pagina.locator('[data-pick="patient"][data-value="2"]')).toHaveAttribute('aria-pressed','true');
    await expect(pagina.locator('.flow-summary')).toContainText('Menor Demostración');
    expect(estadoLocal.calls.filter(c=>c.path==='patients/with-account')).toHaveLength(0);
    const peticion=estadoLocal.calls.find(c=>c.path==='entities/patients'&&c.method==='POST');
    expect(peticion.data.birth_date).toBe('2018-01-01');expect(peticion.data.email).toBe('');
    expect(peticion.data.password).toBeUndefined();expect(peticion.data.terms_accepted).toBeUndefined();
    await pagina.locator('#flow-next').click();await expect(pagina.locator('[data-pick="specialty"]')).toBeVisible();
});

test('la cita presencial exige selección y recepción del dinero antes de emitir la ficha',async({page: pagina})=>{
    const estadoLocal=await mockClinic(pagina,'Recepcionista');await pagina.goto('/#CU09');
    await pagina.locator('#flow-next').click();await expect(pagina.locator('#flow-error')).toContainText('Seleccione una ficha');
    await selectBookingSlot(pagina,{staff:true});
    await pagina.getByRole('button',{name:'Confirmar cita y emitir ficha',exact:true}).click();
    expect(estadoLocal.calls.filter(c=>c.path==='appointments'&&c.method==='POST')).toHaveLength(0);
    await pagina.locator('[name=payment_confirmed]').check();
    await pagina.screenshot({path:'storage/app/private/recorrido-cita-confirmacion.png',fullPage:true});
    await pagina.getByRole('button',{name:'Confirmar cita y emitir ficha',exact:true}).click();
    await expect(pagina.getByRole('dialog')).toContainText('No necesita crearle una cuenta');
    expect(estadoLocal.calls.find(c=>c.path==='appointments'&&c.method==='POST').data.payment_confirmed).toBe(true);
});

const hoy=()=>new Date().toLocaleDateString('en-CA',{timeZone:'America/La_Paz'});
const careAppointment=overrides=>({id:60,patient: paciente,doctor: medico,specialty: especialidad,patient_id:1,doctor_id:1,specialty_id:1,date:hoy(),start_time:'10:30',end_time:'11:00',status:'Programada',payment:{status:'confirmed'},...overrides});

test('la agenda de Recepción muestra la llegada como acción y después el siguiente paso',async({page: pagina})=>{
    const estadoLocal=await mockClinic(pagina,'Recepcionista',{appointments:[careAppointment({})]});await pagina.goto('/#agenda');
    await pagina.getByRole('button',{name:'Registrar asistencia',exact:true}).click();
    await expect(pagina.getByRole('dialog')).toContainText('Paciente Prueba');
    await pagina.getByRole('button',{name:'Guardar',exact:true}).click();
    await expect(pagina.locator('.stage-badge')).toContainText('En espera de atención');
    await expect(pagina.locator('.operation-success')).toContainText('lista de espera para el médico');
    await expect(pagina.getByRole('button',{name:'Finalizar consulta',exact:true})).toHaveCount(0);
    expect(estadoLocal.calls.some(c=>c.path==='appointments/60/attendance')).toBe(true);
    await pagina.screenshot({path:'storage/app/private/recorrido-agenda-llegada.png',fullPage:true});
});

test('el médico puede finalizar al paciente presente y no al que todavía no llegó',async({page: pagina})=>{
    const a=careAppointment({status:'Confirmada',attendance_at:new Date().toISOString(),care_ready:true});
    const estadoLocal=await mockClinic(pagina,'Médico',{appointments:[a,{...careAppointment({}),id:61}]});await pagina.goto('/#CU14');
    await expect(pagina.locator('[data-record="61"] [data-care="attend"]')).toHaveCount(0);
    await pagina.getByRole('button',{name:'Finalizar consulta',exact:true}).click();
    await pagina.getByRole('button',{name:'Guardar',exact:true}).click();
    await expect(pagina.locator('[data-record="60"]')).toContainText('Consulta finalizada');
    expect(estadoLocal.calls.find(c=>c.path==='appointments/60/attend').data.consultation_finished).toBe('1');
});

test('la solicitud del paciente conserva la cita y el identificador al enviar el diálogo',async({page: pagina})=>{
    const estadoLocal=await mockClinic(pagina,'Paciente',{appointments:[careAppointment({})]});await pagina.goto('/#CU10');
    await pagina.getByRole('button',{name:'Solicitar cambio',exact:true}).click();
    await pagina.getByLabel('Motivo y disponibilidad propuesta').fill('Solicito el día siguiente por la mañana.');
    await pagina.getByRole('button',{name:'Enviar solicitud a Recepción',exact:true}).click();
    await expect(pagina.locator('.request-card')).toContainText('Pendiente de Recepción');
    expect(estadoLocal.calls.find(c=>c.path==='appointments/60/reschedule-request').data.reason).toContain('día siguiente');
    expect(estadoLocal.appointments[0].status).toBe('Programada');
});

test('aprobar el reembolso no registra la entrega hasta confirmar sus datos',async({page: pagina})=>{
    const estadoLocal=await mockClinic(pagina,'Administrador',{refunds:[{id:5,appointment_id:60,status:'pending',amount_minor:10000,reason:'Cancelación de la clínica'}],appointments:[careAppointment({status:'Cancelada'})]});
    await pagina.goto('/#CU26');await pagina.getByRole('button',{name:'Aprobar',exact:true}).click();
    await pagina.getByRole('button',{name:'Aprobar y dejar devolución pendiente',exact:true}).click();
    await expect(pagina.locator('.refund-card')).toContainText('falta registrar la entrega');
    expect(estadoLocal.calls.some(c=>c.path==='refunds/5/complete')).toBe(false);
    await pagina.getByRole('button',{name:'Registrar dinero devuelto',exact:true}).click();
    await pagina.getByLabel('Fecha y hora de entrega',{exact:true}).fill('2026-11-08T08:00');
    await pagina.getByLabel('Medio utilizado',{exact:true}).fill('Efectivo de prueba');
    await pagina.getByRole('button',{name:'Registrar devolución realizada',exact:true}).click();
    expect(estadoLocal.calls.some(c=>c.path==='refunds/5/complete')).toBe(false);
    await pagina.locator('[name=delivery_confirmed]').check();
    await pagina.getByRole('button',{name:'Registrar devolución realizada',exact:true}).click();
    await expect(pagina.locator('.refund-card')).toContainText('Devolución registrada');
});

test('cerrar sesión limpia el diálogo y el borrador del paciente',async({page: pagina})=>{
    await mockClinic(pagina,'Recepcionista');await pagina.goto('/#CU09');
    await pagina.locator('[data-pick="patient"]').first().click();
    await pagina.locator('#logout-button').click();await pagina.locator('#confirm-action').click();
    await expect(pagina.locator('#login-form')).toBeVisible();
    await expect(pagina.locator('#modal-body')).toBeEmpty();
    await submitLogin(pagina);await pagina.goto('/#CU09');
    await expect(pagina.locator('[data-pick="patient"][aria-pressed="true"]')).toHaveCount(0);
});

async function expectReadableTheme(pagina) {
    const failures = await pagina.evaluate(() => {
        const rgb = color => color.match(/[\d.]+/g)?.map(Number);
        const luminance = color => rgb(color).slice(0,3).map(v => {
            const s = v / 255; return s <= .04045 ? s / 12.92 : ((s + .055) / 1.055) ** 2.4;
        }).reduce((suma,v,i) => suma + v * [.2126,.7152,.0722][i],0);
        return [...document.querySelectorAll('button,p,small,strong,span,label,td,th,dt,dd,a,h1,h2,h3,h4')]
            .filter(el => !el.children.length && el.textContent.trim() && el.getClientRects().length)
            .flatMap(el => {
                const estilo = getComputedStyle(el);
                let ancestor = el, background;
                do { background = getComputedStyle(ancestor).backgroundColor; ancestor = ancestor.parentElement; }
                while (ancestor && (rgb(background)?.[3] ?? 1) < 1);
                const a = luminance(estilo.color), b = luminance(background);
                const ratio = (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
                const large = parseFloat(estilo.fontSize) >= 24 || (parseFloat(estilo.fontSize) >= 18.66 && Number(estilo.fontWeight) >= 700);
                return ratio + .01 < (large ? 3 : 4.5) ? [{text:el.textContent.slice(0,70),ratio,color:estilo.color,background}] : [];
            });
    });
    expect(failures).toEqual([]);
}

test('tema: sigue al equipo hasta guardar una preferencia y la conserva entre páginas', async ({page: pagina, context: contexto}) => {
    await pagina.emulateMedia({colorScheme:'dark'});
    await mockClinic(pagina, null);
    await pagina.goto('/');
    await expect(pagina.locator('html')).toHaveAttribute('data-theme','dark');
    await expect(pagina.getByRole('button',{name:'Activar modo claro'})).toHaveAttribute('aria-pressed','true');
    await pagina.emulateMedia({colorScheme:'light'});
    await expect(pagina.locator('html')).toHaveAttribute('data-theme','light');
    await pagina.getByRole('button',{name:'Activar modo oscuro'}).click();
    await pagina.reload();
    await expect(pagina.locator('html')).toHaveAttribute('data-theme','dark');
    await pagina.goto('/terms');
    await expect(pagina.locator('html')).toHaveAttribute('data-theme','dark');
    const other = await contexto.newPage();
    await other.goto('/password/reset/theme-test?email=theme@example.test');
    await expect(other.locator('html')).toHaveAttribute('data-theme','dark');
    await other.getByRole('button',{name:'Activar modo claro'}).click();
    await expect(pagina.locator('html')).toHaveAttribute('data-theme','light');
    await pagina.emulateMedia({colorScheme:'dark'});
    await expect(pagina.locator('html')).toHaveAttribute('data-theme','light');
    await other.close();
});

test('tema: funciona sin almacenamiento y se puede activar con teclado', async ({page: pagina}) => {
    await pagina.emulateMedia({colorScheme:'light'});
    await pagina.addInitScript(() => {
        Object.defineProperty(window,'localStorage',{get(){throw new DOMException('Blocked','SecurityError');}});
    });
    await mockClinic(pagina,null);
    await pagina.goto('/');
    const toggle = pagina.getByRole('button',{name:'Activar modo oscuro'});
    await toggle.focus();
    await pagina.keyboard.press('Enter');
    await expect(pagina.locator('html')).toHaveAttribute('data-theme','dark');
    await pagina.emulateMedia({colorScheme:'dark'});
    await pagina.emulateMedia({colorScheme:'light'});
    await expect(pagina.locator('html')).toHaveAttribute('data-theme','dark');
    await expect(pagina.locator('#login-form')).toBeVisible();
});

test('tema: acceso y páginas de correo mantienen el contraste y el tema guardado', async ({page: pagina}) => {
    await pagina.emulateMedia({colorScheme:'dark'});
    await mockClinic(pagina,null);
    for (const ruta of ['/', '/terms', '/password/reset/theme-test?email=theme@example.test']) {
        await pagina.goto(ruta);
        await expect(pagina.locator('[data-theme-toggle]')).toHaveAttribute('aria-pressed','true');
        await expectReadableTheme(pagina);
    }
    await pagina.goto('/');
    await pagina.screenshot({path:'storage/app/private/tema-oscuro-acceso.png',fullPage:true});
});

for (const width of [360,1440]) test(`tema: pantallas, diálogo e impresión a ${width}px`, async ({page: pagina}) => {
    await pagina.setViewportSize({width,height:1000});
    await pagina.emulateMedia({colorScheme:'dark'});
    await mockClinic(pagina,'Superadmin');
    await pagina.goto('/');
    await expect(pagina.locator('.welcome-panel')).toBeVisible();
    await expect(pagina.locator('[data-theme-toggle]')).toBeVisible();
    await expect(pagina.locator('html')).toHaveCSS('background-color','rgb(16, 28, 37)');
    await expectReadableTheme(pagina);
    expect(await pagina.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await pagina.screenshot({path:`storage/app/private/tema-oscuro-inicio-${width}.png`,fullPage:true});
    await pagina.goto('/#CU03');
    await expect(pagina.locator('table')).toBeVisible();
    await expect(pagina.locator('td').first()).toHaveCSS('color','rgb(215, 228, 236)');
    await expectReadableTheme(pagina);
    expect(await pagina.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await pagina.screenshot({path:`storage/app/private/tema-oscuro-usuarios-${width}.png`,fullPage:true});
    await pagina.locator('[data-edit="1"]').click();
    await expect(pagina.getByRole('dialog')).toHaveCSS('background-color','rgb(25, 42, 54)');
    await expect(pagina.getByRole('dialog').locator('input').first()).toHaveCSS('color','rgb(215, 228, 236)');
    await pagina.screenshot({path:`storage/app/private/tema-oscuro-dialogo-${width}.png`,fullPage:true});
    await pagina.locator('#close-modal').click();
    await pagina.emulateMedia({media:'print'});
    await expect(pagina.locator('[data-theme-toggle]')).toBeHidden();
    await expect(pagina.locator('html')).toHaveCSS('background-color','rgb(245, 247, 250)');
});

for(const width of [360,1440])test(`tema: recorrido completo de cita a ${width}px sin desbordamiento`,async({page: pagina})=>{
    await pagina.emulateMedia({colorScheme:'dark'});
    await pagina.setViewportSize({width,height:900});await mockClinic(pagina,'Recepcionista');await pagina.goto('/#CU09');
    for(const paso of ['patient','specialty']){
        await expectReadableTheme(pagina);
        await pagina.locator(`[data-pick="${paso}"]`).first().click();
        expect(await pagina.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        await pagina.locator('#flow-next').click();
    }
    await expect(pagina.locator('[data-flow-slot]').first()).toBeVisible();
    await expectReadableTheme(pagina);
    expect(await pagina.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await pagina.locator('[data-flow-slot]').first().click();
    await expect(pagina.locator('.confirmation-ticket')).toBeVisible();
    await expectReadableTheme(pagina);
    expect(await pagina.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await pagina.screenshot({path:`storage/app/private/tema-oscuro-cita-${width}.png`,fullPage:true});
});
