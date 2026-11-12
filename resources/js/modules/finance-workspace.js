/** CU20/CU26: solicitud, decisión y entrega externa de devoluciones, cada una con confirmación y estado propios. */
export function installFinanceWorkspace(ui) {
 const {$,state,views,api,esc,full,code,button,panel,notice,on,navigate,modal,textarea,field,formErrors,submit,bindForm,closeModal,toast}=ui;
 const money=n=>`Bs ${(Number(n)/100).toFixed(2)}`;
 // Los segundos evitan registrar una entrega antes de una autorización ocurrida en ese mismo minuto.
 const localTimestamp=()=>{const p=Object.fromEntries(new Intl.DateTimeFormat('sv-SE',{timeZone:'America/La_Paz',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(new Date()).map(p=>[p.type,p.value]));return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}:${p.second}`;};
 const labels={pending:'Pendiente de decisión',approved:'Aprobada: falta registrar la entrega',completed:'Devolución registrada',rejected:'Rechazada',ineligible:'No admitida por la política'};
 let stage='all';
 window.addEventListener('clinic:identity-changed',()=>{stage='all';});
 /** Presenta las fases de devolución, sus estados y acciones administrativas. */
 async function refunds(admin) {
  const rows=await api('refunds');
  const appointments=await api('appointments?mode=history&status=Cancelada');
  const context=state.workflowContext?.appointment_id;
  const original=id=>appointments.find(a=>a.id===Number(id));
  const candidates=appointments.filter(a=>a.payment_id&&!rows.some(r=>r.appointment_id===a.id));
  /** Solicita evaluación de devolución conservando su política en el servidor. */
  function request(id) {
   const a=original(id);if(!a)return;
   modal('Solicitar evaluación de reembolso',`<div class="confirmation-ticket"><div><small>Paciente y cita</small><strong>${esc(full(a.patient))} · ${esc(code('CIT',a.id))}</strong></div><div><small>Pago registrado</small><strong>${money(a.payment?.amount_minor)}</strong></div></div><p>La cita ya está cancelada. El servidor comprobará la política de devolución; enviar esta solicitud no entrega dinero.</p><form id="refund-request">${formErrors}<div class="reason-choices">${['Cancelación de la clínica','Cambio de disponibilidad del paciente','Otro motivo'].map(t=>button(t,`data-refund-reason="${esc(t)}"`,'')).join('')}</div>${textarea('Motivo de la solicitud','reason','','required maxlength="3000"')}${submit('Enviar para evaluación')}</form>`);
   on('[data-refund-reason]','click',e=>{$('#refund-request [name=reason]').value=e.currentTarget.dataset.refundReason;});
   bindForm('refund-request',async data=>{
    const result=await api(`appointments/${a.id}/refund`,'POST',data);closeModal();await navigate('CU20');
    modal(result.status==='ineligible'?'Solicitud no admitida':'Solicitud enviada',notice(result.status==='ineligible'?result.decision_reason:'La solicitud cumple la evaluación inicial y está pendiente de decisión administrativa. Todavía no hay una devolución ejecutada.',result.status==='ineligible'?'warning':'success')+button('Entendido','id="refund-result-close"'));
    on('#refund-result-close','click',closeModal);
   });
  }
  /** Pide una decisión explícita sin marcar el dinero como entregado. */
  function decide(id,decision) {
   const r=rows.find(r=>r.id===Number(id));
   modal(decision==='approve'?'Aprobar solicitud':'Rechazar solicitud',`<p class="dialog-context">${esc(code('CIT',r.appointment_id))} · ${money(r.amount_minor)}</p><p>${decision==='approve'?'La aprobación autoriza la devolución; no confirma que se haya entregado dinero. Después deberá registrar la entrega real.':'La solicitud conservará el motivo del rechazo en el historial.'}</p><form id="refund-decision">${formErrors}${decision==='reject'?textarea('Motivo del rechazo','reason','','required maxlength="3000"'):''}${submit(decision==='approve'?'Aprobar y dejar devolución pendiente':'Confirmar rechazo')}</form>`);
   bindForm('refund-decision',async data=>{await api(`refunds/${id}/decision`,'POST',{...data,decision});closeModal();await navigate('CU26');toast(decision==='approve'?'Aprobada. Ahora falta registrar la devolución realizada.':'Rechazo registrado con su motivo.');});
  }
  /** Recoge la constancia de una entrega externa que ya se realizó. */
  function complete(id) {
   const r=rows.find(r=>r.id===Number(id));
   modal('Registrar devolución ya realizada',`<p class="dialog-context">${esc(code('CIT',r.appointment_id))} · ${money(r.amount_minor)}</p><p>Este registro documenta una entrega realizada fuera del sistema. No envía dinero ni ordena un cobro.</p><form id="refund-complete">${formErrors}<div class="grid two">${field('Persona que entregó el dinero','executor','text',state.user.name,'required maxlength="255"')}${field('Fecha y hora de entrega','completed_at','datetime-local',localTimestamp(),'required step="1"')}${field('Medio utilizado','method','text','','required placeholder="Efectivo, transferencia…" maxlength="80"')}${field('Comprobante o referencia (si existe)','reference','text','','maxlength="255"')}</div><label class="confirmation-check"><input type="checkbox" name="delivery_confirmed" required><span>Confirmo que se entregó el importe completo y que estos datos corresponden a la devolución realizada.</span></label>${submit('Registrar devolución realizada')}</form>`);
   bindForm('refund-complete',async data=>{delete data.delivery_confirmed;await api(`refunds/${id}/complete`,'POST',{...data,amount_minor:r.amount_minor});closeModal();await navigate('CU26');toast('La devolución quedó registrada como completada.');});
  }
  /** Actualiza los registros visibles y sus acciones sin alterar su estado en el servidor. */
  function draw() {
   const visible=rows.filter(r=>stage==='all'||r.status===stage);
   $('#refund-cards').innerHTML=visible.length?visible.map(r=>{const a=original(r.appointment_id);return `<article class="refund-card"><div class="record-heading"><div><span class="record-code">${esc(code('CIT',r.appointment_id))}</span><h3>${esc(a?full(a.patient):'Solicitud '+r.id)}</h3><p>${esc(r.reason)}</p></div><strong class="refund-amount">${money(r.amount_minor)}</strong></div><span class="stage-badge ${r.status}">${esc(labels[r.status]||r.status)}</span><ol class="mini-journey"><li class="done"><span>✓</span>Solicitud</li><li class="${['approved','completed','rejected'].includes(r.status)?'done':''}"><span>2</span>Decisión</li><li class="${r.status==='completed'?'done':''}"><span>3</span>Entrega registrada</li></ol>${r.decision_reason?`<p>${esc(r.decision_reason)}</p>`:''}${r.completed_at?notice(`Entrega declarada por ${r.executor} · ${r.method} · ${r.completed_at}${r.reference?' · '+r.reference:''}`,'success'):''}<div class="record-actions">${admin&&r.status==='pending'?button('Aprobar',`data-approve-refund="${r.id}"`,'success')+button('Rechazar',`data-reject-refund="${r.id}"`,''):admin&&r.status==='approved'?button('Registrar dinero devuelto',`data-complete-refund="${r.id}"`,'success'):''}</div></article>`;}).join(''):'<div class="friendly-empty"><strong>No hay solicitudes en este estado</strong><p>Las solicitudes y sus decisiones aparecerán aquí.</p></div>';
   on('[data-approve-refund]','click',e=>decide(e.currentTarget.dataset.approveRefund,'approve'),$('#refund-cards'));
   on('[data-reject-refund]','click',e=>decide(e.currentTarget.dataset.rejectRefund,'reject'),$('#refund-cards'));
   on('[data-complete-refund]','click',e=>complete(e.currentTarget.dataset.completeRefund),$('#refund-cards'));
  }
  return {html:`<div class="workspace-heading"><div><h2>${admin?'Resolver y dar seguimiento a devoluciones':'Sus solicitudes de devolución'}</h2><p>${admin?'Revise la solicitud, registre su decisión y después documente la entrega. Cada paso tiene un estado propio.':'Para solicitar una devolución, primero la clínica debe cancelar la cita. La cuenta del paciente es opcional: Recepción también puede tramitarla.'}</p></div></div>`
   +(!admin?panel('Citas canceladas que puede presentar a evaluación',candidates.length?candidates.map(a=>`<div class="eligible-refund"><div><strong>${esc(full(a.patient))}</strong><p>${esc(code('CIT',a.id))} · ${esc(a.date)} · ${esc(a.specialty.name)} · ${money(a.payment?.amount_minor)}</p></div>${button('Solicitar evaluación',`data-request-refund="${a.id}"`)}</div>`).join(''):`<div class="friendly-empty"><strong>No hay otra cita cancelada con pago para solicitar</strong><p>Una cita activa debe cancelarse primero desde su ficha; no se cancela por solicitar reembolso.</p>${ui.allowedViews().includes('CU11')?button('Revisar citas de la clínica','data-refund-agenda',''):''}</div>`):'')
   +`<div class="status-filters">${[['all','Todas'],['pending','Por decidir'],['approved','Entrega pendiente'],['completed','Completadas'],['rejected','Rechazadas'],['ineligible','No admitidas']].map(([key,label])=>`<button type="button" class="filter-chip ${stage===key?'selected':''}" data-refund-stage="${key}">${label}</button>`).join('')}</div><div id="refund-cards" class="refund-grid"></div>`,bind:()=>{
    draw();on('[data-refund-stage]','click',e=>{stage=e.currentTarget.dataset.refundStage;document.querySelectorAll('[data-refund-stage]').forEach(b=>b.classList.toggle('selected',b.dataset.refundStage===stage));draw();});
    on('[data-request-refund]','click',e=>request(e.currentTarget.dataset.requestRefund));on('[data-refund-agenda]','click',()=>navigate('CU11'));
    if(context&&candidates.some(a=>a.id===context)){state.workflowContext=null;request(context);}
   }};
 }
 views.CU20=()=>refunds(false);views.CU26=()=>refunds(true);
}
