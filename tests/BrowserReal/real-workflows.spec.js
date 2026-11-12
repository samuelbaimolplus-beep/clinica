import {test as base,expect} from '@playwright/test';
import {readFileSync,writeFileSync,existsSync} from 'node:fs';
const fixture=()=>JSON.parse(readFileSync('storage/app/private/browser-real-fixture.json','utf8'));
async function login(page,role){
 const f=fixture();await page.goto('/');await page.locator('#email').fill(f.users[role].username);await page.locator('#password').fill(f.password);
 await page.getByRole('button',{name:'Iniciar sesión',exact:true}).click();await expect(page.locator('[data-home]')).toBeVisible();
}
const test=base.extend({roles:[async({browser},use)=>{
 const roles={};for(const role of ['super','admin','reception','doctor','patient']){const path=`storage/app/private/browser-real-${fixture().run}-${role}.json`;const context=await browser.newContext({locale:'es-BO',...(existsSync(path)?{storageState:path}:{})});const page=await context.newPage();if(existsSync(path)){await page.goto('/');await expect(page.locator('[data-home]')).toBeVisible();}else{await login(page,role);await context.storageState({path});}roles[role]=page;}
 const saved=runtime();newSpecialty=saved.newSpecialty;newDoctor=saved.newDoctor;refundId=saved.refundId;createdPatient=saved.createdPatient;
 await use(roles);for(const page of Object.values(roles))await page.context().close();
},{scope:'worker',timeout:120000}]});
let activePage,newDoctor,newSpecialty,refundId,createdPatient;
const runtimePath=()=>`storage/app/private/browser-real-${fixture().run}-runtime.json`;
const runtime=()=>existsSync(runtimePath())?JSON.parse(readFileSync(runtimePath(),'utf8')):{};
const nav=async(page,cu)=>{activePage=page;await page.goto('/#'+cu);await expect(page.locator('.nav-btn.selected')).toHaveAttribute('data-view',cu);await expect(page.locator('#main')).not.toHaveAttribute('aria-busy','true');await expect(page.locator('#retry')).toHaveCount(0);};
async function save(page,form,path,method='POST'){
 const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/'+path&&r.request().method()===method);
 await page.locator(form+' button[type=submit]').click();const r=await response;expect(r.status(),await r.text()).toBeLessThan(300);return r.json();
}
const card=(page,key)=>page.locator(`[data-record="${fixture().appointments[key]}"]`);
test.afterEach(async({},info)=>{writeFileSync(runtimePath(),JSON.stringify({...runtime(),...Object.fromEntries(Object.entries({newDoctor,newSpecialty,refundId,createdPatient}).filter(([,v])=>v!==undefined))}));if(activePage&&!activePage.isClosed())await activePage.screenshot({path:`storage/app/private/browser-real-results/${info.title.replace(/[^a-zA-Z0-9_-]/g,'_')}.png`,fullPage:true});});

test('CU01 real: acceso de los cinco roles con sesión persistente',async({roles})=>{
 for(const [role,page]of Object.entries(roles)){activePage=page;await page.reload();await expect(page.locator('#profile')).toContainText(fixture().users[role].username.split('.').at(-1));}
});
test('CU05 real: crear y modificar especialidad desde Administración',async({roles})=>{
 const p=roles.admin;await nav(p,'CU05');await p.locator('#new-entity').click();
 await p.locator('[name=name]').fill('Especialidad adicional '+fixture().run);await p.locator('[name=fee_amount]').fill('80');
 newSpecialty=await save(p,'#entity-form','entities/specialties');await expect(p.getByRole('dialog')).not.toBeVisible();
 await p.locator(`[data-edit="${newSpecialty.id}"]`).click();await p.locator('#entity-form [name=description]').fill('Servicio ficticio de navegador');await save(p,'#entity-form','entities/specialties/'+newSpecialty.id,'PUT');
 await p.reload();await expect(p.locator('#records')).toContainText(newSpecialty.name);
});
test('CU04 real: crear médico y vincular especialidad desde Administración',async({roles})=>{
 const p=roles.admin;await nav(p,'CU04');await p.locator('#new-entity').click();
 for(const [name,value]of Object.entries({registration:fixture().run+'extra',first_name:'Profesional',last_name:'Adicional',phone:'70000007',email:'extra.'+fixture().run+'@example.test'}))await p.locator(`#entity-form [name=${name}]`).fill(value);
 await p.locator(`[name=specialty_ids][value="${newSpecialty.id}"]`).check();newDoctor=await save(p,'#entity-form','entities/doctors');await p.reload();await expect(p.locator('#records')).toContainText('Profesional Adicional');
});
test('CU03 real: alta de personal adulto y validación de campos',async({roles})=>{
 const p=roles.super;await nav(p,'CU03');await p.locator('#new-entity').click();await p.locator('#entity-form button[type=submit]').click();await expect(p.getByRole('dialog')).toBeVisible();
 for(const [name,value]of Object.entries({birth_date:'1990-01-01',username:fixture().run+'.nuevo',name:'Personal navegador',email:'nuevo.'+fixture().run+'@example.test',password:fixture().password}))await p.locator(`#entity-form [name=${name}]`).fill(value);
 await p.locator('#role').selectOption('Recepcionista');const result=await save(p,'#entity-form','entities/users');expect(result.email_verified_at).toBeNull();await p.reload();await expect(p.locator('#records')).toContainText(fixture().run+'.nuevo');
});
test('CU06 real: menor sin cuenta ni correo, consulta y modificación de ficha',async({roles})=>{
 const p=roles.reception;await nav(p,'CU06');await p.locator('#new-entity').click();await expect(p.locator('#create-account')).not.toBeChecked();
 for(const [name,value]of Object.entries({first_name:'Infante',last_name:fixture().run,phone:'70000009',birth_date:'2018-01-01'}))await p.locator(`#entity-form [name=${name}]`).fill(value);
 await p.locator('#birth_date').blur();await expect(p.locator('#create-account')).toBeDisabled();createdPatient=await save(p,'#entity-form','entities/patients');expect(createdPatient.email).toBeFalsy();
 await p.locator(`[data-show="${createdPatient.id}"]`).click();await expect(p.getByRole('dialog')).toContainText('Infante');await p.locator('#close-modal').click();
 await p.locator(`[data-edit="${createdPatient.id}"]`).click();await p.locator('#entity-form [name=phone]').fill('70000019');await save(p,'#entity-form','entities/patients/'+createdPatient.id,'PUT');await p.reload();await expect(p.locator('#records')).toContainText('70000019');
});
test('CU07 real: horario, cruce, cupos y capacidad desde Administración',async({roles})=>{
 const p=roles.admin;await nav(p,'CU07');await p.locator('#schedule-doctor').selectOption(String(newDoctor.id));await p.locator('[data-new-shift="1"]').click();
 await p.locator('#schedule-form [name=start_time]').fill('08:00');await p.locator('#schedule-form [name=end_time]').fill('12:00');await save(p,'#schedule-form','schedules');await expect(p.locator('.week-grid')).toContainText('08:00');
 await p.locator('[data-new-shift="1"]').click();await p.locator('#schedule-form [name=start_time]').fill('09:00');await p.locator('#schedule-form [name=end_time]').fill('11:00');await p.locator('#schedule-form button[type=submit]').click();await expect(p.locator('#schedule-conflicts')).toContainText('Se cruza');await p.locator('#close-modal').click();
 await p.locator('[data-tab=quota]').click();await p.locator('#edit-quota').click();await p.locator('[name=presential_percent]').fill('60');await p.locator('[name=online_percent]').fill('40');await save(p,'#quota-form','doctor-quotas','PUT');
 await p.locator('#capacity-form [name=date]').fill(fixture().tomorrow);await save(p,'#capacity-form','schedules/capacity','GET');await expect(p.locator('#capacity-result')).toContainText('Capacidad');
});
test('CU08 real: consultar disponibilidad conduce a programar sin crear una cita',async({roles})=>{
 const p=roles.reception;await nav(p,'CU08');await p.locator(`[data-pick=specialty][data-value="${fixture().specialty.id}"]`).click();await p.locator('#flow-next').click();
 await p.locator('#booking-date').fill(fixture().tomorrow);await p.locator('[data-flow-slot]').first().click();await expect(p.locator('.nav-btn.selected')).toHaveAttribute('data-view','CU09');await expect(p.locator('[data-pick=patient]')).not.toHaveCount(0);
});
async function chooseSlot(p,{followup=false,online=false}={}){
 if(!online&&!followup){await p.locator(`[data-pick=patient][data-value="${fixture().child.id}"]`).click();await p.locator('#flow-next').click();}
 if(!followup){await p.locator(`[data-pick=specialty][data-value="${fixture().specialty.id}"]`).click();await p.locator('#flow-next').click();await p.locator('#booking-date').fill(fixture().tomorrow);}
 await p.locator(`[data-doctor="${fixture().doctor.id}"]`).click();await p.locator('[data-flow-slot]').first().click();await expect(p.locator('.confirmation-ticket')).toBeVisible();
}
test('CU09 CU19 real: cita presencial, cobro verificado y ficha persistente',async({roles})=>{
 const p=roles.reception;await nav(p,'CU09');await chooseSlot(p);await p.locator('[name=payment_confirmed]').check();const a=await save(p,'#guided-booking','appointments');
 await expect(p.getByRole('dialog')).toContainText('Ficha CIT-');await expect(p.getByRole('dialog')).toContainText('Efectivo');await p.locator('#close-modal').click();await nav(p,'CU15');await expect(p.locator(`[data-record="${a.id}"]`)).toBeVisible();
 await nav(roles.super,'CU19');await expect(roles.super.locator('#main')).toContainText('Confirmado');
});
test('CU10 real: reprogramar una cita con acuerdo desde Recepción',async({roles})=>{
 const p=roles.reception;await nav(p,'CU10');await card(p,'reschedule').locator('[data-change]').click();
 await expect(p.locator('#clinical-reschedule [name=start_time] option')).not.toHaveCount(1);await p.locator('#clinical-reschedule [name=start_time]').selectOption({index:1});const selected=await p.locator('#clinical-reschedule [name=start_time]').inputValue();await p.locator('[name=change_reason]').fill('Acuerdo de prueba por llamada');await p.locator('[name=patient_accepted]').check();await save(p,'#clinical-reschedule',`appointments/${fixture().appointments.reschedule}/reschedule`,'PUT');await p.reload();await expect(card(p,'reschedule')).toContainText(selected);
});
test('CU11 real: cancelar por causa de clínica sin borrar la cita',async({roles})=>{
 const p=roles.reception;await nav(p,'CU11');await card(p,'cancel').locator('summary').click();await card(p,'cancel').locator('[data-cancel-record]').click();await p.locator('#clinical-operation [name=cause]').selectOption('clinic');await p.locator('#clinical-operation [name=reason]').fill('Cancelación ficticia de la clínica');await save(p,'#clinical-operation',`appointments/${fixture().appointments.cancel}/cancel`);await nav(p,'CU15');await expect(card(p,'cancel')).toContainText('Cancelada');
});
test('CU12 real: médico consulta su agenda y no ve acciones de Recepción',async({roles})=>{
 const p=roles.doctor;await nav(p,'CU12');await expect(card(p,'consult')).toBeVisible();await expect(p.locator('[data-care=attendance]')).toHaveCount(0);await expect(p.locator('[data-cancel-record]')).toHaveCount(0);
});
test('CU13 real: Recepción registra asistencia desde navegador y persiste al recargar',async({roles})=>{
 const page=roles.reception,f=fixture();await nav(page,'CU13');
 const card=page.locator(`[data-record="${f.appointments.arrival}"]`);await expect(card).toContainText(f.child.name);
 await card.locator('[data-care="attendance"]').click();
 const response=page.waitForResponse(r=>r.url().endsWith(`/api/appointments/${f.appointments.arrival}/attendance`)&&r.request().method()==='POST');
 await page.locator('#clinical-operation button[type=submit]').click();const r=await response;
 expect(r.status(),await r.text()).toBe(200);
 await expect(card).toContainText('En espera de atención');await page.reload();await expect(card).toContainText('En espera de atención');
 await page.screenshot({path:'storage/app/private/asistencia-real-recepcion.png',fullPage:true});
});
test('CU13 real: inasistencia definitiva desde Recepción',async({roles})=>{
 const p=roles.reception;await nav(p,'CU13');await card(p,'absent').locator('summary').click();await card(p,'absent').locator('[data-care=absent]').click();await p.locator('[name=definitive]').check();await p.locator('#clinical-operation [name=reason]').fill('Se confirmó que no acudirá');await save(p,'#clinical-operation',`appointments/${fixture().appointments.absent}/absent`);await p.reload();await expect(card(p,'absent')).toContainText('No asistió');
});
test('CU14 real: médico finaliza consulta y registra seguimiento',async({roles})=>{
 const p=roles.doctor;await nav(p,'CU14');await card(p,'consult').locator('[data-care=attend]').click();await p.locator('[name=followup_suggested_at]').fill(fixture().tomorrow+'T11:00');await p.locator('[name=followup_note]').fill('Control indicado de demostración');await save(p,'#clinical-operation',`appointments/${fixture().appointments.consult}/attend`);await p.reload();await expect(card(p,'consult')).toContainText('Consulta finalizada');
});
test('Superadmin real: registra asistencia y finaliza consulta de cualquier médico',async({roles})=>{
 const p=roles.super;await nav(p,'CU13');await card(p,'superconsult').locator('[data-care=attendance]').click();await save(p,'#clinical-operation',`appointments/${fixture().appointments.superconsult}/attendance`);
 await card(p,'superconsult').locator('[data-care=attend]').click();await save(p,'#clinical-operation',`appointments/${fixture().appointments.superconsult}/attend`);await p.reload();await expect(card(p,'superconsult')).toContainText('Consulta finalizada');
});
test('CU15 real: historial y detalle del paciente',async({roles})=>{
 const p=roles.patient;await nav(p,'CU15');await card(p,'consult').locator('[data-record-detail]').click();await expect(p.getByRole('dialog')).toContainText('Atendida');await expect(p.getByRole('dialog')).toContainText('Control indicado');await p.locator('#close-modal').click();
});
test('CU16 real: generar los siete reportes y ofrecer CSV',async({roles})=>{
 test.setTimeout(120000);
 const p=roles.admin;await nav(p,'CU16');
 for(const type of ['REP01','REP02','REP03','REP04','REP05','REP06','REP07']){
  const response=['REP01','REP05'].includes(type)?p.waitForResponse(r=>r.url().includes('/api/reports?')&&r.url().includes(type)):null;
  await p.locator(`[data-report-choice="${type}"]`).click();
  if(!response){if(['REP02','REP07'].includes(type))await p.locator('#doctor_id').selectOption(String(fixture().doctor.id));if(type==='REP03')await p.locator('#specialty_id').selectOption(String(fixture().specialty.id));await save(p,'#report-form','reports','GET');}
  else {const r=await response;expect(r.status(),await r.text()).toBe(200);}
  await expect(p.locator('#report-result')).toContainText('Descargar CSV');
 }
 const download=p.waitForEvent('download');await p.getByRole('link',{name:'Descargar CSV'}).click();expect((await download).suggestedFilename()).toMatch(/\.csv$/);
});
test('CU17 real: ausencia con vista previa y citas afectadas operables',async({roles})=>{
 const p=roles.admin;await nav(p,'CU07');await p.locator('#schedule-doctor').selectOption(String(fixture().doctor.id));await p.locator('[data-tab=block]').click();await p.locator('#new-unavailability').click();
 await p.locator('[name=starts_at]').fill(fixture().tomorrow+'T10:00');await p.locator('[name=ends_at]').fill(fixture().tomorrow+'T10:15');await p.locator('#preview-block').click();await expect(p.locator('#affected-preview')).toContainText('1 citas afectadas');await save(p,'#unavailable-form','unavailabilities');
 await nav(roles.reception,'CU17');const row=roles.reception.getByRole('row').filter({has:roles.reception.locator(`[data-impact="${fixture().appointments.impact}"]`)});await expect(row).toContainText(fixture().patient.name);await row.locator('[data-impact]').click();await expect(roles.reception.getByRole('dialog')).toContainText('Indisponibilidad');await roles.reception.locator('#close-modal').click();await row.locator('[data-impact-change]').click();await expect(roles.reception.locator('#clinical-reschedule')).toBeVisible();await roles.reception.locator('#close-modal').click();
});
test('CU20 real: Recepción solicita evaluación de reembolso para paciente sin depender de su cuenta',async({roles})=>{
 const p=roles.reception;await nav(p,'CU20');await p.locator(`[data-request-refund="${fixture().appointments.cancel}"]`).click();await p.locator('#refund-request [name=reason]').fill('Cancelación de prueba de la clínica');const r=await save(p,'#refund-request',`appointments/${fixture().appointments.cancel}/refund`);refundId=r.id;await expect(p.getByRole('dialog')).toContainText('Solicitud enviada');await p.locator('#close-modal').click();
});
test('CU26 real: aprobar y registrar la entrega después de la autorización',async({roles})=>{
 const p=roles.admin;await nav(p,'CU26');await p.locator(`[data-approve-refund="${refundId}"]`).click();await save(p,'#refund-decision',`refunds/${refundId}/decision`);await p.locator(`[data-complete-refund="${refundId}"]`).click();await p.locator('[name=method]').fill('Efectivo de prueba');await p.locator('[name=delivery_confirmed]').check();await save(p,'#refund-complete',`refunds/${refundId}/complete`);await p.reload();await expect(p.locator('#refund-cards')).toContainText('Devolución registrada');
});
test('CU21 real: programar seguimiento desde consulta finalizada',async({roles})=>{
 const p=roles.reception;await nav(p,'CU15');await card(p,'followup').locator('[data-followup]').click();await expect(p.locator('#booking-date')).toHaveValue(fixture().tomorrow);await p.locator('[data-flow-slot]').filter({has:p.locator('strong').filter({hasText:/^09:45$/})}).click();await expect(p.locator('.confirmation-ticket')).toContainText('Sin costo');await save(p,'#guided-booking','appointments');await expect(p.getByRole('dialog')).toContainText('Exención válida');await p.locator('#close-modal').click();
});
test('CU22 real: consultar evento, IP y filtros desde la bitácora',async({roles})=>{
 const p=roles.admin;await nav(p,'CU22');await expect(p.locator('#main')).toContainText('Dirección IP');await expect(p.locator('#main')).toContainText('127.0.0.1');await p.locator('[data-audit]').first().click();await expect(p.getByRole('dialog')).toContainText('127.0.0.1');await p.locator('#close-modal').click();
 await p.locator('.advanced-options summary').click();await p.locator('[name=ip_address]').fill('127.0.0.1');await save(p,'#audit-form','audit','GET');await expect(p.locator('#main')).toContainText('127.0.0.1');
});
test('CU23 real: habilitar portal opcional con consentimiento y activar por código de correo simulado',async({roles,browser})=>{
 const p=roles.reception;await nav(p,'CU06');await p.locator('#new-entity').click();
 const email='portal.'+fixture().run+'@example.test';
 for(const [name,value]of Object.entries({first_name:'Adulto',last_name:fixture().run,phone:'70000004',birth_date:'1990-01-01'}))await p.locator(`#entity-form [name=${name}]`).fill(value);
 await p.locator('#create-account').check();await p.locator('#entity-form [name=email]').fill(email);await p.locator('#entity-form [name=username]').fill(fixture().run+'.portal');await p.locator('#entity-form [name=password]').fill(fixture().password);await p.locator('#entity-form [name=password_confirmation]').fill(fixture().password);await p.locator('[name=identity_verified]').check();await p.locator('[name=terms_accepted]').check();await save(p,'#entity-form','patients/with-account');await expect(p.locator('#records')).toContainText('Pendiente de código');
 const code=JSON.parse(readFileSync('storage/app/private/browser-real-mail.json','utf8'))[email];expect(code).toMatch(/^\d{8}$/);
 const context=await browser.newContext();const guest=await context.newPage();await guest.goto('/#activar-cuenta');await guest.locator('#activation-form [name=email]').fill(email);await guest.locator('#activation-form [name=code]').fill(code);await save(guest,'#activation-form','account/activate');await expect(guest.locator('#login-form')).toBeVisible();await context.close();
 await nav(roles.super,'CU23');await expect(roles.super.locator('#main')).toContainText(fixture().run+'.portal');await expect(roles.super.locator('#main')).toContainText('Correo verificado');
});
test('CU24 real: proponer, confirmar y liberar bloques desde Recepción',async({roles})=>{
 const p=roles.reception;await nav(p,'CU24');await p.locator(`[data-demand-specialty="${fixture().specialty.id}"]`).click();await expect(p.locator('#specialty_id')).toHaveValue(String(fixture().specialty.id));await save(p,'#demand-proposal-form','agenda-adjustments/proposal');
 await expect(p.locator('#confirm-demand-form [name=blocks]')).not.toHaveCount(0);await save(p,'#confirm-demand-form','agenda-adjustments');await p.locator('#release-blocks-form input[type=checkbox]').first().check();await p.locator('#release-blocks-form button[type=submit]').click();await p.locator('#confirm-action').click();await expect(p.getByRole('dialog')).not.toBeVisible();await p.reload();await expect(p.locator('#release-blocks-form')).toContainText('Liberado');
});
test('CU25 real: habilitar un cupo protegido online desde Recepción',async({roles})=>{
 const p=roles.reception;await nav(p,'CU25');await p.locator(`[data-open-doctor="${fixture().openingDoctor.id}"]`).click();
 await expect(p.locator('#protected-options')).not.toBeEmpty();
 const checks=p.locator('#open-online-form [name=blocks]');
 if(await p.locator('#protected-options').getByText('No hay franjas protegidas libres',{exact:false}).count()){test.info().annotations.push({type:'restricción',description:'La franja protegida ya pasó hoy; se comprueba estado vacío sin falsificar la fecha.'});await expect(p.locator('#protected-options')).toContainText('No hay franjas');return;}
 await checks.first().check();await save(p,'#open-online-form','agenda-adjustments');await p.reload();await expect(p.locator('#main')).toContainText(fixture().openingDoctor.name);
});
test('CU18 real: reserva y ficha confirmada con proveedor externo simulado',async({roles})=>{
 test.setTimeout(90000);
 const p=roles.patient;await p.route('https://www.sandbox.paypal.com/checkoutnow**',async route=>{const token=new URL(route.request().url()).searchParams.get('token');await route.fulfill({contentType:'text/html',body:`<h1>Simulación externa de PayPal Sandbox</h1><p>No se mueve dinero.</p><a href="http://127.0.0.1:8012/__qa/approve?token=${token}">Autorizar pago de prueba</a>`});});
 await nav(p,'CU18');await chooseSlot(p,{online:true});await save(p,'#guided-booking','online-bookings');await expect(p.getByRole('dialog')).toContainText('Pago con PayPal Sandbox');await p.getByRole('link',{name:'Continuar en PayPal Sandbox'}).click();await p.getByRole('link',{name:'Autorizar pago de prueba'}).click();await expect(p.getByRole('dialog')).toContainText('Ficha CIT-',{timeout:70000});await p.locator('#close-modal').click();await p.reload();await expect(p.locator('#retry')).toHaveCount(0);
});
test('CU02 real: cambiar datos propios y cerrar sesión impide recuperar la pantalla privada',async({roles})=>{
 const p=roles.patient;await nav(p,'CU02');await p.locator('#my-profile-form [name=phone]').fill('70000088');await p.locator('[name=profile_password]').fill(fixture().password);await save(p,'#my-profile-form','account/profile','PATCH');await p.reload();await expect(p.locator('#my-profile-form [name=phone]')).toHaveValue('70000088');
 await p.locator('#logout-button').click();await p.locator('#confirm-action').click();await expect(p.locator('#login-form')).toBeVisible();await p.reload();await expect(p.locator('#login-form')).toBeVisible();await expect(p.locator('#modal-body')).toBeEmpty();
});
