/** Presenta reportes, auditoría y ajustes de cupos mediante decisiones contextuales antes de pedir criterios. */
import {shiftDate} from '../lib/clinical-flow';

export function installContextualTools(ui) {
 const {$,state,views,button,esc,localDate,full,on}=ui;
 const report=views.CU16;
 const reports=[['REP01','Citas de una fecha','Revise quién tiene cita y el estado de su atención.'],['REP02','Actividad de un médico','Consulte las citas de un profesional en un período.'],['REP03','Demanda por especialidad','Revise las citas de un servicio de la clínica.'],['REP04','Citas por resultado','Separe citas atendidas, canceladas o pendientes.'],['REP05','Pacientes registrados','Consulte las fichas, tengan o no una cuenta del portal.'],['REP06','Resumen de atenciones','Compare atención, cancelaciones e inasistencias.'],['REP07','Agenda diaria de un médico','Prepare la lista de pacientes de una jornada.']];
 views.CU16=async()=>{
  const result=await report();
  return {html:`<div class="workspace-heading"><div><h2>¿Qué información necesita revisar?</h2><p>Elija un informe. Solo se pedirán los criterios que necesita ese reporte.</p></div></div><div class="report-choices">${reports.map(([id,title,copy])=>`<button type="button" class="report-choice" data-report-choice="${id}"><span>↗</span><strong>${title}</strong><small>${copy}</small></button>`).join('')}</div><section id="chosen-report" hidden><div id="report-presets" class="status-filters">${[['today','Hoy'],['week','Últimos 7 días'],['month','Este mes']].map(([id,label])=>button(label,`data-report-period="${id}"`,'')).join('')}</div>${result.html}</section>`,bind:()=>{
   result.bind?.();
   $('#type').closest('label').hidden=true;
   on('[data-report-choice]','click',e=>{
    $('#type').value=e.currentTarget.dataset.reportChoice;$('#type').dispatchEvent(new Event('change'));
    $('#chosen-report').hidden=false;
    document.querySelectorAll('[data-report-choice]').forEach(b=>b.classList.toggle('chosen',b===e.currentTarget));
    if(['REP01','REP05'].includes(e.currentTarget.dataset.reportChoice))$('#report-form').requestSubmit();
    $('#chosen-report').scrollIntoView({block:'start',behavior:'smooth'});
   });
   on('[data-report-period]','click',e=>{
    const period=e.currentTarget.dataset.reportPeriod;const today=localDate();
    $('#date').value=today;$('#from').value=period==='week'?shiftDate(today,-6):period==='month'?today.slice(0,8)+'01':today;$('#to').value=today;
    $('#period_mode').value=period==='today'?'date':'range';$('#period_mode').dispatchEvent(new Event('change'));
    if($('#report-form').reportValidity())$('#report-form').requestSubmit();
   });
  }};
 };
 const audit=views.CU22;
 views.CU22=async()=>{
  const result=await audit();
  return {html:result.html,bind:()=>{
   result.bind?.();const form=$('#audit-form');const section=form.closest('section');
   const details=document.createElement('details');details.className='advanced-options';details.innerHTML='<summary>Filtrar la actividad por fecha, responsable o proceso</summary>';
   section.querySelector('h3')?.remove();details.append(form);section.replaceChildren(details);
   section.parentElement.append(section);
  }};
 };
 for(const view of ['CU24','CU25']){
  const original=views[view];
  views[view]=async()=>{
   const result=await original();
   return {html:result.html,bind:()=>{
    result.bind?.();
    if(view==='CU24'){
     const form=$('#demand-proposal-form'),select=form.elements.specialty_id,quantity=form.elements.quantity;
     select.closest('label').hidden=true;quantity.closest('label').hidden=true;
     const choices=document.createElement('div');choices.className='specialty-demand-picker';choices.innerHTML=`<h3>¿Para qué especialidad hay personas esperando hoy?</h3><div class="doctor-chips">${state.refs.specialties.filter(s=>s.status==='Activo').map(s=>`<button type="button" class="filter-chip ${String(s.id)===select.value?'selected':''}" data-demand-specialty="${s.id}">${esc(s.name)}</button>`).join('')}</div><div class="quantity-picker"><div><strong>Personas presentes o estimadas</strong><p>Se buscarán cupos presenciales libres para esta demanda; todavía no se crean citas.</p></div>${button('−','data-quantity="-1" aria-label="Quitar una persona"','')}<output id="demand-count">${quantity.value}</output>${button('+','data-quantity="1" aria-label="Añadir una persona"','')}</div>`;
     form.prepend(choices);
     on('[data-demand-specialty]','click',e=>{select.value=e.currentTarget.dataset.demandSpecialty;select.dispatchEvent(new Event('change'));});
     on('[data-quantity]','click',e=>{quantity.value=Math.max(1,Math.min(100,Number(quantity.value)+Number(e.currentTarget.dataset.quantity)));$('#demand-count').textContent=quantity.value;quantity.dispatchEvent(new Event('input'));});
     const release=$('#release-blocks-form');const update=()=>{const count=release.querySelectorAll('input[type=checkbox]:checked').length;const submit=release.querySelector('button[type=submit]');submit.disabled=!count;submit.textContent=count?`Liberar ${count} ${count===1?'cupo seleccionado':'cupos seleccionados'}`:'Seleccione un cupo activo para liberarlo';};
     on('#release-blocks-form input','change',update);update();
    }else{
     const form=$('#protected-query-form'),select=form.elements.doctor_id;
     select.closest('label').hidden=true;form.querySelector('button[type=submit]').hidden=true;
     const picker=document.createElement('div');picker.innerHTML=`<h3>Seleccione al médico para ver sus franjas libres</h3><p>Los cupos elegidos se habilitarán para reserva online. Esta apertura no se puede retirar desde el sistema.</p><div class="choice-grid">${state.refs.doctors.filter(d=>d.status==='Activo').map(d=>`<button type="button" class="option-card" data-open-doctor="${d.id}"><span class="option-symbol">${esc(d.first_name.slice(0,1))}</span><span><strong>${esc(full(d))}</strong><small>Ver cupos protegidos de hoy →</small></span></button>`).join('')}</div>`;
     form.prepend(picker);
     on('[data-open-doctor]','click',e=>{select.value=e.currentTarget.dataset.openDoctor;select.dispatchEvent(new Event('change'));document.querySelectorAll('[data-open-doctor]').forEach(b=>b.classList.toggle('chosen',b===e.currentTarget));form.requestSubmit();});
    }
   }};
  };
 }
}
