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
import { navigationGroups, rolePanels, visibleNavigation, homeView, routeFor, viewFromHash, viewDescriptions } from './lib/navigation';
import { icon, viewIcon } from './lib/icons';
import { installSupervisionViews } from './modules/supervision';
import { installReceptionInbox } from './modules/reception-inbox';
import { installAccountManagementViews } from './modules/accounts';
import { installSessionViews } from './modules/session';

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
const table = (headers, rows) => `<div class="table-wrap" tabindex="0" role="region" aria-label="Listado de registros"><table><thead><tr>${headers.map(h=>`<th scope="col">${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.map(row=>`<tr>${row.map((c,i)=>`<td data-label="${esc(headers[i])}"><div class="cell-content">${c === '' || c == null ? '—' : c}</div></td>`).join('')}</tr>`).join('') : `<tr><td colspan="${headers.length}" class="empty"><span class="empty-icon">${icon('file')}</span><strong>No hay resultados para mostrar</strong><span>Los registros aparecerán aquí cuando estén disponibles. Si aplicó filtros, pruebe con otros criterios.</span></td></tr>`}</tbody></table></div><p class="result-count">${rows.length} ${rows.length===1?'registro':'registros'}</p>`;
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
        form.querySelectorAll('[aria-invalid]').forEach(input=>input.removeAttribute('aria-invalid'));
        const btn = $('button[type=submit]', form); if(btn?.disabled)return; const label=btn?.innerHTML;
        if(btn){btn.disabled = true;btn.setAttribute('aria-busy','true');btn.textContent=id==='login-form'?'Ingresando…':'Guardando…';}
        try { await callback(Object.fromEntries(new FormData(form)), form); }
        catch(e) {
            const messages = e.errors ? Object.values(e.errors).flat() : [e.message];
            Object.keys(e.errors || {}).forEach(name=>form.elements.namedItem(name)?.setAttribute?.('aria-invalid','true'));
            if(errors) { errors.innerHTML = `<div class="notice error"><ul>${messages.map(m=>`<li>${esc(m)}</li>`).join('')}</ul></div>`; errors.scrollIntoView({block:'nearest'}); }
            else toast(messages.join(' '), true);
        } finally { if(btn){btn.disabled=false;btn.removeAttribute('aria-busy');btn.innerHTML=label;} }
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
    document.body.classList.toggle('guest',!user);
    $('#profile').innerHTML = user ? `<button class="profile-account" id="profile-account" aria-label="Mi cuenta y seguridad"><span class="profile-info"><strong>${esc(user.name)}</strong><small>${esc(user.role)}</small></span><span class="avatar" aria-hidden="true">${esc(user.name.slice(0,1))}</span></button><button type="button" class="icon-btn logout-button" id="logout-button" aria-label="Cerrar sesión" title="Cerrar sesión">${icon('logout')}</button>` : '<span class="secure-access">'+icon('shield')+' Acceso seguro</span>';
    refreshInbox();
    if(!user)return;
    const profile = rolePanels[user.role];
    $('#sidebar').innerHTML = `<div class="role-panel"><span class="workspace-label">SU ESPACIO DE TRABAJO</span><strong>${esc(profile?.title || user.role)}</strong><p>${esc(profile?.description || '')}</p></div><label class="nav-search">${icon('search')}<input id="nav-search" type="search" placeholder="Buscar en el menú" aria-label="Buscar en el menú" autocomplete="off"></label><div class="nav-sections">`
        + visibleNavigation(user.role, access[user.role] || []).map(([title,links])=>`<nav aria-label="${esc(title)}"><h3>${esc(title)}</h3>${links.map(([id,label])=>`<button class="nav-btn ${state.view===id?'selected':''}" data-view="${id}" ${state.view===id?'aria-current="page"':''}>${icon(viewIcon(id))}<span>${esc(label)}</span>${state.view===id?'<span class="nav-active-dot" aria-hidden="true"></span>':''}</button>`).join('')}</nav>`).join('')+'</div><p id="nav-empty" class="nav-empty" hidden>No encontramos esa opción. Pruebe con otro nombre.</p><div class="sidebar-foot">Clínica Vida Salud<small>Atención y gestión en un solo lugar</small></div>';
    on('[data-view]','click',e=>navigate(e.currentTarget.dataset.view));
    on('#nav-search','input',e=>{
        const normalize=value=>value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
        const query=normalize(e.target.value.trim());
        $$('#sidebar nav').forEach(nav=>{$$('.nav-btn',nav).forEach(link=>link.hidden=!normalize(link.textContent).includes(query));nav.hidden=!$('.nav-btn:not([hidden])',nav);});
        $('#nav-empty').hidden=Boolean($('#sidebar nav:not([hidden])'));
    });
    on('#profile-account','click',()=>navigate('CU02'));
    on('#logout-button','click',logout);
}
function logout() { confirmAction('Cerrar sesión',`¿Desea cerrar la sesión de ${state.user.name}?`,async()=>{await api('logout','POST',{});state.user=null;state.refs={doctors:[],specialties:[],patients:[]};await navigate('CU01');toast('Sesión cerrada correctamente.');},true); }
function heading() {
    const group=visibleNavigation(state.user.role, access[state.user.role] || []).find(([,links])=>links.some(([id])=>id===state.view));
    const title=group?.[1].find(([id])=>id===state.view)?.[1] || titles[state.view];
    document.title=`${title} · Clínica Vida Salud`;
    return `<div class="page-heading"><div><span class="eyebrow">${esc(group?.[0] || 'Clínica Vida Salud')}</span><h1>${esc(title)}</h1><p>${esc(viewDescriptions[state.view] || '')}</p></div><span class="page-date">${icon('calendar')}${new Intl.DateTimeFormat('es-BO',{day:'numeric',month:'short',year:'numeric',timeZone:'America/La_Paz'}).format(new Date())}</span></div>`;
}
function setMenu(open, restoreFocus=false) {
    $('#sidebar').classList.toggle('open',open);
    $('#menu-toggle').setAttribute('aria-expanded',String(open));
    $('#menu-toggle').setAttribute('aria-label',open?'Cerrar menú':'Abrir menú');
    $('#menu-backdrop').hidden=!open;
    document.body.classList.toggle('menu-open',open);
    main.inert=open;
    if(open)$('#nav-search')?.focus();
    else if(restoreFocus)$('#menu-toggle').focus();
}
async function navigate(view, {replace=false}={}) {
    if (!state.user) view = 'CU01';
    else if (!access[state.user.role]?.includes(view)) { toast('Su rol no tiene acceso a esta función.',true); return; }
    else if(view==='CU01')view=home();
    state.view = view; state.sequence++; closeModal(); shell();
    const destination=state.user?`#${routeFor(view)}`:location.pathname+location.search;
    if(!state.user || replace)history.replaceState(null,'',destination);
    else if(location.hash!==destination)history.pushState(null,'',destination);
    setMenu(false);
    if(view==='CU01')return loginView();
    main.setAttribute('aria-busy','true');
    main.innerHTML = heading()+'<div class="loading-state" role="status"><span class="spinner"></span><p>Cargando información…</p></div>';
    const seq = state.sequence;
    try { const content = await views[view](); if(seq !== state.sequence)return; main.innerHTML=heading()+content.html; content.bind?.(); }
    catch(e) { if(seq===state.sequence)main.innerHTML=heading()+notice(e.message,'error')+button('Reintentar','id="retry"'); on('#retry','click',()=>navigate(view)); }
    if(seq===state.sequence){main.removeAttribute('aria-busy');main.focus({preventScroll:true});}
    window.scrollTo(0,0);
}
function loginView() {
    document.title='Bienvenido · Clínica Vida Salud';
    main.removeAttribute('aria-busy');
    main.innerHTML = `<div class="login-layout"><section class="login-story"><span class="story-kicker">CLÍNICA VIDA SALUD</span><h2>Más cerca de usted.<br>Más fácil de gestionar.</h2><p>Un espacio para cuidar su tiempo y acompañar su atención médica.</p><div class="story-features"><div>${icon('calendar')}<span><strong>Su próxima consulta, a un paso</strong><small>Consulte horarios y gestione sus citas.</small></span></div><div>${icon('file')}<span><strong>Todo a mano</strong><small>Revise sus citas y el estado de sus solicitudes.</small></span></div><div>${icon('shield')}<span><strong>Acceso personal y protegido</strong><small>Una cuenta para cada paciente y miembro del equipo.</small></span></div></div><div class="story-footer"><span class="brand-mark">✚</span><span>Su salud, nuestra prioridad<small>Portal de atención y gestión</small></span></div></section><div class="login-wrap">${panel('',`<div class="login-heading"><span class="eyebrow">BIENVENIDO A SU CLÍNICA</span><h1>Iniciar sesión</h1><p>Ingrese con su cuenta para continuar.</p></div><form id="login-form">${formErrors}${field('Usuario o correo electrónico','email','text','','required maxlength="255" autocomplete="username" placeholder="Su usuario o correo"')}${field('Contraseña','password','password','','required maxlength="128" autocomplete="current-password"')}${submit('Iniciar sesión')}</form><div class="account-invitation"><strong>¿Es su primera visita?</strong><p>Para obtener una cuenta, acuda a la clínica. Recepción registrará su acceso. Si ya recibió el código por correo, active su cuenta.</p>${button('Activar mi cuenta','id="activate-account"','link')}</div>`)}<p class="login-foot">Clínica Vida Salud · Atención para personal y pacientes</p></div></div>`;
    on('#activate-account', 'click', () => views.activation());
    $('#login-form').insertAdjacentHTML('afterend', button('Recuperar acceso o verificar correo', 'id="recover-account"', 'link'));
    on('#recover-account', 'click', () => views.recovery());
    bindForm('login-form', async data=>{
        try { await api('login','POST',data); }
        catch(error) {
            if(error.data?.activation_required) {views.activation(error.data.email);toast(error.message);return;}
            if(error.status!==409)throw error;
        }
        // Leer la sesión ya persistida renueva también los permisos de pestañas antiguas.
        if(await boot({requireAuthentication:true}))toast(`Bienvenido, ${state.user.name}.`);
    });
}
const views = {};

// CU16: siete reportes, porcentajes, impresión y exportación CSV.
const reportTypes=[['REP01','Citas por fecha'],['REP02','Citas por médico'],['REP03','Citas por especialidad'],['REP04','Citas por estado'],['REP05','Pacientes registrados'],['REP06','Resumen de atenciones por período'],['REP07','Agenda diaria por médico']];
let reportFilter={type:'REP01',date:localDate(),from:localDate().slice(0,8)+'01',to:localDate()};
views.CU16=async()=>({html:panel('Generador de reportes administrativos',`<form id="report-form">${formErrors}<div class="grid four">${select('Tipo de reporte','type',reportTypes.map(([k,n])=>[k,n]),reportFilter.type)}<div data-report-field="period">${select('Consultar por','period_mode',[['date','Fecha específica'],['range','Período']],reportFilter.period_mode||'date')}</div><div data-report-field="date">${field('Fecha','date','date',reportFilter.date)}</div><div data-report-field="range">${field('Desde','from','date',reportFilter.from)}</div><div data-report-field="range">${field('Hasta','to','date',reportFilter.to)}</div><div data-report-field="doctor">${select('Médico','doctor_id',[['','Seleccione un médico…'],...personOptions(state.refs.doctors)],reportFilter.doctor_id)}</div><div data-report-field="specialty">${select('Especialidad','specialty_id',[['','Seleccione una especialidad…'],...state.refs.specialties.map(s=>[s.id,s.name])],reportFilter.specialty_id)}</div><div data-report-field="status">${select('Estado','status',[['','Todos los estados'],...states],reportFilter.status)}</div></div>${submit('Generar reporte')}</form>`)+`<div id="report-result"></div>`,bind:()=>{
    const update=()=>{const type=$('#type').value;const visible={period:type==='REP01',date:type==='REP07'||(type==='REP01'&&$('#period_mode').value==='date'),range:['REP02','REP03','REP04','REP06'].includes(type)||(type==='REP01'&&$('#period_mode').value==='range'),doctor:['REP02','REP07'].includes(type),specialty:type==='REP03',status:type==='REP04'};$$('[data-report-field]').forEach(el=>{el.hidden=!visible[el.dataset.reportField];$$('input,select',el).forEach(input=>{input.disabled=el.hidden;input.required=!el.hidden&&['date','from','to','doctor_id','specialty_id'].includes(input.name);});});$('#report-result').innerHTML='';};
    on('#type,#period_mode','change',update);update();
    bindForm('report-form',async d=>{
        const filter=Object.fromEntries(Object.entries(d).filter(([,v])=>v));reportFilter={...reportFilter,...filter};
        const query=new URLSearchParams(filter);const data=await api('reports?'+query);
        const metrics=filter.type==='REP06'?`<div class="metrics">${[['Total de citas',data.summary.total],...['Atendida','Cancelada','Inasistencia'].map(s=>[s,`${data.summary[s].count} · ${data.summary[s].percentage}%`])].map(([l,v])=>`<div class="metric"><small>${esc(l)}</small><strong>${esc(v)}</strong></div>`).join('')}</div>`:notice(`Total de registros: ${data.summary.total}`);
        $('#report-result').innerHTML=panel(reportTypes.find(([k])=>k===filter.type)[1],metrics+table(data.headers,data.rows.map(row=>row.map(esc)))+`<div class="actions no-print"><a class="btn primary" href="/api/reports?${esc(query.toString())}&amp;format=csv">Descargar CSV</a>${button('Imprimir','id="print-report"','')}</div>`);on('#print-report','click',()=>window.print());
    });
}});


const ui = { $, state, views, api, esc, full, code, badge, field, select, textarea, personOptions, localDate, table, button, modal, formErrors, actionButtons, on, closeModal, bindForm, references, navigate, toast, confirmAction, panel, time, days, doctorOptions, notice, submit, main, details, loginView, heading, home, logout, getTermsVersion: () => termsVersion };
installSessionViews(ui);
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
$('#menu-toggle').addEventListener('click',()=>setMenu(!$('#sidebar').classList.contains('open')));
$('#menu-backdrop').addEventListener('click',()=>setMenu(false,true));
document.addEventListener('keydown',event=>{
    if(!$('#sidebar').classList.contains('open') || $('#modal').open)return;
    if(event.key==='Escape'){event.preventDefault();setMenu(false,true);}
    if(event.key==='Tab'){
        const items=[$('#menu-toggle'),...$$('#sidebar input, #sidebar button').filter(el=>el.getClientRects().length)];
        const index=items.indexOf(document.activeElement);
        if(event.shiftKey&&index===0){event.preventDefault();items.at(-1).focus();}
        else if(!event.shiftKey&&index===items.length-1){event.preventDefault();items[0].focus();}
    }
});
window.matchMedia('(min-width: 961px)').addEventListener('change',event=>{if(event.matches)setMenu(false);});
new ResizeObserver(entries=>document.documentElement.style.setProperty('--header-height',`${entries[0].target.getBoundingClientRect().height}px`)).observe($('.topbar'));
window.addEventListener('hashchange',()=>{const view=viewFromHash(location.hash);if(state.user&&view&&view!==state.view)navigate(view,{replace:true});});
async function boot({requireAuthentication=false}={}) {
    try {
        const result=await api('session');
        state.user=result.user;access=result.access||{};termsVersion=result.terms_version;
        if(requireAuthentication&&!state.user)throw new Error(result.message||'No se pudo conservar la sesión. Permita las cookies de esta página e intente nuevamente.');
        if(state.user&&!access[state.user.role]?.includes('CU02'))throw new Error('No se pudieron cargar los permisos de su cuenta. Recargue la página o reintente la carga del panel.');
        if(state.user)await references();
        const activationRequested = !state.user && location.hash === '#activar-cuenta';
        const requested=viewFromHash(location.hash);
        await navigate(state.user&&access[state.user.role]?.includes(requested)?requested:state.user?home():'CU01',{replace:true});
        if(activationRequested)views.activation();
        return Boolean(state.user);
    } catch(error) {
        shell();
        main.innerHTML=notice(error.message,'error')+button('Reintentar','id="boot-retry"')+(!state.user?button('Volver al inicio de sesión','id="boot-login"',''):'');
        on('#boot-retry','click',()=>boot({requireAuthentication}));
        on('#boot-login','click',()=>navigate('CU01'));
        return false;
    }
}
boot();
