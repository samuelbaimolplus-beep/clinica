import { installCatalogViews } from './modules/catalogs';
import { installScheduleViews } from './modules/schedules';
import { installPatientViews } from './modules/patients';
import { installAuditViews } from './modules/audit';
import { installImpactViews } from './modules/impacts';
import { installAvailabilityViews } from './modules/availability';
import { installAgendaAdjustmentViews } from './modules/agenda-adjustments';
import { createApiClient } from './lib/api';

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
    ['Ciclo III · Historial y reportes', [['CU15','Consultar historial de citas'],['CU16','Generar reportes administrativos'],['CU22','Consultar auditoría']]],
];
cycles.push(['Operación de Recepción', [['CU24', 'Bloqueos provisionales'], ['CU25', 'Habilitar cupos online']]]);
const titles = Object.fromEntries(cycles.flatMap(c => c[1]));
let access = {};
let termsVersion = '';
const home = () => access[state.user?.role]?.[2] || 'CU02';
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
const api = createApiClient({
    getToken: () => state.csrf,
    setToken: token => { state.csrf = token; $('meta[name="csrf-token"]').content = token; },
    onUnauthorized: async message => {
        state.user = null;
        state.refs = {doctors: [], specialties: [], patients: []};
        await navigate('CU01');
        toast(message, true);
    },
});
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
    on('#confirm-action','click',async event=>{event.target.disabled=true;try { const afterClose = await callback(); closeModal(); if(typeof afterClose==='function')afterClose(); } finally {event.target.disabled=false;}});
}
async function references() { state.refs = await api('references'); }
function shell() {
    const user = state.user;
    $('#sidebar').hidden = !user;
    $('#menu-toggle').hidden = !user;
    $('#profile').innerHTML = user ? `<div class="profile-info"><strong>${esc(user.name)}</strong><small>${esc(user.role)}</small></div><span class="avatar" aria-hidden="true">${esc(user.name.slice(0,1))}</span>${button('Salir','id="logout-button"','')}` : '<small>Personal y pacientes</small>';
    if(!user)return;
    $('#sidebar').innerHTML = cycles.filter(([,links]) => links.some(([id]) => access[user.role]?.includes(id))).map(([title,links])=>`<nav><h3>${esc(title)}</h3>${links.filter(([id])=>access[user.role]?.includes(id)).map(([id,label])=>`<button class="nav-btn ${state.view===id?'selected':''}" data-view="${id}" ${state.view===id?'aria-current="page"':''}><b>${id}</b><span>${esc(label)}</span></button>`).join('')}</nav>`).join('');
    on('[data-view]','click',e=>navigate(e.currentTarget.dataset.view));
    on('#logout-button','click',logout);
}
function logout() { confirmAction('Cerrar sesión',`¿Desea cerrar la sesión de ${state.user.name}?`,async()=>{await api('logout','POST',{});state.user=null;state.refs={doctors:[],specialties:[],patients:[]};await navigate('CU01');toast('Sesión cerrada correctamente.');},true); }
function heading() {
    return `<div class="page-heading"><span class="eyebrow">${state.view} · CLÍNICA VIDA SALUD</span><h1>${esc(titles[state.view])}</h1><p>${esc(state.user.role)} · ${new Intl.DateTimeFormat('es-BO',{dateStyle:'long',timeZone:'America/La_Paz'}).format(new Date())}</p></div>`;
}
async function navigate(view) {
    if (!state.user) view = 'CU01';
    else if (!access[state.user.role]?.includes(view)) { toast('Su rol no tiene acceso a esta función.',true); return; }
    else if(view==='CU01')view=home();
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
    main.innerHTML = `<div class="login-wrap">${panel('',`<div class="login-heading"><div class="brand-mark">✚</div><h1>Iniciar sesión</h1><p>Acceso para personal y pacientes</p></div><form id="login-form">${formErrors}${field('Correo o identificador de acceso','email','text','','required maxlength="255" autocomplete="username" placeholder="usuario@vidasalud.bo"')}${field('Contraseña','password','password','','required maxlength="128" autocomplete="current-password"')}${submit('Iniciar sesión')}</form><div class="actions">${button('Crear cuenta de paciente','id="register-patient"','link')}</div>`)}<p class="login-foot">Clínica Vida Salud · Gestión de citas médicas</p></div>`;
    on('#register-patient', 'click', () => views.registration());
    $('#login-form').insertAdjacentHTML('afterend', button('Recuperar acceso o verificar correo', 'id="recover-account"', 'link'));
    on('#recover-account', 'click', () => views.recovery());
    bindForm('login-form', async data=>{const result=await api('login','POST',data);state.user=result.user;await references();await navigate(home());toast(`Bienvenido, ${state.user.name}.`);});
}
const views = {};
views.CU02 = async()=>({html:panel('Sesión activa',details([['Usuario',esc(state.user.name)],['Identificador',esc(state.user.email)],['Rol',badge(state.user.role)]])+`<div class="actions">${button('Cerrar sesión','id="session-logout"','danger')}</div>`),bind:()=>on('#session-logout','click',logout)});

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


const ui = { $, state, views, api, esc, full, code, badge, field, select, textarea, personOptions, localDate, table, button, modal, formErrors, actionButtons, on, closeModal, bindForm, references, navigate, toast, confirmAction, panel, time, days, doctorOptions, notice, submit, main, details, loginView, heading, home, getTermsVersion: () => termsVersion };
installCatalogViews(ui);
installScheduleViews(ui);
installPatientViews(ui);
installAuditViews(ui);
installImpactViews(ui);
installAvailabilityViews(ui);
installAgendaAdjustmentViews(ui);

$('#close-modal').addEventListener('click',closeModal);
$('#modal').addEventListener('click',e=>{if(e.target===$('#modal')){const rect=$('#modal').getBoundingClientRect();if(e.clientX<rect.left||e.clientX>rect.right||e.clientY<rect.top||e.clientY>rect.bottom)closeModal();}});
$('#menu-toggle').addEventListener('click',()=>{const open=$('#sidebar').classList.toggle('open');$('#menu-toggle').setAttribute('aria-expanded',String(open));});
async function boot(){try {const result=await api('session');state.user=result.user;access=result.access;termsVersion=result.terms_version;if(state.user)await references();await navigate(state.user?home():'CU01');}catch(e){main.innerHTML=notice(e.message,'error')+button('Reintentar','id="boot-retry"');on('#boot-retry','click',boot);}}
boot();
