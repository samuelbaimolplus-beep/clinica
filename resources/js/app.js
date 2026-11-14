/** Arranque de la interfaz: sesión, navegación por rol, formularios, diálogos y limpieza de información privada. */
import { instalarVistasCatalogos } from "./modules/catalogos";
import { instalarVistasHorarios } from "./modules/horarios";
import { instalarVistasPacientes } from "./modules/pacientes";
import { instalarVistasAuditoria } from "./modules/auditoria";
import { instalarVistasAfectaciones } from "./modules/afectaciones";
import { instalarVistasAjustesAgenda } from "./modules/ajustes-agenda";
import { crearClienteApi } from './lib/api';
import { instalarAccionesClinicas } from "./modules/acciones-clinicas";
import { botonVisibilidadContrasena } from "./lib/visibilidad-contrasena";
import { gruposNavegacion, panelesPorRol, navegacionVisible, rutaParaVista, vistaDesdeFragmento, descripcionesVistas } from "./lib/navegacion";
import { icono, iconoVista } from "./lib/iconos";
import { instalarVistasSupervision } from "./modules/supervision";
import { instalarBandejaRecepcion } from "./modules/bandeja-recepcion";
import { instalarVistasCuentas } from "./modules/cuentas";
import { instalarVistasSesion } from "./modules/sesion";
import { instalarAreaTrabajo } from "./modules/area-trabajo";
import { instalarFlujoReserva } from "./modules/flujo-reservas";
import { instalarAreaCitas } from "./modules/area-citas";
import { instalarAreaFinanzas } from "./modules/area-finanzas";
import { instalarHerramientasContextuales } from "./modules/herramientas-contextuales";

const $ = (s, raiz = document) => raiz.querySelector(s);
const $$ = (s, raiz = document) => [...raiz.querySelectorAll(s)];
const escapar = valorDato => String(valorDato ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const nombreCompleto = p => p ? `${p.first_name} ${p.last_name}` : '—';
const codigo = (prefijo, id) => `${prefijo}-${String(id).padStart(prefijo === 'CIT' ? 4 : 3, '0')}`;
const hora = t => String(t || '').slice(0, 5);
const estados = ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'];
const dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
const vigente = a => ['Programada', 'Confirmada'].includes(a.status);
const fechaLocalLocal = () => new Intl.DateTimeFormat('sv-SE', {timeZone:'America/La_Paz'}).format(new Date());
const ciclos = gruposNavegacion;
const titulos = Object.fromEntries(ciclos.flatMap(c => c[1]));
let acceso = {};
let versionTerminos = '';
const inicioLocal = () => 'inicio';
let actualizarBandeja = () => {};
const estadoLocal = {user:null, csrf:$('meta[name="csrf-token"]').content, view:'CU01', refs:{doctors:[],specialties:[],patients:[]}, sequence:0};
const principal = $('#main');
/** Elimina borradores y contenido de diálogos al cerrar o revocar la sesión. */
function limpiarAreaPrivada() {
    for(const clave of ['bookingDraft','workflowContext','flowFeedback','returnToBooking','openAppointmentId','scheduleDoctor'])delete estadoLocal[clave];
    cerrarModal();
    window.dispatchEvent(new Event('clinic:identity-changed'));
}
const panel = (titulo, contenido) => `<section class="panel">${titulo ? `<h3>${escapar(titulo)}</h3>` : ''}${contenido}</section>`;
const distintivo = s => `<span class="badge ${escapar(s)}">${escapar(s)}</span>`;
const aviso = (s, tipo = '') => `<div class="notice ${tipo}">${escapar(s)}</div>`;
const boton = (etiqueta, atributos = '', estilo = 'primary') => `<button type="button" class="btn ${estilo}" ${atributos}>${escapar(etiqueta)}</button>`;
const tabla = (cabeceras, filas) => `<div class="table-wrap" tabindex="0" role="region" aria-label="Listado de registros"><table><thead><tr>${cabeceras.map(h=>`<th scope="col">${escapar(h)}</th>`).join('')}</tr></thead><tbody>${filas.length ? filas.map(fila=>`<tr>${fila.map((c,i)=>`<td data-label="${escapar(cabeceras[i])}"><div class="cell-content">${c === '' || c == null ? '—' : c}</div></td>`).join('')}</tr>`).join('') : `<tr><td colspan="${cabeceras.length}" class="empty"><span class="empty-icon">${icono('file')}</span><strong>No hay resultados para mostrar</strong><span>Los registros aparecerán aquí cuando estén disponibles. Si aplicó filtros, pruebe con otros criterios.</span></td></tr>`}</tbody></table></div><p class="result-count">${filas.length} ${filas.length===1?'registro':'registros'}</p>`;
const campo = (etiqueta, nombreLocal, tipo = 'text', valorDato = '', atributos = '') => {
    const entrada = `<input name="${escapar(nombreLocal)}" id="${escapar(nombreLocal)}" type="${tipo}" value="${escapar(valorDato)}" ${atributos}>`;
    if (tipo === 'password') return `<div class="field"><label for="${escapar(nombreLocal)}">${escapar(etiqueta)}</label><div class="password-control">${entrada}${botonVisibilidadContrasena(escapar(nombreLocal))}</div></div>`;
    return `<label class="field"><span>${escapar(etiqueta)}</span>${entrada}</label>`;
};
const seleccionar = (etiqueta, nombreLocal, opciones, valorDato = '', atributos = '') => `<label class="field"><span>${escapar(etiqueta)}</span><select name="${escapar(nombreLocal)}" id="${escapar(nombreLocal)}" ${atributos}>${opciones.map(o=>{const [v,l] = Array.isArray(o) ? o : [o,o]; return `<option value="${escapar(v)}" ${String(valorDato) === String(v) ? 'selected' : ''}>${escapar(l)}</option>`;}).join('')}</select></label>`;
const areaTexto = (etiqueta, nombreLocal, valorDato = '', atributos = '') => `<label class="field full"><span>${escapar(etiqueta)}</span><textarea name="${escapar(nombreLocal)}" rows="3" ${atributos}>${escapar(valorDato)}</textarea></label>`;
const detalles = entradas => `<dl class="details">${entradas.map(([k,v])=>`<div><dt>${escapar(k)}</dt><dd>${v}</dd></div>`).join('')}</dl>`;
const opcionesPersonas = lista => lista.map(p=>[p.id, nombreCompleto(p)]);
const opcionesEspecialidades = () => estadoLocal.refs.specialties.filter(s=>s.status==='Activo').map(s=>[s.id,s.name]);
const opcionesMedicos = (especialidad = null) => opcionesPersonas(estadoLocal.refs.doctors.filter(d=>d.status==='Activo' && (!especialidad || d.specialties.some(s=>String(s.id)===String(especialidad)))));
const botonGuardar = etiqueta => `<div class="actions"><button class="btn success" type="submit">${escapar(etiqueta)}</button></div>`;
const erroresFormulario = '<div class="form-errors" role="alert"></div>';
const botonesAccion = contenido => `<div class="actions end">${contenido}</div>`;
/** Muestra una confirmación o error temporal sin insertar HTML recibido del usuario. */
function notificacion(mensajeLocal, fallo = false) {
    const caja = document.createElement('div'); caja.className = `toast ${fallo?'error':''}`; caja.textContent = mensajeLocal;
    $('#toasts').append(caja); setTimeout(()=>caja.remove(), 6000);
}
/** Convierte errores de una acción de interfaz en mensajes visibles al usuario. */
function ejecutarSeguro(funcion) { return async evento => { try { await funcion(evento); } catch (e) { notificacion(e.message, true); } }; }
/** Enlaza eventos únicamente a los elementos del componente indicado. */
function enlazarEvento(selectorElemento, evento, funcion, raiz = document) { $$(selectorElemento, raiz).forEach(el=>el.addEventListener(evento,ejecutarSeguro(funcion))); }
const clienteApi = crearClienteApi({
    getToken: () => estadoLocal.csrf,
    setToken: token => { estadoLocal.csrf = token; $('meta[name="csrf-token"]').content = token; },
    onUnauthorized: async mensajeLocal => {
        limpiarAreaPrivada();
        estadoLocal.user = null;
        estadoLocal.refs = {doctors: [], specialties: [], patients: []};
        await navegar('CU01');
        notificacion(mensajeLocal, true);
    },
});
/** Evita doble envío, presenta errores por campo y espera confirmación del servidor. */
function enlazarFormulario(id, funcionRetorno) {
    const formularioLocal = $(`#${id}`);
    formularioLocal.addEventListener('submit', async evento => {
        evento.preventDefault(); const errores = $('.form-errors', formularioLocal); if(errores)errores.innerHTML='';
        formularioLocal.querySelectorAll('[aria-invalid]').forEach(entrada=>entrada.removeAttribute('aria-invalid'));
        const botonElemento = $('button[type=submit]', formularioLocal); if(botonElemento?.disabled)return; const etiqueta=botonElemento?.innerHTML;
        if(botonElemento){botonElemento.disabled = true;botonElemento.setAttribute('aria-busy','true');botonElemento.textContent=id==='login-form'?'Ingresando…':'Guardando…';}
        try { await funcionRetorno(Object.fromEntries(new FormData(formularioLocal)), formularioLocal); }
        catch(e) {
            const mensajes = e.errors ? Object.values(e.errors).flat() : [e.message];
            Object.keys(e.errors || {}).forEach(nombreLocal=>formularioLocal.elements.namedItem(nombreLocal)?.setAttribute?.('aria-invalid','true'));
            if(errores) { errores.innerHTML = `<div class="notice error"><ul>${mensajes.map(m=>`<li>${escapar(m)}</li>`).join('')}</ul></div>`; errores.scrollIntoView({block:'nearest'}); }
            else notificacion(mensajes.join(' '), true);
        } finally { if(botonElemento){botonElemento.disabled=false;botonElemento.removeAttribute('aria-busy');botonElemento.innerHTML=etiqueta;} }
    });
}
function modal(titulo, contenido) {
    $('#modal-title').textContent = titulo; $('#modal-body').innerHTML = contenido;
    if(!$('#modal').open)$('#modal').showModal();
}
/** Cierra y vacía el diálogo para no conservar datos de otra operación o identidad. */
function cerrarModal() { $('#modal').close(); $('#modal-body').replaceChildren(); $('#modal-title').textContent=''; }
/** Pide confirmación de una acción concreta y espera el resultado de su ejecución. */
function confirmarAccion(titulo, mensajeLocal, funcionRetorno, peligro = false) {
    modal(titulo, `<p>${escapar(mensajeLocal)}</p>${botonesAccion(boton('Cancelar','data-dismiss','')+boton('Confirmar','id="confirm-action"',peligro?'danger':'success'))}`);
    enlazarEvento('[data-dismiss]','click',cerrarModal);
    enlazarEvento('#confirm-action','click',async evento=>{evento.target.disabled=true;try { const despuesCerrar = await funcionRetorno(); cerrarModal(); if(typeof despuesCerrar==='function')despuesCerrar(); } finally {evento.target.disabled=false;}});
}
async function cargarReferencias() { estadoLocal.refs = await clienteApi('references'); }
/** Presenta el marco de navegación según el rol y permisos actuales. */
function estructuraPagina() {
    const usuario = estadoLocal.user;
    $('#sidebar').hidden = !usuario;
    $('#menu-toggle').hidden = !usuario;
    document.body.classList.toggle('guest',!usuario);
    $('#profile').innerHTML = usuario ? `<button class="profile-account" id="profile-account" aria-label="Mi cuenta y seguridad"><span class="profile-info"><strong>${escapar(usuario.name)}</strong><small>${escapar(usuario.role)}</small></span><span class="avatar" aria-hidden="true">${escapar(usuario.name.slice(0,1))}</span></button><button type="button" class="icon-btn logout-button" id="logout-button" aria-label="Cerrar sesión" title="Cerrar sesión">${icono('logout')}</button>` : '<span class="secure-access">'+icono('shield')+' Acceso seguro</span>';
    actualizarBandeja();
    if(!usuario)return;
    const perfil = panelesPorRol[usuario.role];
    $('#sidebar').innerHTML = `<div class="role-panel"><span class="workspace-label">SU ESPACIO DE TRABAJO</span><strong>${escapar(perfil?.title || usuario.role)}</strong><p>${escapar(perfil?.description || '')}</p></div><button type="button" class="nav-btn ${estadoLocal.view==='inicio'?'selected':''}" data-home ${estadoLocal.view==='inicio'?'aria-current="page"':''}>${icono('calendar')}<span>Inicio y acciones</span></button><label class="nav-search">${icono('search')}<input id="nav-search" type="search" placeholder="Buscar en el menú" aria-label="Buscar en el menú" autocomplete="off"></label><div class="nav-sections">`
        + navegacionVisible(usuario.role, acceso[usuario.role] || []).map(([titulo,enlaces])=>`<nav aria-label="${escapar(titulo)}"><h3>${escapar(titulo)}</h3>${enlaces.map(([id,etiqueta])=>`<button class="nav-btn ${estadoLocal.view===id?'selected':''}" data-view="${id}" ${estadoLocal.view===id?'aria-current="page"':''}>${icono(iconoVista(id))}<span>${escapar(etiqueta)}</span>${estadoLocal.view===id?'<span class="nav-active-dot" aria-hidden="true"></span>':''}</button>`).join('')}</nav>`).join('')+'</div><p id="nav-empty" class="nav-empty" hidden>No encontramos esa opción. Pruebe con otro nombre.</p><div class="sidebar-foot">Clínica Vida Salud<small>Atención y gestión en un solo lugar</small></div>';
    enlazarEvento('[data-view]','click',e=>navegar(e.currentTarget.dataset.view));
    enlazarEvento('[data-home]','click',()=>navegar('inicio'));
    enlazarEvento('#nav-search','input',e=>{
        const normalizar=valorDato=>valorDato.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
        const consulta=normalizar(e.target.value.trim());
        $$('#sidebar nav').forEach(navegacionElemento=>{$$('.nav-btn',navegacionElemento).forEach(enlaceAccion=>enlaceAccion.hidden=!normalizar(enlaceAccion.textContent).includes(consulta));navegacionElemento.hidden=!$('.nav-btn:not([hidden])',navegacionElemento);});
        $('#nav-empty').hidden=Boolean($('#sidebar nav:not([hidden])'));
    });
    enlazarEvento('#profile-account','click',()=>navegar('CU02'));
    enlazarEvento('#logout-button','click',cerrarSesion);
}
function cerrarSesion() { confirmarAccion('Cerrar sesión',`¿Desea cerrar la sesión de ${estadoLocal.user.name}?`,async()=>{await clienteApi('logout','POST',{});limpiarAreaPrivada();estadoLocal.user=null;estadoLocal.refs={doctors:[],specialties:[],patients:[]};await navegar('CU01');notificacion('Sesión cerrada correctamente.');},true); }
function encabezado() {
    const grupo=navegacionVisible(estadoLocal.user.role, acceso[estadoLocal.user.role] || []).find(([,enlaces])=>enlaces.some(([id])=>id===estadoLocal.view));
    const titulo=estadoLocal.view==='inicio'?'Inicio y acciones':grupo?.[1].find(([id])=>id===estadoLocal.view)?.[1] || titulos[estadoLocal.view];
    const descripcion=estadoLocal.user.role==='Paciente'&&estadoLocal.view==='CU15'
        ?'Consulta tus citas y sus cambios. Solo se muestran registros vinculados a tu cuenta.'
        :estadoLocal.view==='inicio'?'Sus tareas y herramientas, organizadas según su rol.':descripcionesVistas[estadoLocal.view] || '';
    document.title=`${titulo} · Clínica Vida Salud`;
    return `<div class="page-heading"><div><span class="eyebrow">${escapar(grupo?.[0] || 'Clínica Vida Salud')}</span><h1>${escapar(titulo)}</h1><p>${escapar(descripcion)}</p></div><span class="page-date">${icono('calendar')}${new Intl.DateTimeFormat('es-BO',{day:'numeric',month:'short',year:'numeric',timeZone:'America/La_Paz'}).format(new Date())}</span></div>`;
}
function configurarMenu(abrir, restaurarFoco=false) {
    $('#sidebar').classList.toggle('open',abrir);
    $('#menu-toggle').setAttribute('aria-expanded',String(abrir));
    $('#menu-toggle').setAttribute('aria-label',abrir?'Cerrar menú':'Abrir menú');
    $('#menu-backdrop').hidden=!abrir;
    document.body.classList.toggle('menu-open',abrir);
    principal.inert=abrir;
    // Abrir la navegación no debe activar el teclado virtual del buscador.
    if(abrir || restaurarFoco)$('#menu-toggle').focus({preventScroll:true});
}
/** Controla permisos, secuencia de carga, navegación y foco antes de enlazar una vista. */
async function navegar(vista, {replace: reemplazar=false}={}) {
    if (!estadoLocal.user) vista = 'CU01';
    else if (vista!=='inicio'&&!acceso[estadoLocal.user.role]?.includes(vista)) { notificacion('Su rol no tiene acceso a esta función.',true); return; }
    else if(vista==='CU01')vista=inicioLocal();
    estadoLocal.view = vista; estadoLocal.sequence++; cerrarModal(); estructuraPagina();
    const destino=estadoLocal.user?`#${rutaParaVista(vista)}`:location.pathname+location.search;
    if(!estadoLocal.user || reemplazar)history.replaceState(null,'',destino);
    else if(location.hash!==destino)history.pushState(null,'',destino);
    configurarMenu(false);
    if(vista==='CU01')return vistaInicioSesion();
    principal.setAttribute('aria-busy','true');
    principal.innerHTML = encabezado()+'<div class="loading-state" role="status"><span class="spinner"></span><p>Cargando información…</p></div>';
    const secuenciaCorta = estadoLocal.sequence;
    try { const contenidoTexto = await vistas[vista](); if(secuenciaCorta !== estadoLocal.sequence)return; principal.innerHTML=encabezado()+contenidoTexto.html; contenidoTexto.bind?.();
        enlazarEvento('[data-open-view]','click',e=>navegar(e.currentTarget.dataset.openView),principal);
    }
    catch(e) { if(secuenciaCorta===estadoLocal.sequence)principal.innerHTML=encabezado()+aviso(e.message,'error')+boton('Reintentar','id="retry"'); enlazarEvento('#retry','click',()=>navegar(vista)); }
    if(secuenciaCorta===estadoLocal.sequence){principal.removeAttribute('aria-busy');principal.focus({preventScroll:true});}
    window.scrollTo(0,0);
}
/** Muestra acceso y requisitos pendientes sin abrir una sesión hasta su verificación. */
function vistaInicioSesion() {
    document.title='Bienvenido · Clínica Vida Salud';
    principal.removeAttribute('aria-busy');
    principal.innerHTML = `<div class="login-layout"><section class="login-story"><span class="story-kicker">CLÍNICA VIDA SALUD</span><h2>Más cerca de usted.<br>Más fácil de gestionar.</h2><p>Un espacio para cuidar su tiempo y acompañar su atención médica.</p><div class="story-features"><div>${icono('calendar')}<span><strong>Su próxima consulta, a un paso</strong><small>Consulte horarios y gestione sus citas.</small></span></div><div>${icono('file')}<span><strong>Todo a mano</strong><small>Revise sus citas y el estado de sus solicitudes.</small></span></div><div>${icono('shield')}<span><strong>Acceso personal y protegido</strong><small>Acceso del personal y portal opcional para pacientes adultos.</small></span></div></div><div class="story-footer"><span class="brand-mark">✚</span><span>Su salud, nuestra prioridad<small>Portal de atención y gestión</small></span></div></section><div class="login-wrap">${panel('',`<div class="login-heading"><span class="eyebrow">BIENVENIDO A SU CLÍNICA</span><h1>Iniciar sesión</h1><p>Ingrese con su cuenta para continuar.</p></div><form id="login-form">${erroresFormulario}${campo('Usuario o correo electrónico','email','text','','required maxlength="255" autocomplete="username" placeholder="Su usuario o correo"')}${campo('Contraseña','password','password','','required maxlength="128" autocomplete="current-password"')}${botonGuardar('Iniciar sesión')}</form><div class="account-invitation"><strong>¿Es su primera visita?</strong><p>La atención no requiere cuenta. Si tiene 18 años cumplidos y desea usar el portal, acuda a la clínica para solicitarlo y aceptar sus términos. Si ya recibió el código por correo, active su cuenta.</p>${boton('Activar mi cuenta','id="activate-account"','link')}</div>`)}<p class="login-foot">Clínica Vida Salud · Atención para personal y pacientes</p></div></div>`;
    enlazarEvento('#activate-account', 'click', () => vistas.activation());
    $('#login-form').insertAdjacentHTML('afterend', boton('Recuperar acceso o verificar correo', 'id="recover-account"', 'link'));
    enlazarEvento('#recover-account', 'click', () => vistas.recovery());
    enlazarFormulario('login-form', async datos=>{
        try { await clienteApi('login','POST',datos); }
        catch(fallo) {
            if(fallo.data?.eligibility_required) {
                let caja=$('#login-eligibility');
                if(!caja){caja=document.createElement('div');caja.id='login-eligibility';$('#login-form .actions').before(caja);}
                caja.innerHTML=aviso('Verifique estos datos antes de acceder. La atención en la clínica no requiere una cuenta de paciente.')
                    +(fallo.data.birth_date_required?campo('Fecha de nacimiento','birth_date','date','','required'):'')
                    +(fallo.data.terms_required?`<label class="terms-accept"><input type="checkbox" name="terms_accepted" value="1" required>Acepto los <a href="/terms" target="_blank" rel="noopener">términos y condiciones</a> del portal, versión ${escapar(fallo.data.terms_version)}.</label><input type="hidden" name="terms_version" value="${escapar(fallo.data.terms_version)}">`:'');
                caja.querySelector('input')?.focus();return;
            }
            if(fallo.data?.activation_required) {vistas.activation(fallo.data.email);notificacion(fallo.message);return;}
            if(fallo.status!==409)throw fallo;
        }
        // Leer la sesión ya persistida renueva también los permisos de pestañas antiguas.
        if(await iniciarAplicacion({requireAuthentication:true}))notificacion(`Bienvenido, ${estadoLocal.user.name}.`);
    });
}
const vistas = {};

// CU16: siete reportes, porcentajes, impresión y exportación CSV.
const tiposInforme=[['REP01','Citas por fecha'],['REP02','Citas por médico'],['REP03','Citas por especialidad'],['REP04','Citas por estado'],['REP05','Pacientes registrados'],['REP06','Resumen de atenciones por período'],['REP07','Agenda diaria por médico']];
let filtroInforme={type:'REP01',date:fechaLocalLocal(),from:fechaLocalLocal().slice(0,8)+'01',to:fechaLocalLocal()};
vistas.CU16=async()=>({html:panel('Generador de reportes administrativos',`<form id="report-form">${erroresFormulario}<div class="grid four">${seleccionar('Tipo de reporte','type',tiposInforme.map(([k,n])=>[k,n]),filtroInforme.type)}<div data-report-field="period">${seleccionar('Consultar por','period_mode',[['date','Fecha específica'],['range','Período']],filtroInforme.period_mode||'date')}</div><div data-report-field="date">${campo('Fecha','date','date',filtroInforme.date)}</div><div data-report-field="range">${campo('Desde','from','date',filtroInforme.from)}</div><div data-report-field="range">${campo('Hasta','to','date',filtroInforme.to)}</div><div data-report-field="doctor">${seleccionar('Médico','doctor_id',[['','Seleccione un médico…'],...opcionesPersonas(estadoLocal.refs.doctors)],filtroInforme.doctor_id)}</div><div data-report-field="specialty">${seleccionar('Especialidad','specialty_id',[['','Seleccione una especialidad…'],...estadoLocal.refs.specialties.map(s=>[s.id,s.name])],filtroInforme.specialty_id)}</div><div data-report-field="status">${seleccionar('Estado','status',[['','Todos los estados'],...estados],filtroInforme.status)}</div></div>${botonGuardar('Generar reporte')}</form>`)+`<div id="report-result"></div>`,bind:()=>{
    const actualizarDato=()=>{const tipo=$('#type').value;const visible={period:tipo==='REP01',date:tipo==='REP07'||(tipo==='REP01'&&$('#period_mode').value==='date'),range:['REP02','REP03','REP04','REP06'].includes(tipo)||(tipo==='REP01'&&$('#period_mode').value==='range'),doctor:['REP02','REP07'].includes(tipo),specialty:tipo==='REP03',status:tipo==='REP04'};$$('[data-report-field]').forEach(el=>{el.hidden=!visible[el.dataset.reportField];$$('input,select',el).forEach(entrada=>{entrada.disabled=el.hidden;entrada.required=!el.hidden&&['date','from','to','doctor_id','specialty_id'].includes(entrada.name);});});$('#report-result').innerHTML='';};
    enlazarEvento('#type,#period_mode','change',actualizarDato);actualizarDato();
    enlazarFormulario('report-form',async d=>{
        const filtro=Object.fromEntries(Object.entries(d).filter(([,v])=>v));filtroInforme={...filtroInforme,...filtro};
        const consulta=new URLSearchParams(filtro);const datos=await clienteApi('reports?'+consulta);
        const indicadores=filtro.type==='REP06'?`<div class="metrics">${[['Total de citas',datos.summary.total],...['Atendida','Cancelada','Inasistencia'].map(s=>[s,`${datos.summary[s].count} · ${datos.summary[s].percentage}%`])].map(([l,v])=>`<div class="metric"><small>${escapar(l)}</small><strong>${escapar(v)}</strong></div>`).join('')}</div>`:aviso(`Total de registros: ${datos.summary.total}`);
        $('#report-result').innerHTML=panel(tiposInforme.find(([k])=>k===filtro.type)[1],indicadores+tabla(datos.headers,datos.rows.map(fila=>fila.map(escapar)))+`<div class="actions no-print"><a class="btn primary" href="/api/reports?${escapar(consulta.toString())}&amp;format=csv">Descargar CSV</a>${boton('Imprimir','id="print-report"','')}</div>`);enlazarEvento('#print-report','click',()=>window.print());
    });
}});


const interfaz = { $, estadoLocal: estadoLocal, vistas: vistas, clienteApi: clienteApi, escapar: escapar, nombreCompleto: nombreCompleto, codigo: codigo, distintivo: distintivo, campo: campo, seleccionar: seleccionar, areaTexto: areaTexto, opcionesPersonas: opcionesPersonas, fechaLocalLocal: fechaLocalLocal, tabla: tabla, boton: boton, modal, erroresFormulario: erroresFormulario, botonesAccion: botonesAccion, enlazarEvento: enlazarEvento, cerrarModal: cerrarModal, enlazarFormulario: enlazarFormulario, cargarReferencias: cargarReferencias, navegar: navegar, notificacion: notificacion, confirmarAccion: confirmarAccion, panel, hora: hora, dias: dias, opcionesMedicos: opcionesMedicos, aviso: aviso, botonGuardar: botonGuardar, principal: principal, detalles: detalles, vistaInicioSesion: vistaInicioSesion, encabezado: encabezado, inicioLocal: inicioLocal, cerrarSesion: cerrarSesion, icono: icono, vistasPermitidas:()=>acceso[estadoLocal.user?.role]||[], obtenerVersionTerminos: () => versionTerminos };
instalarAreaTrabajo(interfaz);
instalarVistasSesion(interfaz);
instalarVistasCatalogos(interfaz);
instalarVistasHorarios(interfaz);
instalarVistasPacientes(interfaz);
instalarVistasAuditoria(interfaz);
instalarVistasAfectaciones(interfaz);
instalarVistasAjustesAgenda(interfaz);
instalarAccionesClinicas(interfaz);
instalarFlujoReserva(interfaz);
instalarAreaCitas(interfaz);
instalarAreaFinanzas(interfaz);
instalarVistasSupervision(interfaz);
instalarVistasCuentas(interfaz);
instalarHerramientasContextuales(interfaz);
actualizarBandeja = instalarBandejaRecepcion(interfaz);

$('#close-modal').addEventListener('click',cerrarModal);
$('#modal').addEventListener('click',e=>{if(e.target===$('#modal')){const rectangulo=$('#modal').getBoundingClientRect();if(e.clientX<rectangulo.left||e.clientX>rectangulo.right||e.clientY<rectangulo.top||e.clientY>rectangulo.bottom)cerrarModal();}});
$('#menu-toggle').addEventListener('click',()=>configurarMenu(!$('#sidebar').classList.contains('open')));
$('#menu-backdrop').addEventListener('click',()=>configurarMenu(false,true));
document.addEventListener('keydown',evento=>{
    if(!$('#sidebar').classList.contains('open') || $('#modal').open)return;
    if(evento.key==='Escape'){evento.preventDefault();configurarMenu(false,true);}
    if(evento.key==='Tab'){
        const elementos=[$('#menu-toggle'),...$$('#sidebar input, #sidebar button').filter(el=>el.getClientRects().length)];
        const indice=elementos.indexOf(document.activeElement);
        if(evento.shiftKey&&indice===0){evento.preventDefault();elementos.at(-1).focus();}
        else if(!evento.shiftKey&&indice===elementos.length-1){evento.preventDefault();elementos[0].focus();}
    }
});
window.matchMedia('(min-width: 961px)').addEventListener('change',evento=>{if(evento.matches)configurarMenu(false);});
new ResizeObserver(entradas=>document.documentElement.style.setProperty('--header-height',`${entradas[0].target.getBoundingClientRect().height}px`)).observe($('.topbar'));
window.addEventListener('hashchange',()=>{const vista=vistaDesdeFragmento(location.hash);if(estadoLocal.user&&vista&&vista!==estadoLocal.view)navegar(vista,{replace:true});});
/** Recupera identidad, permisos y referencias antes de mostrar operaciones privadas. */
async function iniciarAplicacion({requireAuthentication: exigirAutenticacion=false}={}) {
    try {
        const resultado=await clienteApi('session');
        if(estadoLocal.sessionOwnerId!==resultado.user?.id){limpiarAreaPrivada();estadoLocal.sessionOwnerId=resultado.user?.id;}
        estadoLocal.user=resultado.user;acceso=resultado.access||{};versionTerminos=resultado.terms_version;
        if(exigirAutenticacion&&!estadoLocal.user)throw new Error(resultado.message||'No se pudo conservar la sesión. Permita las cookies de esta página e intente nuevamente.');
        if(estadoLocal.user&&!acceso[estadoLocal.user.role]?.includes('CU02'))throw new Error('No se pudieron cargar los permisos de su cuenta. Recargue la página o reintente la carga del panel.');
        if(estadoLocal.user)await cargarReferencias();
        const activacionSolicitada = !estadoLocal.user && location.hash === '#activar-cuenta';
        const solicitada=vistaDesdeFragmento(location.hash);
        await navegar(estadoLocal.user&&(solicitada==='inicio'||acceso[estadoLocal.user.role]?.includes(solicitada))?solicitada:estadoLocal.user?inicioLocal():'CU01',{replace:true});
        if(activacionSolicitada)vistas.activation();
        return Boolean(estadoLocal.user);
    } catch(fallo) {
        estructuraPagina();
        principal.innerHTML=aviso(fallo.message,'error')+boton('Reintentar','id="boot-retry"')+(!estadoLocal.user?boton('Volver al inicio de sesión','id="boot-login"',''):'');
        enlazarEvento('#boot-retry','click',()=>iniciarAplicacion({requireAuthentication: exigirAutenticacion}));
        enlazarEvento('#boot-login','click',()=>navegar('CU01'));
        return false;
    }
}
iniciarAplicacion();
