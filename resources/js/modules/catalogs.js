/** CU03–CU06: directorios y altas en contexto. La ficha de paciente es independiente de una cuenta opcional. */
export function installCatalogViews(ui) {
    const { $, state, views, api, esc, full, code, badge, field, select, textarea, personOptions, localDate, table, button, modal, formErrors, actionButtons, on, closeModal, bindForm, references, navigate, toast, confirmAction, panel, details, notice, time, getTermsVersion } = ui;
const adultCutoff=()=>{const [year,month,day]=localDate().split('-').map(Number);const lastDay=new Date(Date.UTC(year-18,month,0)).getUTCDate();return new Date(Date.UTC(year-18,month-1,Math.min(day,lastDay),12)).toISOString().slice(0,10);};
// CU03–CU06: registros persistentes y activación sin eliminar información.
const configs = {
    users:{title:'Usuarios registrados',new:'Registrar usuario',headers:['Usuario','Correo','Nombre','Rol','Médico vinculado','Estado','Activación'], row:r=>[esc(r.username),esc(r.email),esc(r.name),esc(r.role),esc(full(state.refs.doctors.find(d=>d.id===r.doctor_id))),badge(r.status),esc(r.email_verified_at?'Correo confirmado':'Pendiente de código')]},
    doctors:{title:'Médicos registrados',new:'Registrar médico',headers:['Código','N.º registro','Médico','Teléfono','Especialidades','Estado'],row:r=>[code('MED',r.id),esc(r.registration),esc(full(r)),esc(r.phone),esc(r.specialties.map(s=>s.name).join(', ')),badge(r.status)]},
    specialties:{title:'Especialidades',new:'Registrar especialidad',headers:['Código','Nombre','Descripción','Estado'],row:r=>[code('ESP',r.id),esc(r.name),esc(r.description||'—'),badge(r.status)]},
    patients:{title:'Pacientes registrados',new:'Registrar paciente',headers:['Código','CI','Paciente','Nacimiento','Sexo','Teléfono','Estado','Cuenta de acceso'],row:r=>[code('PAC',r.id),esc(r.identity),esc(full(r)),esc(r.birth_date),esc(r.sex),esc(r.phone),badge(r.status),r.account?`${esc(r.account.username)} · ${r.account.email_verified_at?'Activada':'Pendiente de código'}`:'Portal opcional no solicitado']},
};
function accountFields(patient={},includeEmail=true) {
    return (includeEmail?field('Correo para activar la cuenta','email','email',patient.email,'required maxlength="255" autocomplete="off"'):'')
        +field('Nombre de usuario','username','text','','required minlength="3" maxlength="50" autocomplete="off"')
        +field('Contraseña inicial','password','password','','required minlength="10" maxlength="128" autocomplete="new-password"')
        +field('Repetir contraseña inicial','password_confirmation','password','','required minlength="10" maxlength="128" autocomplete="new-password"')
        +`<label class="terms-accept full"><input name="identity_verified" type="checkbox" value="1" required>He verificado presencialmente la identidad del paciente y revisado su correo de contacto.</label>`
        +`<label class="terms-accept full"><input name="terms_accepted" type="checkbox" value="1" required>El paciente adulto solicitó su cuenta y aceptó los <a href="/terms" target="_blank" rel="noopener">términos y condiciones</a> vigentes.</label><input type="hidden" name="terms_version" value="${esc(getTermsVersion())}">`
        +`<div class="full">${notice('Se enviará un código al correo del paciente. La cuenta permanecerá bloqueada hasta que lo confirme desde «Activar mi cuenta». Entregue las credenciales únicamente al titular.')}</div>`;
}
function entityFields(entity,r={}) {
    const req='required maxlength="100"';
    const person = field('Nombres','first_name','text',r.first_name,req)+field('Apellidos','last_name','text',r.last_name,'required maxlength="150"')+field('Teléfono','phone','tel',r.phone,'required maxlength="30"');
    let body='';
    if(entity==='users') {
        body = field('Nombre de usuario','username','text',r.username,'required minlength="3" maxlength="50" autocomplete="off" title="Letras, numeros, punto, guion y guion bajo; sin espacios."')+field('Nombre completo','name','text',r.name,'required maxlength="150"')+field('Correo electrónico','email','email',r.email,'required maxlength="255" autocomplete="off"')+field(r.id?'Nueva contraseña (vacía para conservar)':'Contraseña (mínimo 10 caracteres)','password','password','','minlength="10" maxlength="128" autocomplete="new-password" '+(r.id?'':'required'))+select('Rol','role',(state.user.role==='Superadmin'?['Superadmin','Administrador','Recepcionista','Médico']:['Administrador','Recepcionista','Médico']),r.role||'Recepcionista')+`<div id="doctor-link">${select('Médico vinculado','doctor_id',[['','Seleccione un médico…'],...personOptions(state.refs.doctors.filter(d=>d.status==='Activo'||d.id===r.doctor_id))],r.doctor_id)}</div>`;
    } else if(entity==='doctors') {
        body=field('Código de médico','code','text',r.id?code('MED',r.id):'Automático al guardar','readonly')+field('Número de registro profesional','registration','text',r.registration,'required maxlength="80"')+person+field('Correo electrónico','email','email',r.email,'required maxlength="255"')+`<div class="full"><span class="label">Especialidades activas *</span><div class="checks">${state.refs.specialties.filter(s=>s.status==='Activo').map(s=>`<label><input type="checkbox" name="specialty_ids" value="${s.id}" ${r.specialties?.some(x=>x.id===s.id)?'checked':''}>${esc(s.name)}</label>`).join('')||'<small>Registre una especialidad activa antes de crear médicos.</small>'}</div>${r.specialties?.some(s=>s.status==='Inactivo')?notice('Este médico conserva relaciones con especialidades inactivas. Al guardar la edición, seleccione sus especialidades activas; se identificarán las citas afectadas por las retiradas.') : ''}</div>`;
    } else if(entity==='specialties')body=field('Código','code','text',r.id?code('ESP',r.id):'Automático al guardar','readonly')+field('Nombre de especialidad','name','text',r.name,'required maxlength="120"')+textarea('Descripción (opcional)','description',r.description,'maxlength="3000"')+field('Tarifa de consulta (Bs)','fee_amount','number',r.fee_minor==null?'':(r.fee_minor/100).toFixed(2),'min="0.01" max="999999.99" step="0.01"');
    else body=field('Código de paciente','code','text',r.id?code('PAC',r.id):'Automático al guardar','readonly')+field('Documento de identidad (CI, opcional)','identity','text',r.identity,'maxlength="40"')+person+field('Fecha de nacimiento','birth_date','date',r.birth_date,`required max="${localDate()}"`)+select('Sexo','sex',['Femenino','Masculino'],r.sex)+field('Correo electrónico (opcional)','email','email',r.email,'maxlength="255"')+textarea('Dirección (opcional)','address',r.address,'maxlength="1000"');
    if(entity==='users')body=field('Fecha de nacimiento','birth_date','date',r.birth_date||'',`required max="${adultCutoff()}"`)+body;
    if (!r.id && ['doctors','specialties','patients'].includes(entity)) {
        return body+'<input type="hidden" name="status" value="Activo">'+notice('El registro se creará activo.');
    }
    return body+select('Estado','status',['Activo','Inactivo'],r.status||'Activo');
}
async function crudView(entity) {
    const rows=await api(`entities/${entity}`), config=configs[entity];
    const rowsHtml = items=>table([...config.headers,'Acciones'],items.map(r=>[...config.row(r),(button('Consultar',`data-show="${r.id}"`,'link'))+(entity==='patients'&&ui.allowedViews().includes('CU09')&&r.status==='Activo'?button('Programar cita',`data-patient-book="${r.id}"`,'success'):'')+(entity==='patients'&&ui.allowedViews().includes('CU15')?button('Ver citas',`data-patient-history="${r.id}"`,'link'):'')+button('Modificar',`data-edit="${r.id}"`,'link')+(entity==='doctors'?button('Ver horarios',`data-doctor-schedule="${r.id}"`,'link'):'')+button(r.status==='Activo'?'Desactivar':'Activar',`data-toggle="${r.id}"`,'link')+(entity==='patients'&&r.status==='Activo'&&!r.account&&r.birth_date&&r.birth_date<=adultCutoff()?button('Habilitar portal opcional',`data-create-account="${r.id}"`,'link'):'')]));
    function impactHtml(record) {
        const affected = record.affected_appointments || [];
        if (!affected.length) return '';
        return panel('Citas futuras afectadas', notice('Las citas conservan su estado y quedan pendientes de gestión por recepción. No se cancelaron automáticamente.')+
            table(['Código','Fecha','Horario','Estado','Motivo'],affected.map(appointment=>[
                esc(code('CIT',appointment.id)),esc(appointment.date),`${esc(time(appointment.start_time))}–${esc(time(appointment.end_time))}`,
                badge(appointment.status),esc(appointment.reasons.join(', ')),
            ])));
    }
    function showImpact(record) {
        if (record.affected_appointments?.length) modal('Resultado del cambio', impactHtml(record));
    }
    /** Abre datos editables del registro seleccionado y activa únicamente los campos pertinentes. */
    function edit(record={}) {
        const patientAccount=entity==='patients'&&!record.id;
        modal(record.id?'Modificar registro':config.new,`<form id="entity-form">${formErrors}<div class="grid two">${entityFields(entity,record)}${patientAccount?`<div class="full">${notice('La ficha permite recibir atención, pagar y gestionar citas sin tener cuenta ni correo. El portal es opcional para adultos que lo soliciten.')}</div><label class="terms-accept full"><input id="create-account" type="checkbox">El paciente adulto solicita una cuenta de acceso al portal</label><div id="patient-account-fields" class="grid two full">${accountFields({},false)}</div>`:''}</div>${actionButtons(button('Cancelar','data-dismiss','')+'<button type="submit" class="btn success">Guardar</button>')}</form>`);
        on('[data-dismiss]','click',closeModal);
        if(patientAccount) {
            const update=()=>{
                const birth=$('#entity-form [name="birth_date"]').value;
                const isMinor=birth&&birth>adultCutoff();
                $('#create-account').disabled=Boolean(isMinor);
                if(isMinor)$('#create-account').checked=false;
                const enabled=$('#create-account').checked;
                $('#patient-account-fields').hidden=!enabled;
                $('#patient-account-fields').querySelectorAll('input').forEach(input=>input.disabled=!enabled);
                const email=$('#entity-form [name="email"]');email.required=enabled;
                email.closest('label').querySelector('span').textContent=enabled?'Correo electrónico para activar la cuenta':'Correo electrónico (opcional)';
            };
            on('#create-account,#entity-form [name="birth_date"]','change',update);update();
        }
        if(entity==='users') {
            const update=()=>{const doctor=$('#doctor_id');$('#doctor-link').hidden=$('#role').value!=='Médico';doctor.required=!$('#doctor-link').hidden;doctor.disabled=$('#doctor-link').hidden;if(doctor.disabled)doctor.value='';};
            on('#role','change',update);update();
        }
        bindForm('entity-form',async(data,form)=>{
            delete data.code;
            if(entity==='doctors')data.specialty_ids=new FormData(form).getAll('specialty_ids');
            if(entity==='users'&&data.role!=='Médico')data.doctor_id=null;
            const withAccount=patientAccount&&$('#create-account').checked;
            const saved=await api(withAccount?'patients/with-account':`entities/${entity}${record.id?'/'+record.id:''}`,record.id?'PUT':'POST',data);
            closeModal();await references();if(entity==='users'&&record.id===state.user.id)state.user=(await api('session')).user;
            if(entity==='patients'&&state.returnToBooking){const target=state.returnToBooking;state.returnToBooking=null;state.bookingDraft={...(state.bookingDraft||{}),view:target,patient_id:String(saved.id)};await navigate(target);toast('Ficha registrada. Continúe con la cita del paciente.');return;}
            await navigate(state.view);toast(withAccount?(saved.activation_email_sent?'Paciente y cuenta registrados. Se envió el código de activación a su correo.':'Paciente y cuenta registrados. Solicite el código desde Activar mi cuenta.'):entity==='users'&&!saved.email_verified_at?'Cuenta guardada pendiente de activación por correo.':'Registro guardado correctamente.');showImpact(saved);
        });
    }
    /** Enlaza consulta, modificación, estado y siguientes pasos de cada registro. */
    function bindRows(){
        on('[data-patient-book]','click',e=>{state.bookingDraft=null;state.workflowContext={patient_id:Number(e.currentTarget.dataset.patientBook)};return navigate('CU09');});
        on('[data-patient-history]','click',e=>{state.workflowContext={patient_id:Number(e.currentTarget.dataset.patientHistory)};return navigate('CU15');});
        on('[data-doctor-schedule]','click',e=>{state.scheduleDoctor=e.currentTarget.dataset.doctorSchedule;return navigate('CU07');});
        on('[data-show]','click',async event=>{
            const record=await api(`entities/${entity}/${event.currentTarget.dataset.show}`);
            const fields = config.headers.map((label,index)=>[label,config.row(record)[index]]);
            if (entity === 'patients') fields.push(['Correo electrónico', esc(record.email || '—')], ['Dirección', esc(record.address || '—')]);
            modal('Consultar registro',details(fields)+impactHtml(record));
        });
        on('[data-create-account]','click',e=>{
            const id=Number(e.currentTarget.dataset.createAccount);
            const patient=rows.find(r=>r.id===id);
            modal('Crear cuenta del paciente',`<p>${esc(full(patient))} · CI: ${esc(patient.identity||'Sin documento')}</p><form id="patient-account-form">${formErrors}<div class="grid two">${accountFields(patient)}</div>${actionButtons(button('Cancelar','data-dismiss','')+'<button type="submit" class="btn success">Crear cuenta y enviar código</button>')}</form>`);
            on('[data-dismiss]','click',closeModal);
            bindForm('patient-account-form',async(data,form)=>{
                const result=await api(`patients/${id}/account`,'POST',data);
                form.reset();closeModal();await references();await navigate(state.view);toast(result.message);
            });
        });
        on('[data-edit]','click',e=>edit(rows.find(r=>r.id===Number(e.currentTarget.dataset.edit))));
        on('[data-toggle]','click',e=>{
            const r=rows.find(r=>r.id===Number(e.currentTarget.dataset.toggle));
            confirmAction('Cambiar estado',`¿Desea ${r.status==='Activo'?'desactivar':'activar'} este registro?`,async()=>{
                const data={...r,status:r.status==='Activo'?'Inactivo':'Activo'};
                if(entity==='doctors')data.specialty_ids=r.specialties.map(s=>s.id);
                const saved = await api(`entities/${entity}/${r.id}/status`,'PATCH',{status:data.status});
                await references();await navigate(state.view);toast('Estado actualizado.');
                return ()=>showImpact(saved);
            },r.status==='Activo');
        });
    }
    return {html:`<div class="toolbar">${field('Buscar por código, nombre o identificador','search','search','','placeholder="Buscar registros…"')}${button('+ '+config.new,'id="new-entity"')}${entity==='users'?button('Portal opcional de pacientes','id="new-patient-account"'):''}</div>${panel(config.title,`<div id="records">${rowsHtml(rows)}</div>`)}`,bind:()=>{
        on('#new-entity','click',()=>edit());bindRows();
        on('#new-patient-account','click',()=>navigate('CU06'));
        on('#search','input',e=>{const q=e.target.value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();$('#records').innerHTML=rowsHtml(rows.filter(r=>JSON.stringify(r).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().includes(q)));bindRows();});
    }};
}
views.CU03=()=>crudView('users');views.CU04=()=>crudView('doctors');views.CU05=()=>crudView('specialties');views.CU06=()=>crudView('patients');


}
