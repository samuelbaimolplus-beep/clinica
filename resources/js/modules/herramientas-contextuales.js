/** Presenta reportes, auditoría y ajustes de cupos mediante decisiones contextuales antes de pedir criterios. */
import {desplazarFecha} from "../lib/flujo-clinico";

export function instalarHerramientasContextuales(interfaz) {
 const {$,estadoLocal: estadoLocal,vistas: vistas,boton: boton,escapar: escapar,fechaLocalLocal: fechaLocalLocal,nombreCompleto: nombreCompleto,enlazarEvento: enlazarEvento}=interfaz;
 const informe=vistas.CU16;
 const informes=[['REP01','Citas de una fecha','Revise quién tiene cita y el estado de su atención.'],['REP02','Actividad de un médico','Consulte las citas de un profesional en un período.'],['REP03','Demanda por especialidad','Revise las citas de un servicio de la clínica.'],['REP04','Citas por resultado','Separe citas atendidas, canceladas o pendientes.'],['REP05','Pacientes registrados','Consulte las fichas, tengan o no una cuenta del portal.'],['REP06','Resumen de atenciones','Compare atención, cancelaciones e inasistencias.'],['REP07','Agenda diaria de un médico','Prepare la lista de pacientes de una jornada.']];
 vistas.CU16=async()=>{
  const resultado=await informe();
  return {html:`<div class="workspace-heading"><div><h2>¿Qué información necesita revisar?</h2><p>Elija un informe. Solo se pedirán los criterios que necesita ese reporte.</p></div></div><div class="report-choices">${informes.map(([id,titulo,copiar])=>`<button type="button" class="report-choice" data-report-choice="${id}"><span>↗</span><strong>${titulo}</strong><small>${copiar}</small></button>`).join('')}</div><section id="chosen-report" hidden><div id="report-presets" class="status-filters">${[['today','Hoy'],['week','Últimos 7 días'],['month','Este mes']].map(([id,etiqueta])=>boton(etiqueta,`data-report-period="${id}"`,'')).join('')}</div>${resultado.html}</section>`,bind:()=>{
   resultado.bind?.();
   $('#type').closest('label').hidden=true;
   enlazarEvento('[data-report-choice]','click',e=>{
    $('#type').value=e.currentTarget.dataset.reportChoice;$('#type').dispatchEvent(new Event('change'));
    $('#chosen-report').hidden=false;
    document.querySelectorAll('[data-report-choice]').forEach(b=>b.classList.toggle('chosen',b===e.currentTarget));
    if(['REP01','REP05'].includes(e.currentTarget.dataset.reportChoice))$('#report-form').requestSubmit();
    $('#chosen-report').scrollIntoView({block:'start',behavior:'smooth'});
   });
   enlazarEvento('[data-report-period]','click',e=>{
    const periodo=e.currentTarget.dataset.reportPeriod;const hoy=fechaLocalLocal();
    $('#date').value=hoy;$('#from').value=periodo==='week'?desplazarFecha(hoy,-6):periodo==='month'?hoy.slice(0,8)+'01':hoy;$('#to').value=hoy;
    $('#period_mode').value=periodo==='today'?'date':'range';$('#period_mode').dispatchEvent(new Event('change'));
    if($('#report-form').reportValidity())$('#report-form').requestSubmit();
   });
  }};
 };
 const auditoria=vistas.CU22;
 vistas.CU22=async()=>{
  const resultado=await auditoria();
  return {html:resultado.html,bind:()=>{
   resultado.bind?.();const formularioLocal=$('#audit-form');const seccion=formularioLocal.closest('section');
   const detalles=document.createElement('details');detalles.className='advanced-options';detalles.innerHTML='<summary>Filtrar la actividad por fecha, responsable o proceso</summary>';
   seccion.querySelector('h3')?.remove();detalles.append(formularioLocal);seccion.replaceChildren(detalles);
   seccion.parentElement.append(seccion);
  }};
 };
 for(const vista of ['CU24','CU25']){
  const original=vistas[vista];
  vistas[vista]=async()=>{
   const resultado=await original();
   return {html:resultado.html,bind:()=>{
    resultado.bind?.();
    if(vista==='CU24'){
     const formularioLocal=$('#demand-proposal-form'),seleccionar=formularioLocal.elements.specialty_id,numeroElementos=formularioLocal.elements.quantity;
     seleccionar.closest('label').hidden=true;numeroElementos.closest('label').hidden=true;
     const alternativas=document.createElement('div');alternativas.className='specialty-demand-picker';alternativas.innerHTML=`<h3>¿Para qué especialidad hay personas esperando hoy?</h3><div class="doctor-chips">${estadoLocal.refs.specialties.filter(s=>s.status==='Activo').map(s=>`<button type="button" class="filter-chip ${String(s.id)===seleccionar.value?'selected':''}" data-demand-specialty="${s.id}">${escapar(s.name)}</button>`).join('')}</div><div class="quantity-picker"><div><strong>Personas presentes o estimadas</strong><p>Se buscarán cupos presenciales libres para esta demanda; todavía no se crean citas.</p></div>${boton('−','data-quantity="-1" aria-label="Quitar una persona"','')}<output id="demand-count">${numeroElementos.value}</output>${boton('+','data-quantity="1" aria-label="Añadir una persona"','')}</div>`;
     formularioLocal.prepend(alternativas);
     enlazarEvento('[data-demand-specialty]','click',e=>{seleccionar.value=e.currentTarget.dataset.demandSpecialty;seleccionar.dispatchEvent(new Event('change'));});
     enlazarEvento('[data-quantity]','click',e=>{numeroElementos.value=Math.max(1,Math.min(100,Number(numeroElementos.value)+Number(e.currentTarget.dataset.quantity)));$('#demand-count').textContent=numeroElementos.value;numeroElementos.dispatchEvent(new Event('input'));});
     const liberar=$('#release-blocks-form');const actualizarDato=()=>{const cantidad=liberar.querySelectorAll('input[type=checkbox]:checked').length;const botonGuardar=liberar.querySelector('button[type=submit]');botonGuardar.disabled=!cantidad;botonGuardar.textContent=cantidad?`Liberar ${cantidad} ${cantidad===1?'cupo seleccionado':'cupos seleccionados'}`:'Seleccione un cupo activo para liberarlo';};
     enlazarEvento('#release-blocks-form input','change',actualizarDato);actualizarDato();
    }else{
     const formularioLocal=$('#protected-query-form'),seleccionar=formularioLocal.elements.doctor_id;
     seleccionar.closest('label').hidden=true;formularioLocal.querySelector('button[type=submit]').hidden=true;
     const selectorOpciones=document.createElement('div');selectorOpciones.innerHTML=`<h3>Seleccione al médico para ver sus franjas libres</h3><p>Los cupos elegidos se habilitarán para reserva online. Esta apertura no se puede retirar desde el sistema.</p><div class="choice-grid">${estadoLocal.refs.doctors.filter(d=>d.status==='Activo').map(d=>`<button type="button" class="option-card" data-open-doctor="${d.id}"><span class="option-symbol">${escapar(d.first_name.slice(0,1))}</span><span><strong>${escapar(nombreCompleto(d))}</strong><small>Ver cupos protegidos de hoy →</small></span></button>`).join('')}</div>`;
     formularioLocal.prepend(selectorOpciones);
     enlazarEvento('[data-open-doctor]','click',e=>{seleccionar.value=e.currentTarget.dataset.openDoctor;seleccionar.dispatchEvent(new Event('change'));document.querySelectorAll('[data-open-doctor]').forEach(b=>b.classList.toggle('chosen',b===e.currentTarget));formularioLocal.requestSubmit();});
    }
   }};
  };
 }
}
