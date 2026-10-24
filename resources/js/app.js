const $ = (s, root = document) => root.querySelector(s);
const $$ = (s, root = document) => [...root.querySelectorAll(s)];
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const full = p => p ? `${p.first_name} ${p.last_name}` : '—';
const code = (prefix, id) => `${prefix}-${String(id).padStart(prefix === 'CIT' ? 4 : 3, '0')}`;
const time = t => String(t || '').slice(0, 5);
const states = ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'];
const days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
const active = a => ['Programada', 'Confirmada'].includes(a.status);
const localDate = () => new Intl.DateTimeFormat('sv-SE', {timeZone:'America/La_Paz'}).format(new Date());
const cycles = [
    ['Ciclo I · Funcionalidades fundamentales', [['CU01','Iniciar sesión'],['CU02','Cerrar sesión'],['CU03','Gestionar usuarios y roles'],['CU04','Gestionar médicos'],['CU05','Gestionar especialidades'],['CU06','Gestionar pacientes'],['CU07','Gestionar horarios médicos'],['CU08','Consultar disponibilidad médica'],['CU09','Programar cita médica']]],
    ['Ciclo II · Gestión y seguimiento de citas', [['CU10','Reprogramar cita médica'],['CU11','Cancelar cita médica'],['CU12','Consultar agenda médica'],['CU13','Registrar asistencia e inasistencia'],['CU14','Registrar cita como atendida'],['CU17','Citas afectadas por indisponibilidad']]],
    ['Ciclo III · Historial y reportes', [['CU15','Consultar historial de citas'],['CU16','Generar reportes administrativos']]],
];
const titles = Object.fromEntries(cycles.flatMap(c => c[1]));
const access = {Administrador:['CU01','CU02','CU03','CU04','CU05','CU07','CU15','CU16'],Recepcionista:['CU01','CU02','CU06','CU08','CU09','CU10','CU11','CU12','CU13','CU15','CU17'],'Médico':['CU01','CU02','CU12','CU14']};
const state = {user:null, csrf:$('meta[name="csrf-token"]').content, view:'CU01', refs:{doctors:[],specialties:[],patients:[]}, sequence:0};
const main = $('#main');
const panel = (title, body) => `<section class="panel">${title ? `<h3>${esc(title)}</h3>` : ''}${body}</section>`;
const badge = s => `<span class="badge ${esc(s)}">${esc(s)}</span>`;
const notice = (s, type = '') => `<div class="notice ${type}">${esc(s)}</div>`;
const button = (label, attrs = '', style = 'primary') => `<button type="button" class="btn ${style}" ${attrs}>${esc(label)}</button>`;
const table = (headers, rows) => `<div class="table-wrap"><table><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.map(row=>`<tr>${row.map(c=>`<td>${c}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${headers.length}" class="empty">Sin resultados. Los registros aparecerán aquí cuando los cree.</td></tr>`}</tbody></table></div><p class="result-count">${rows.length} registro(s)</p>`;
const field = (label, name, type = 'text', value = '', attrs = '') => `<label class="field"><span>${esc(label)}</span><input name="${esc(name)}" id="${esc(name)}" type="${type}" value="${esc(value)}" ${attrs}></label>`;
const select = (label, name, options, value = '', attrs = '') => `<label class="field"><span>${esc(label)}</span><select name="${esc(name)}" id="${esc(name)}" ${attrs}>${options.map(o=>{const [v,l] = Array.isArray(o) ? o : [o,o]; return `<option value="${esc(v)}" ${String(value) === String(v) ? 'selected' : ''}>${esc(l)}</option>`;}).join('')}</select></label>`;
const textarea = (label, name, value = '', attrs = '') => `<label class="field full"><span>${esc(label)}</span><textarea name="${esc(name)}" rows="3" ${attrs}>${esc(value)}</textarea></label>`;
const details = entries => `<dl class="details">${entries.map(([k,v])=>`<div><dt>${esc(k)}</dt><dd>${v}</dd></div>`).join('')}</dl>`;
const personOptions = list => list.map(p=>[p.id, full(p)]);
const specOptions = () => state.refs.specialties.filter(s=>s.status==='Activo').map(s=>[s.id,s.name]);
const doctorOptions = (specialty = null) => personOptions(state.refs.doctors.filter(d=>d.status==='Activo' && (!specialty || d.specialties.some(s=>String(s.id)===String(specialty)))));
const submit = label => `<div class="actions"><button class="btn success" type="submit">${esc(label)}</button></div>`;
const formErrors = '<div class="form-errors" role="alert"></div>';
const actionButtons = body => `<div class="actions end">${body}</div>`;
function toast(message, error = false) {
    const box = document.createElement('div'); box.className = `toast ${error?'error':''}`; box.textContent = message;
    $('#toasts').append(box); setTimeout(()=>box.remove(), 6000);
}
function safe(fn) { return async event => { try { await fn(event); } catch (e) { toast(e.message, true); } }; }
function on(selector, event, fn, root = document) { $$(selector, root).forEach(el=>el.addEventListener(event,safe(fn))); }
async function api(path, method = 'GET', body) {
    const response = await fetch(`/api/${path}`, {method, credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':state.csrf}, ...(body !== undefined ? {body:JSON.stringify(body)} : {})});
    const data = await response.json().catch(()=>({message:'No se pudo interpretar la respuesta del servidor.'}));
    if (data.csrf) { state.csrf = data.csrf; $('meta[name="csrf-token"]').content = data.csrf; }
    if (!response.ok) {
        if (response.status === 401) { state.user = null; await navigate('CU01'); }
        const error = new Error(response.status === 419 ? 'La sesión caducó. Recargue la página para iniciar sesión.' : (data.message || 'No se pudo completar la operación.'));
        error.errors = data.errors; throw error;
    }
    return data;
}
function bindForm(id, callback) {
    const form = $(`#${id}`);
    form.addEventListener('submit', async event => {
        event.preventDefault(); const errors = $('.form-errors', form); if(errors)errors.innerHTML='';
        const btn = $('button[type=submit]', form); if(btn?.disabled)return; if(btn)btn.disabled = true;
        try { await callback(Object.fromEntries(new FormData(form)), form); }
        catch(e) {
            const messages = e.errors ? Object.values(e.errors).flat() : [e.message];
            if(errors) { errors.innerHTML = `<div class="notice error"><ul>${messages.map(m=>`<li>${esc(m)}</li>`).join('')}</ul></div>`; errors.scrollIntoView({block:'nearest'}); }
            else toast(messages.join(' '), true);
        } finally { if(btn)btn.disabled=false; }
    });
}
function modal(title, body) {
    $('#modal-title').textContent = title; $('#modal-body').innerHTML = body;
    if(!$('#modal').open)$('#modal').showModal();
}
function closeModal() { $('#modal').close(); }
function confirmAction(title, message, callback, danger = false) {
    modal(title, `<p>${esc(message)}</p>${actionButtons(button('Cancelar','data-dismiss','')+button('Confirmar','id="confirm-action"',danger?'danger':'success'))}`);
    on('[data-dismiss]','click',closeModal);
    on('#confirm-action','click',async event=>{event.target.disabled=true;try { await callback(); closeModal(); } finally {event.target.disabled=false;}});
}
async function references() { state.refs = await api('references'); }
function shell() {
    const user = state.user;
    $('#sidebar').hidden = !user;
    $('#menu-toggle').hidden = !user;
    $('#profile').innerHTML = user ? `<div class="profile-info"><strong>${esc(user.name)}</strong><small>${esc(user.role)}</small></div><button class="avatar" id="profile-button" aria-label="Ver perfil">${esc(user.name.slice(0,1))}</button>${button('Salir','id="logout-button"','')}` : '<small>Acceso del personal</small>';
    if(!user)return;
    $('#sidebar').innerHTML = cycles.map(([title,links])=>`<nav><h3>${esc(title)}</h3>${links.filter(([id])=>access[user.role]?.includes(id)).map(([id,label])=>`<button class="nav-btn ${state.view===id?'selected':''}" data-view="${id}" ${state.view===id?'aria-current="page"':''}><b>${id}</b><span>${esc(label)}</span></button>`).join('')}</nav>`).join('');
    on('[data-view]','click',e=>navigate(e.currentTarget.dataset.view));
    on('#logout-button','click',logout);
    on('#profile-button','click',()=>navigate('CU02'));
}
function logout() { confirmAction('Cerrar sesión',`¿Desea cerrar la sesión de ${state.user.name}?`,async()=>{await api('logout','POST',{});state.user=null;state.refs={doctors:[],specialties:[],patients:[]};await navigate('CU01');toast('Sesión cerrada correctamente.');},true); }
function heading() {
    return `<div class="page-heading"><span class="eyebrow">${state.view} · CLÍNICA VIDA SALUD</span><h1>${esc(titles[state.view])}</h1><p>${esc(state.user.role)} · ${new Intl.DateTimeFormat('es-BO',{dateStyle:'long',timeZone:'America/La_Paz'}).format(new Date())}</p></div>`;
}
async function navigate(view) {
    if (!state.user) view = 'CU01';
    else if (!access[state.user.role]?.includes(view)) { toast('Su rol no tiene acceso a esta función.',true); return; }
    else if(view==='CU01')view=access[state.user.role][2];
    state.view = view; state.sequence++; closeModal(); shell();
    $('#sidebar').classList.remove('open');$('#menu-toggle').setAttribute('aria-expanded','false');
    if(view==='CU01')return loginView();
    main.innerHTML = heading()+'<p class="empty">Cargando…</p>';
    const seq = state.sequence;
    try { const content = await views[view](); if(seq !== state.sequence)return; main.innerHTML=heading()+content.html; content.bind?.(); }
    catch(e) { if(seq===state.sequence)main.innerHTML=heading()+notice(e.message,'error')+button('Reintentar','id="retry"'); on('#retry','click',()=>navigate(view)); }
    window.scrollTo(0,0);
}
function loginView() {
    main.innerHTML = `<div class="login-wrap">${panel('',`<div class="login-heading"><div class="brand-mark">✚</div><h1>Iniciar sesión</h1><p>Acceso para el personal de la clínica</p></div><form id="login-form">${formErrors}${field('Correo o identificador de acceso','email','text','','required maxlength="255" autocomplete="username" placeholder="usuario@vidasalud.bo"')}${field('Contraseña','password','password','','required maxlength="128" autocomplete="current-password"')}${submit('Iniciar sesión')}</form>`)}<p class="login-foot">Clínica Vida Salud · Gestión de citas médicas</p></div>`;
    bindForm('login-form', async data=>{const result=await api('login','POST',data);state.user=result.user;await references();await navigate(access[state.user.role][2]);toast(`Bienvenido, ${state.user.name}.`);});
}
const views = {};
views.CU02 = async()=>({html:panel('Sesión activa',details([['Usuario',esc(state.user.name)],['Identificador',esc(state.user.email)],['Rol',badge(state.user.role)]])+`<div class="actions">${button('Cerrar sesión','id="session-logout"','danger')}</div>`),bind:()=>on('#session-logout','click',logout)});

// CU03–CU06: registros persistentes y activación sin eliminar información.
const configs = {
    users:{title:'Usuarios registrados',new:'Registrar usuario',headers:['Identificador de acceso','Nombre','Rol','Médico vinculado','Estado'], row:r=>[esc(r.email),esc(r.name),esc(r.role),esc(full(state.refs.doctors.find(d=>d.id===r.doctor_id))),badge(r.status)]},
    doctors:{title:'Médicos registrados',new:'Registrar médico',headers:['Código','N.º registro','Médico','Teléfono','Especialidades','Estado'],row:r=>[code('MED',r.id),esc(r.registration),esc(full(r)),esc(r.phone),esc(r.specialties.map(s=>s.name).join(', ')),badge(r.status)]},
    specialties:{title:'Especialidades',new:'Registrar especialidad',headers:['Código','Nombre','Descripción','Estado'],row:r=>[code('ESP',r.id),esc(r.name),esc(r.description||'—'),badge(r.status)]},
    patients:{title:'Pacientes registrados',new:'Registrar paciente',headers:['Código','CI','Paciente','Nacimiento','Sexo','Teléfono','Estado'],row:r=>[code('PAC',r.id),esc(r.identity),esc(full(r)),esc(r.birth_date),esc(r.sex),esc(r.phone),badge(r.status)]},
};
function entityFields(entity,r={}) {
    const req='required maxlength="100"';
    const person = field('Nombres','first_name','text',r.first_name,req)+field('Apellidos','last_name','text',r.last_name,'required maxlength="150"')+field('Teléfono','phone','tel',r.phone,'required maxlength="30"');
    let body='';
    if(entity==='users') {
        body = field('Nombre completo','name','text',r.name,'required maxlength="150"')+field('Correo / identificador de acceso','email','email',r.email,'required maxlength="255" autocomplete="off"')+field(r.id?'Nueva contraseña (vacía para conservar)':'Contraseña (mínimo 10 caracteres)','password','password','','minlength="10" maxlength="128" autocomplete="new-password" '+(r.id?'':'required'))+select('Rol','role',['Administrador','Recepcionista','Médico'],r.role||'Recepcionista')+`<div id="doctor-link">${select('Médico vinculado','doctor_id',[['','Seleccione un médico…'],...personOptions(state.refs.doctors.filter(d=>d.status==='Activo'||d.id===r.doctor_id))],r.doctor_id)}</div>`;
    } else if(entity==='doctors') {
        body=field('Código de médico','code','text',r.id?code('MED',r.id):'Automático al guardar','readonly')+field('Número de registro profesional','registration','text',r.registration,'required maxlength="80"')+person+field('Correo electrónico','email','email',r.email,'required maxlength="255"')+`<div class="full"><span class="label">Especialidades *</span><div class="checks">${state.refs.specialties.filter(s=>s.status==='Activo'||r.specialties?.some(x=>x.id===s.id)).map(s=>`<label><input type="checkbox" name="specialty_ids" value="${s.id}" ${r.specialties?.some(x=>x.id===s.id)?'checked':''}>${esc(s.name)}${s.status==='Inactivo'?' (inactiva)':''}</label>`).join('')||'<small>Registre una especialidad activa antes de crear médicos.</small>'}</div></div>`;
    } else if(entity==='specialties')body=field('Código','code','text',r.id?code('ESP',r.id):'Automático al guardar','readonly')+field('Nombre de especialidad','name','text',r.name,'required maxlength="120"')+textarea('Descripción (opcional)','description',r.description,'maxlength="3000"');
    else body=field('Código de paciente','code','text',r.id?code('PAC',r.id):'Automático al guardar','readonly')+field('Documento de identidad (CI)','identity','text',r.identity,'required maxlength="40"')+person+field('Fecha de nacimiento','birth_date','date',r.birth_date,`required max="${localDate()}"`)+select('Sexo','sex',['Femenino','Masculino'],r.sex)+field('Correo electrónico (opcional)','email','email',r.email,'maxlength="255"')+textarea('Dirección (opcional)','address',r.address,'maxlength="1000"');
    return body+select('Estado','status',['Activo','Inactivo'],r.status||'Activo');
}
async function crudView(entity) {
    const rows=await api(`entities/${entity}`), config=configs[entity];
    const rowsHtml = items=>table([...config.headers,'Acciones'],items.map(r=>[...config.row(r),button('Modificar',`data-edit="${r.id}"`,'link')+button(r.status==='Activo'?'Desactivar':'Activar',`data-toggle="${r.id}"`,'link')]));
    function edit(record={}) {
        modal(record.id?'Modificar registro':config.new,`<form id="entity-form">${formErrors}<div class="grid two">${entityFields(entity,record)}</div>${actionButtons(button('Cancelar','data-dismiss','')+'<button type="submit" class="btn success">Guardar</button>')}</form>`);
        on('[data-dismiss]','click',closeModal);
        if(entity==='users') {
            const update=()=>{const doctor=$('#doctor_id');$('#doctor-link').hidden=$('#role').value!=='Médico';doctor.required=!$('#doctor-link').hidden;doctor.disabled=$('#doctor-link').hidden;if(doctor.disabled)doctor.value='';};
            on('#role','change',update);update();
        }
        bindForm('entity-form',async(data,form)=>{
            delete data.code;
            if(entity==='doctors')data.specialty_ids=new FormData(form).getAll('specialty_ids');
            if(entity==='users'&&data.role!=='Médico')data.doctor_id=null;
            await api(`entities/${entity}${record.id?'/'+record.id:''}`,record.id?'PUT':'POST',data);
            closeModal();await references();if(entity==='users'&&record.id===state.user.id)state.user=(await api('session')).user;
            await navigate(state.view);toast('Registro guardado correctamente.');
        });
    }
    function bindRows(){
        on('[data-edit]','click',e=>edit(rows.find(r=>r.id===Number(e.currentTarget.dataset.edit))));
        on('[data-toggle]','click',e=>{
            const r=rows.find(r=>r.id===Number(e.currentTarget.dataset.toggle));
            confirmAction('Cambiar estado',`¿Desea ${r.status==='Activo'?'desactivar':'activar'} este registro?`,async()=>{
                const data={...r,status:r.status==='Activo'?'Inactivo':'Activo'};
                if(entity==='doctors')data.specialty_ids=r.specialties.map(s=>s.id);
                await api(`entities/${entity}/${r.id}`,'PUT',data);await references();await navigate(state.view);toast('Estado actualizado.');
            },r.status==='Activo');
        });
    }
    return {html:`<div class="toolbar">${field('Buscar por código, nombre o identificador','search','search','','placeholder="Buscar registros…"')}${button('+ '+config.new,'id="new-entity"')}</div>${panel(config.title,`<div id="records">${rowsHtml(rows)}</div>`)}`,bind:()=>{
        on('#new-entity','click',()=>edit());bindRows();
        on('#search','input',e=>{const q=e.target.value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();$('#records').innerHTML=rowsHtml(rows.filter(r=>JSON.stringify(r).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().includes(q)));bindRows();});
    }};
}
views.CU03=()=>crudView('users');views.CU04=()=>crudView('doctors');views.CU05=()=>crudView('specialties');views.CU06=()=>crudView('patients');

// CU07: horario semanal e intervalos de indisponibilidad.
let scheduleDoctor='',scheduleTab='schedule';
views.CU07=async()=>{
    const doctors=doctorOptions();if(!doctors.length)return{html:notice('Registre al menos un médico activo para configurar sus horarios.')};
    if(!doctors.some(([id])=>String(id)===String(scheduleDoctor)))scheduleDoctor=doctors[0][0];
    const data=await api('schedules?'+new URLSearchParams({doctor_id:scheduleDoctor}));
    let body;
    if(scheduleTab==='schedule')body=panel('Configurar horario semanal',`<form id="schedule-form">${formErrors}<div class="grid four">${select('Día de atención','weekday',days.map((d,i)=>[i,d]),1)}${field('Hora de inicio','start_time','time','08:00','required')}${field('Hora de finalización','end_time','time','12:00','required')}${field('Duración de cita (minutos)','duration','number',30,'required min="5" max="240"')}</div>${submit('Guardar horario')}</form>`)+panel('Horario vigente',table(['Día','Inicio','Fin','Duración',''],data.schedules.map(s=>[esc(days[s.weekday]),time(s.start_time),time(s.end_time),`${s.duration} min`,button('Modificar',`data-schedule="${s.id}"`,'link')])));
    else body=panel('Nueva indisponibilidad',`<form id="unavailable-form">${formErrors}<div class="grid two">${select('Motivo','reason',['Ausencia','Permiso','Vacaciones'])}${field('Médico','doctor_name','text',full(state.refs.doctors.find(d=>String(d.id)===String(scheduleDoctor))),'readonly')}${field('Inicio (fecha y hora)','starts_at','datetime-local',`${localDate()}T08:00`,'required')}${field('Finalización (fecha y hora)','ends_at','datetime-local',`${localDate()}T12:00`,'required')}</div><div class="actions">${button('Consultar citas afectadas','id="preview-block"','')}<button class="btn success" type="submit">Registrar indisponibilidad</button></div><div id="affected-preview" style="margin-top:16px"></div></form>`)+panel('Indisponibilidades registradas',table(['Inicio','Fin','Motivo'],data.unavailabilities.map(x=>[esc(x.starts_at),esc(x.ends_at),esc(x.reason)])));
    return {html:panel('',select('Médico activo','schedule-doctor',doctors,scheduleDoctor))+`<div class="tabs">${['schedule','block'].map((t,i)=>`<button class="tab ${scheduleTab===t?'selected':''}" data-tab="${t}">${i?'Registrar indisponibilidad':'Configuración del horario médico'}</button>`).join('')}</div>`+body,bind:()=>{
        on('#schedule-doctor','change',async e=>{scheduleDoctor=e.target.value;await navigate('CU07');});
        on('[data-tab]','click',e=>{scheduleTab=e.currentTarget.dataset.tab;return navigate('CU07');});
        if(scheduleTab==='schedule'){
            on('[data-schedule]','click',e=>{const s=data.schedules.find(x=>x.id===Number(e.currentTarget.dataset.schedule));for(const k of ['weekday','duration','start_time','end_time'])$(`[name=${k}]`).value=k.endsWith('time')?time(s[k]):s[k];$('#schedule-form').scrollIntoView({block:'center',behavior:'smooth'});});
            bindForm('schedule-form',async d=>{await api('schedules','POST',{...d,doctor_id:scheduleDoctor});await navigate('CU07');toast('Horario guardado.');});
        }else{
            const preview=async()=>{const form=$('#unavailable-form');if(!form.reportValidity())return;const d=Object.fromEntries(new FormData(form));delete d.doctor_name;const result=await api('unavailabilities/preview','POST',{...d,doctor_id:scheduleDoctor});$('#affected-preview').innerHTML=notice(`${result.affected.length} cita(s) vigentes afectadas. ${result.affected.map(a=>code('CIT',a.id)).join(', ')}${result.affected.length?' · Recepción podrá gestionarlas en CU17.':''}`,result.affected.length?'warning':'success');};
            on('#preview-block','click',preview);
            on('#starts_at,#ends_at','change',()=>{$('#affected-preview').innerHTML='';});
            bindForm('unavailable-form',async d=>{delete d.doctor_name;const result=await api('unavailabilities','POST',{...d,doctor_id:scheduleDoctor});await navigate('CU07');toast(`Indisponibilidad registrada. ${result.affected.length} cita(s) afectadas.`);});
        }
    }};
};

// CU08–CU09: bloques reales, duración calculada y confirmación por el servidor.
function slotsHtml(slots, selectable=false, selected=''){
    return `<div class="legend"><span>● Disponible</span><span>● Ocupado</span><span>● Bloqueado</span><span>● Pasado</span></div><div class="slots">${slots.length?slots.map(s=>selectable?`<button type="button" class="slot ${esc(s.status)} ${selected===s.start?'selected':''}" data-slot="${s.start}" ${s.status!=='Disponible'?'disabled':''}>${s.start}–${s.end}<small>${esc(s.status)}</small></button>`:`<div class="slot ${esc(s.status)}"><b>${s.start}–${s.end}</b><small>${esc(s.status)} ${esc(s.detail)}</small></div>`).join(''):'<p class="empty">El médico no tiene bloques de atención en esa fecha.</p>'}</div>`;
}
let availabilityFilter={specialty_id:'',doctor_id:'',date:''};
views.CU08=async()=>{
    const f=availabilityFilter;f.date ||= localDate();f.specialty_id ||= specOptions()[0]?.[0]||'';
    const doctors=doctorOptions(f.specialty_id);if(!doctors.some(([id])=>String(id)===String(f.doctor_id)))f.doctor_id=doctors[0]?.[0]||'';
    const slots=f.doctor_id?await api('availability?'+new URLSearchParams({doctor_id:f.doctor_id,date:f.date})):[];
    return{html:panel('Filtros de búsqueda',`<form id="availability-form">${formErrors}<div class="grid three">${select('Especialidad','specialty_id',[['','Seleccione…'],...specOptions()],f.specialty_id)}${select('Médico','doctor_id',[['','Seleccione…'],...doctors],f.doctor_id,'required')}${field('Fecha','date','date',f.date,'required')}</div>${submit('Consultar disponibilidad')}</form>`)+panel('Disponibilidad médica',slotsHtml(slots)),bind:()=>{
        on('#specialty_id','change',e=>{$('#doctor_id').innerHTML=doctorOptions(e.target.value).map(([id,n])=>`<option value="${id}">${esc(n)}</option>`).join('');});
        bindForm('availability-form',async d=>{availabilityFilter=d;await navigate('CU08');});
    }};
};
views.CU09=async()=>({html:panel('Programar nueva cita',`<form id="booking-form">${formErrors}<div class="grid two">${field('Código de cita','code','text','Automático al guardar','readonly')}${field('Fecha de creación','created','text',localDate(),'readonly')}</div><h3 class="step">Paso 1. Paciente</h3>${select('Paciente activo (CI — nombre)','patient_id',[['','Seleccione un paciente…'],...state.refs.patients.filter(p=>p.status==='Activo').map(p=>[p.id,`${p.identity} — ${full(p)}`])],'','required')}<h3 class="step">Paso 2. Especialidad y médico</h3><div class="grid two">${select('Especialidad','specialty_id',[['','Seleccione…'],...specOptions()],'','required')}${select('Médico habilitado','doctor_id',[['','Seleccione una especialidad…']],'','required')}</div><h3 class="step">Paso 3. Fecha y horario</h3><div class="grid two">${field('Fecha de atención','date','date',localDate(),`required min="${localDate()}"`)}${field('Hora de finalización (calculada)','end_time','text','','readonly')}</div><input type="hidden" name="start_time" id="chosen-slot"><div id="booking-slots">${notice('Seleccione especialidad, médico y fecha para consultar los horarios.')}</div><h3 class="step">Paso 4. Motivo y confirmación</h3>${textarea('Motivo de consulta (opcional)','reason','','maxlength="3000"')}<p style="margin-top:16px">Estado inicial: ${badge('Programada')}</p>${submit('Guardar cita')}</form>`),bind:()=>{
    on('#specialty_id','change',()=>{const opts=doctorOptions($('#specialty_id').value);$('#doctor_id').innerHTML='<option value="">Seleccione…</option>'+opts.map(([id,n])=>`<option value="${id}">${esc(n)}</option>`).join('');$('#chosen-slot').value='';$('#booking-slots').innerHTML=notice('Seleccione un médico.');$('#end_time').value='';});
    let version=0;
    const load=async()=>{const v=++version;$('#chosen-slot').value='';$('#end_time').value='';if(!$('#doctor_id').value||!$('#date').value)return;const slots=await api('availability?'+new URLSearchParams({doctor_id:$('#doctor_id').value,date:$('#date').value}));if(v!==version||state.view!=='CU09')return;$('#booking-slots').innerHTML=slotsHtml(slots,true);on('[data-slot]','click',e=>{$('#chosen-slot').value=e.currentTarget.dataset.slot;$('#end_time').value=slots.find(s=>s.start===e.currentTarget.dataset.slot).end;$$('[data-slot]').forEach(el=>el.classList.toggle('selected',el===e.currentTarget));});};
    on('#doctor_id,#date','change',load);
    bindForm('booking-form',async d=>{if(!d.start_time)throw new Error('Seleccione un bloque horario disponible.');delete d.code;delete d.created;delete d.end_time;const a=await api('appointments','POST',d);await navigate('CU09');toast(`Cita ${code('CIT',a.id)} programada correctamente.`);});
}});

// CU10–CU15 y CU17: agenda, transiciones e historial.
let appointmentFilters={};
function appointmentDetails(a){return details([['Código',code('CIT',a.id)],['Paciente',esc(full(a.patient))],['Médico',esc(full(a.doctor))],['Especialidad',esc(a.specialty.name)],['Fecha',esc(a.date)],['Horario',`${time(a.start_time)}–${time(a.end_time)}`],['Estado',badge(a.status)],['Fecha de creación',esc(a.created_at?.replace('T',' ').slice(0,19))],['Motivo de consulta',esc(a.reason||'—')],['Asistencia',esc(a.attendance_at||'Sin registrar')],['Cancelación',esc(a.cancellation_reason||'No aplica')]]);}
function showHistory(a){
    const eventRows=a.events.map(e=>{
        let info='';const d=e.details;
        if(e.action==='Reprogramación'){
            const oldDoctor=state.refs.doctors.find(m=>m.id===d.before.doctor_id),newDoctor=state.refs.doctors.find(m=>m.id===d.after.doctor_id);
            info=`Anterior: ${d.before.date} ${time(d.before.start_time)} · ${full(oldDoctor)}. Nuevo: ${d.after.date} ${time(d.after.start_time)} · ${full(newDoctor)}.`;
        }else info=[d.before,d.after,d.reason,d.attendance_at].filter(v=>v&&typeof v==='string').join(' · ');
        return[esc(e.created_at),esc(e.action),esc(info)];
    });
    modal(`Detalle de ${code('CIT',a.id)}`,appointmentDetails(a)+`<h3 class="step">Historial de cambios</h3>`+table(['Fecha','Acción','Detalle'],eventRows));
}
function cancelAppointment(a){
    modal('Cancelar cita médica',appointmentDetails(a)+`<form id="cancel-form" style="margin-top:20px">${formErrors}${textarea('Motivo de cancelación (obligatorio)','reason','','required maxlength="3000"')}${actionButtons(button('Volver','data-dismiss','')+'<button type="submit" class="btn danger">Confirmar cancelación</button>')}</form>`);
    on('[data-dismiss]','click',closeModal);bindForm('cancel-form',async d=>{await api(`appointments/${a.id}/cancel`,'POST',d);closeModal();await navigate(state.view);toast('Cita cancelada. El horario quedó libre.');});
}
function rescheduleAppointment(a){
    modal('Reprogramar cita médica',panel('Programación anterior',appointmentDetails(a))+`<form id="reschedule-form">${formErrors}<div class="grid two">${select('Médico (misma especialidad)','doctor_id',doctorOptions(a.specialty_id),a.doctor_id,'required')}${field('Nueva fecha','date','date',a.date<localDate()?localDate():a.date,`required min="${localDate()}"`)}</div><input type="hidden" name="start_time" id="reschedule-slot"><div id="reschedule-slots"></div>${submit('Guardar cambios')}</form>`);
    let version=0;
    const load=async()=>{const v=++version;$('#reschedule-slot').value='';const d=Object.fromEntries(new FormData($('#reschedule-form')));if(!d.doctor_id||!d.date)return;const slots=await api('availability?'+new URLSearchParams({doctor_id:d.doctor_id,date:d.date,exclude:a.id}));if(v!==version||!$('#reschedule-slots'))return;$('#reschedule-slots').innerHTML=slotsHtml(slots,true);on('[data-slot]','click',e=>{$('#reschedule-slot').value=e.currentTarget.dataset.slot;$$('[data-slot]',$('#modal')).forEach(el=>el.classList.toggle('selected',el===e.currentTarget));},$('#modal'));};
    on('#reschedule-form #doctor_id,#reschedule-form #date','change',load);safe(load)();
    bindForm('reschedule-form',async d=>{if(!d.start_time)throw new Error('Seleccione la nueva hora.');await api(`appointments/${a.id}/reschedule`,'PUT',d);closeModal();await navigate(state.view);toast('Cita reprogramada correctamente.');});
}
async function appointmentView(view,mode){
    const defaults={agenda:{date:localDate()},history:{},active:{},today:{},attend:{},affected:{}};
    const f=appointmentFilters[view]||defaults[mode];
    const rows=await api('appointments?'+new URLSearchParams({mode,...f}));
    let filters='';
    if(!['today','attend','affected'].includes(mode))filters=panel('Filtros de búsqueda',`<form id="appointment-filters">${formErrors}<div class="grid four">${field('Código, CI, paciente o médico','q','search',f.q)}${state.user.role!=='Médico'?select('Médico','doctor_id',[['','Todos los médicos'],...personOptions(state.refs.doctors)],f.doctor_id):field('Médico','own','text',full(state.refs.doctors.find(d=>d.id===state.user.doctor_id)),'readonly')}${field('Fecha','date','date',f.date)}${mode==='history'?select('Estado','status',[['','Todos los estados'],...states],f.status):''}</div>${submit(mode==='agenda'?'Consultar agenda':'Buscar citas')}</form>`);
    const headers=['Código','Paciente','Médico','Especialidad','Fecha','Horario','Estado'];
    if(mode==='today')headers.push('Asistencia');
    if(mode==='affected')headers.push('Indisponibilidad relacionada');
    headers.push('Acciones');
    const body=table(headers,rows.map(a=>{
        let buttons=button('Ver detalle',`data-detail="${a.id}"`,'link');
        if(active(a)) {
            if(view==='CU10'||mode==='affected')buttons+=button('Reprogramar',`data-reschedule="${a.id}"`,'small primary');
            if(view==='CU11'||mode==='affected')buttons+=button('Cancelar',`data-cancel="${a.id}"`,'small danger');
            if(mode==='today')buttons+=(!a.attendance_at?button('Registrar asistencia',`data-transition="attendance" data-id="${a.id}"`,'small success')+button('Inasistencia',`data-transition="absent" data-id="${a.id}"`,'small warning'):'');
            if(mode==='attend')buttons+=button('Registrar como atendida',`data-transition="attend" data-id="${a.id}"`,'small success');
            if(mode==='agenda'&&state.user.role==='Recepcionista'&&a.status==='Programada')buttons+=button('Confirmar cita',`data-transition="confirm" data-id="${a.id}"`,'small primary');
        }
        const cells=[code('CIT',a.id),esc(full(a.patient)),esc(full(a.doctor)),esc(a.specialty.name),esc(a.date),`${time(a.start_time)}–${time(a.end_time)}`,badge(a.status)];
        if(mode==='today')cells.push(esc(a.attendance_at?time(a.attendance_at.slice(11)):'Sin registrar'));
        if(mode==='affected')cells.push(a.blocks.map(b=>`${esc(b.reason)}<br><small>${esc(b.starts_at)} → ${esc(b.ends_at)}</small>`).join('<br>'));
        return [...cells,`<div class="actions" style="margin:0;min-width:140px">${buttons}</div>`];
    }));
    let intro='';
    if(mode==='affected')intro=notice(`Se detectaron ${rows.length} cita(s) vigentes afectadas por indisponibilidad médica.`,'warning');
    if(mode==='agenda'&&state.user.role==='Médico')intro=notice('La agenda se limita a su propia identificación profesional (RN18).');
    if(mode==='today')intro=notice(`Citas de hoy, ${localDate()}. La asistencia no cambia el estado de la cita. La inasistencia solo puede registrarse después de su finalización.`);
    return {html:filters+intro+panel(mode==='attend'?'Mi agenda de hoy':titles[view],body),bind:()=>{
        if($('#appointment-filters'))bindForm('appointment-filters',async d=>{delete d.own;appointmentFilters[view]=Object.fromEntries(Object.entries(d).filter(([,v])=>v));await navigate(view);});
        on('[data-detail]','click',e=>showHistory(rows.find(a=>a.id===Number(e.currentTarget.dataset.detail))));
        on('[data-cancel]','click',e=>cancelAppointment(rows.find(a=>a.id===Number(e.currentTarget.dataset.cancel))));
        on('[data-reschedule]','click',e=>rescheduleAppointment(rows.find(a=>a.id===Number(e.currentTarget.dataset.reschedule))));
        on('[data-transition]','click',e=>{const {transition,id}=e.currentTarget.dataset;const labels={attendance:'registrar asistencia',absent:'registrar inasistencia',attend:'registrar como atendida',confirm:'confirmar la cita'};confirmAction('Confirmar operación',`¿Confirma que desea ${labels[transition]} para ${code('CIT',id)}?`,async()=>{await api(`appointments/${id}/${transition}`,'POST',{});await navigate(view);toast('Cita actualizada correctamente.');});});
    }};
}
views.CU10=()=>appointmentView('CU10','active');views.CU11=()=>appointmentView('CU11','active');views.CU12=()=>appointmentView('CU12','agenda');views.CU13=()=>appointmentView('CU13','today');views.CU14=()=>appointmentView('CU14','attend');views.CU15=()=>appointmentView('CU15','history');views.CU17=()=>appointmentView('CU17','affected');

// CU16: siete reportes, porcentajes, impresión y exportación CSV.
const reportTypes=[['REP01','Citas por fecha'],['REP02','Citas por médico'],['REP03','Citas por especialidad'],['REP04','Citas por estado'],['REP05','Pacientes registrados'],['REP06','Resumen de atenciones por período'],['REP07','Agenda diaria por médico']];
let reportFilter={type:'REP01',date:localDate(),from:localDate().slice(0,8)+'01',to:localDate()};
views.CU16=async()=>({html:panel('Generador de reportes administrativos',`<form id="report-form">${formErrors}<div class="grid four">${select('Tipo de reporte','type',reportTypes.map(([k,n])=>[k,`${k} · ${n}`]),reportFilter.type)}<div data-report-field="date">${field('Fecha','date','date',reportFilter.date)}</div><div data-report-field="range">${field('Desde','from','date',reportFilter.from)}</div><div data-report-field="range">${field('Hasta','to','date',reportFilter.to)}</div><div data-report-field="doctor">${select('Médico','doctor_id',[['','Todos los médicos'],...personOptions(state.refs.doctors)],reportFilter.doctor_id)}</div><div data-report-field="specialty">${select('Especialidad','specialty_id',[['','Todas las especialidades'],...state.refs.specialties.map(s=>[s.id,s.name])],reportFilter.specialty_id)}</div><div data-report-field="status">${select('Estado','status',[['','Todos los estados'],...states],reportFilter.status)}</div></div>${submit('Generar reporte')}</form>`)+`<div id="report-result"></div>`,bind:()=>{
    const update=()=>{const type=$('#type').value;const visible={date:['REP01','REP07'].includes(type),range:['REP02','REP03','REP04','REP06'].includes(type),doctor:['REP02','REP07'].includes(type),specialty:type==='REP03',status:type==='REP04'};$$('[data-report-field]').forEach(el=>{el.hidden=!visible[el.dataset.reportField];$$('input,select',el).forEach(input=>{input.disabled=el.hidden;input.required=!el.hidden&&['date','from','to'].includes(input.name);});});$('#report-result').innerHTML='';};
    on('#type','change',update);update();
    bindForm('report-form',async d=>{
        const filter=Object.fromEntries(Object.entries(d).filter(([,v])=>v));reportFilter={...reportFilter,...filter};
        const query=new URLSearchParams(filter);const data=await api('reports?'+query);
        const metrics=filter.type==='REP06'?`<div class="metrics">${[['Total de citas',data.summary.total],...['Atendida','Cancelada','Inasistencia'].map(s=>[s,`${data.summary[s].count} · ${data.summary[s].percentage}%`])].map(([l,v])=>`<div class="metric"><small>${esc(l)}</small><strong>${esc(v)}</strong></div>`).join('')}</div>`:notice(`Total de registros: ${data.summary.total}`);
        $('#report-result').innerHTML=panel(reportTypes.find(([k])=>k===filter.type)[1],metrics+table(data.headers,data.rows.map(row=>row.map(esc)))+`<div class="actions no-print"><a class="btn primary" href="/api/reports?${esc(query.toString())}&amp;format=csv">Descargar CSV</a>${button('Imprimir','id="print-report"','')}</div>`);on('#print-report','click',()=>window.print());
    });
}});

$('#close-modal').addEventListener('click',closeModal);
$('#modal').addEventListener('click',e=>{if(e.target===$('#modal')){const rect=$('#modal').getBoundingClientRect();if(e.clientX<rect.left||e.clientX>rect.right||e.clientY<rect.top||e.clientY>rect.bottom)closeModal();}});
$('#menu-toggle').addEventListener('click',()=>{const open=$('#sidebar').classList.toggle('open');$('#menu-toggle').setAttribute('aria-expanded',String(open));});
async function boot(){try {const result=await api('session');state.user=result.user;if(state.user)await references();await navigate(state.user?access[state.user.role][2]:'CU01');}catch(e){main.innerHTML=notice(e.message,'error')+button('Reintentar','id="boot-retry"');on('#boot-retry','click',boot);}}
boot();
