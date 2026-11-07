import { installCatalogViews } from './modules/catalogs';
import { installScheduleViews } from './modules/schedules';
import { installPatientViews } from './modules/patients';
import { installAuditViews } from './modules/audit';
import { installImpactViews } from './modules/impacts';
import { installAvailabilityViews } from './modules/availability';
import { installAgendaAdjustmentViews } from './modules/agenda-adjustments';
import { createApiClient } from './lib/api';
import { installClinicalViews } from './modules/clinical';
import { passwordToggleButton } from './lib/password-visibility';
import { navigationGroups, rolePanels, visibleNavigation, homeView } from './lib/navigation';
import { installSupervisionViews } from './modules/supervision';
import { installReceptionInbox } from './modules/reception-inbox';
import { installAccountManagementViews } from './modules/accounts';

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
const cycles = navigationGroups;
const titles = Object.fromEntries(cycles.flatMap(c => c[1]));
let access = {};
let termsVersion = '';
const home = () => homeView(state.user?.role, access[state.user?.role] || []);
let refreshInbox = () => {};
const state = {user:null, csrf:$('meta[name="csrf-token"]').content, view:'CU01', refs:{doctors:[],specialties:[],patients:[]}, sequence:0};
const main = $('#main');
const panel = (title, body) => `<section class="panel">${title ? `<h3>${esc(title)}</h3>` : ''}${body}</section>`;
const badge = s => `<span class="badge ${esc(s)}">${esc(s)}</span>`;
const notice = (s, type = '') => `<div class="notice ${type}">${esc(s)}</div>`;
const button = (label, attrs = '', style = 'primary') => `<button type="button" class="btn ${style}" ${attrs}>${esc(label)}</button>`;
const table = (headers, rows) => `<div class="table-wrap"><table><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.map(row=>`<tr>${row.map(c=>`<td>${c}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${headers.length}" class="empty">Sin resultados. Los registros aparecerán aquí cuando los cree.</td></tr>`}</tbody></table></div><p class="result-count">${rows.length} registro(s)</p>`;
const field = (label, name, type = 'text', value = '', attrs = '') => {
    const input = `<input name="${esc(name)}" id="${esc(name)}" type="${type}" value="${esc(value)}" ${attrs}>`;
    if (type === 'password') return `<div class="field"><label for="${esc(name)}">${esc(label)}</label><div class="password-control">${input}${passwordToggleButton(esc(name))}</div></div>`;
    return `<label class="field"><span>${esc(label)}</span>${input}</label>`;
};
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
    refreshInbox();
    if(!user)return;
    const profile = rolePanels[user.role];
    $('#sidebar').innerHTML = `<div class="role-panel"><strong>${esc(profile?.title || user.role)}</strong><p>${esc(profile?.description || '')}</p></div>`
        + visibleNavigation(user.role, access[user.role] || []).map(([title,links])=>`<nav><h3>${esc(title)}</h3>${links.map(([id,label])=>`<button class="nav-btn ${state.view===id?'selected':''}" data-view="${id}" ${state.view===id?'aria-current="page"':''}><b>${id}</b><span>${esc(label)}</span></button>`).join('')}</nav>`).join('');
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
    history.replaceState(null, '', state.user ? `#${view}` : location.pathname);
    $('#sidebar').classList.remove('open');$('#menu-toggle').setAttribute('aria-expanded','false');
    if(view==='CU01')return loginView();
    main.innerHTML = heading()+'<p class="empty">Cargando…</p>';
    const seq = state.sequence;
    try { const content = await views[view](); if(seq !== state.sequence)return; main.innerHTML=heading()+content.html; content.bind?.(); }
    catch(e) { if(seq===state.sequence)main.innerHTML=heading()+notice(e.message,'error')+button('Reintentar','id="retry"'); on('#retry','click',()=>navigate(view)); }
    window.scrollTo(0,0);
}
function loginView() {
    main.innerHTML = `<div class="login-wrap">${panel('',`<div class="login-heading"><div class="brand-mark">✚</div><h1>Iniciar sesión</h1><p>Acceso para personal y pacientes</p></div><form id="login-form">${formErrors}${field('Usuario o correo electrónico','email','text','','required maxlength="255" autocomplete="username" placeholder="superadmin o usuario@vidasalud.bo"')}${field('Contraseña','password','password','','required maxlength="128" autocomplete="current-password"')}${submit('Iniciar sesión')}</form><div class="actions">${button('Crear cuenta de paciente','id="register-patient"','link')}</div>`)}<p class="login-foot">Clínica Vida Salud · Gestión de citas médicas</p></div>`;
    on('#register-patient', 'click', () => views.registration());
    $('#login-form').insertAdjacentHTML('afterend', button('Recuperar acceso o verificar correo', 'id="recover-account"', 'link'));
    on('#recover-account', 'click', () => views.recovery());
    bindForm('login-form', async data=>{const result=await api('login','POST',data);state.user=result.user;await references();await navigate(home());toast(`Bienvenido, ${state.user.name}.`);});
}
const views = {};
views.CU02 = async()=>({html:panel('Sesión activa',details([['Usuario',esc(state.user.name)],['Nombre de usuario',esc(state.user.username || '')],['Correo',esc(state.user.email)],['Rol',badge(state.user.role)]])+`<div class="actions">${button('Cerrar sesión','id="session-logout"','danger')}</div>`),bind:()=>on('#session-logout','click',logout)});

// CU16: siete reportes, porcentajes, impresión y exportación CSV.
const reportTypes=[['REP01','Citas por fecha'],['REP02','Citas por médico'],['REP03','Citas por especialidad'],['REP04','Citas por estado'],['REP05','Pacientes registrados'],['REP06','Resumen de atenciones por período'],['REP07','Agenda diaria por médico']];
let reportFilter={type:'REP01',date:localDate(),from:localDate().slice(0,8)+'01',to:localDate()};
views.CU16=async()=>({html:panel('Generador de reportes administrativos',`<form id="report-form">${formErrors}<div class="grid four">${select('Tipo de reporte','type',reportTypes.map(([k,n])=>[k,`${k} · ${n}`]),reportFilter.type)}<div data-report-field="period">${select('Consultar por','period_mode',[['date','Fecha específica'],['range','Período']],reportFilter.period_mode||'date')}</div><div data-report-field="date">${field('Fecha','date','date',reportFilter.date)}</div><div data-report-field="range">${field('Desde','from','date',reportFilter.from)}</div><div data-report-field="range">${field('Hasta','to','date',reportFilter.to)}</div><div data-report-field="doctor">${select('Médico','doctor_id',[['','Todos los médicos'],...personOptions(state.refs.doctors)],reportFilter.doctor_id)}</div><div data-report-field="specialty">${select('Especialidad','specialty_id',[['','Todas las especialidades'],...state.refs.specialties.map(s=>[s.id,s.name])],reportFilter.specialty_id)}</div><div data-report-field="status">${select('Estado','status',[['','Todos los estados'],...states],reportFilter.status)}</div></div>${submit('Generar reporte')}</form>`)+`<div id="report-result"></div>`,bind:()=>{
    const update=()=>{const type=$('#type').value;const visible={period:type==='REP01',date:type==='REP07'||(type==='REP01'&&$('#period_mode').value==='date'),range:['REP02','REP03','REP04','REP06'].includes(type)||(type==='REP01'&&$('#period_mode').value==='range'),doctor:['REP02','REP07'].includes(type),specialty:type==='REP03',status:type==='REP04'};$$('[data-report-field]').forEach(el=>{el.hidden=!visible[el.dataset.reportField];$$('input,select',el).forEach(input=>{input.disabled=el.hidden;input.required=!el.hidden&&['date','from','to'].includes(input.name);});});$('#report-result').innerHTML='';};
    on('#type,#period_mode','change',update);update();
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
installClinicalViews(ui);
installSupervisionViews(ui);
installAccountManagementViews(ui);
refreshInbox = installReceptionInbox(ui);

$('#close-modal').addEventListener('click',closeModal);
$('#modal').addEventListener('click',e=>{if(e.target===$('#modal')){const rect=$('#modal').getBoundingClientRect();if(e.clientX<rect.left||e.clientX>rect.right||e.clientY<rect.top||e.clientY>rect.bottom)closeModal();}});
$('#menu-toggle').addEventListener('click',()=>{const open=$('#sidebar').classList.toggle('open');$('#menu-toggle').setAttribute('aria-expanded',String(open));});
async function boot(){try {const result=await api('session');state.user=result.user;access=result.access;termsVersion=result.terms_version;if(state.user)await references();await navigate(state.user && access[state.user.role]?.includes(location.hash.slice(1)) ? location.hash.slice(1) : state.user?home():'CU01');}catch(e){main.innerHTML=notice(e.message,'error')+button('Reintentar','id="boot-retry"');on('#boot-retry','click',boot);}}
boot();
