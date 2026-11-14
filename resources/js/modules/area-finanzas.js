/** CU20/CU26: solicitud, decisión y entrega externa de devoluciones, cada una con confirmación y estado propios. */
export function instalarAreaFinanzas(interfaz) {
 const {$,estadoLocal: estadoLocal,vistas: vistas,clienteApi: clienteApi,escapar: escapar,nombreCompleto: nombreCompleto,codigo: codigo,boton: boton,panel,aviso: aviso,enlazarEvento: enlazarEvento,navegar: navegar,modal,areaTexto: areaTexto,campo: campo,erroresFormulario: erroresFormulario,botonGuardar: botonGuardar,enlazarFormulario: enlazarFormulario,cerrarModal: cerrarModal,notificacion: notificacion}=interfaz;
 const importeMonetario=n=>`Bs ${(Number(n)/100).toFixed(2)}`;
 // Los segundos evitan registrar una entrega antes de una autorización ocurrida en ese mismo minuto.
 const marcaTiempoLocal=()=>{const p=Object.fromEntries(new Intl.DateTimeFormat('sv-SE',{timeZone:'America/La_Paz',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(new Date()).map(p=>[p.type,p.value]));return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}:${p.second}`;};
 const etiquetas={pending:'Pendiente de decisión',approved:'Aprobada: falta registrar la entrega',completed:'Devolución registrada',rejected:'Rechazada',ineligible:'No admitida por la política'};
 let etapa='all';
 window.addEventListener('clinic:identity-changed',()=>{etapa='all';});
 /** Presenta las fases de devolución, sus estados y acciones administrativas. */
 async function reembolsos(administrador) {
  const filas=await clienteApi('refunds');
  const citas=await clienteApi('appointments?mode=history&status=Cancelada');
  const contexto=estadoLocal.workflowContext?.appointment_id;
  const original=id=>citas.find(a=>a.id===Number(id));
  const candidatas=citas.filter(a=>a.payment_id&&!filas.some(r=>r.appointment_id===a.id));
  /** Solicita evaluación de devolución conservando su política en el servidor. */
  function peticion(id) {
   const a=original(id);if(!a)return;
   modal('Solicitar evaluación de reembolso',`<div class="confirmation-ticket"><div><small>Paciente y cita</small><strong>${escapar(nombreCompleto(a.patient))} · ${escapar(codigo('CIT',a.id))}</strong></div><div><small>Pago registrado</small><strong>${importeMonetario(a.payment?.amount_minor)}</strong></div></div><p>La cita ya está cancelada. El servidor comprobará la política de devolución; enviar esta solicitud no entrega dinero.</p><form id="refund-request">${erroresFormulario}<div class="reason-choices">${['Cancelación de la clínica','Cambio de disponibilidad del paciente','Otro motivo'].map(t=>boton(t,`data-refund-reason="${escapar(t)}"`,'')).join('')}</div>${areaTexto('Motivo de la solicitud','reason','','required maxlength="3000"')}${botonGuardar('Enviar para evaluación')}</form>`);
   enlazarEvento('[data-refund-reason]','click',e=>{$('#refund-request [name=reason]').value=e.currentTarget.dataset.refundReason;});
   enlazarFormulario('refund-request',async datos=>{
    const resultado=await clienteApi(`appointments/${a.id}/refund`,'POST',datos);cerrarModal();await navegar('CU20');
    modal(resultado.status==='ineligible'?'Solicitud no admitida':'Solicitud enviada',aviso(resultado.status==='ineligible'?resultado.decision_reason:'La solicitud cumple la evaluación inicial y está pendiente de decisión administrativa. Todavía no hay una devolución ejecutada.',resultado.status==='ineligible'?'warning':'success')+boton('Entendido','id="refund-result-close"'));
    enlazarEvento('#refund-result-close','click',cerrarModal);
   });
  }
  /** Pide una decisión explícita sin marcar el dinero como entregado. */
  function resolver(id,resolucion) {
   const r=filas.find(r=>r.id===Number(id));
   modal(resolucion==='approve'?'Aprobar solicitud':'Rechazar solicitud',`<p class="dialog-context">${escapar(codigo('CIT',r.appointment_id))} · ${importeMonetario(r.amount_minor)}</p><p>${resolucion==='approve'?'La aprobación autoriza la devolución; no confirma que se haya entregado dinero. Después deberá registrar la entrega real.':'La solicitud conservará el motivo del rechazo en el historial.'}</p><form id="refund-decision">${erroresFormulario}${resolucion==='reject'?areaTexto('Motivo del rechazo','reason','','required maxlength="3000"'):''}${botonGuardar(resolucion==='approve'?'Aprobar y dejar devolución pendiente':'Confirmar rechazo')}</form>`);
   enlazarFormulario('refund-decision',async datos=>{await clienteApi(`refunds/${id}/decision`,'POST',{...datos,decision: resolucion});cerrarModal();await navegar('CU26');notificacion(resolucion==='approve'?'Aprobada. Ahora falta registrar la devolución realizada.':'Rechazo registrado con su motivo.');});
  }
  /** Recoge la constancia de una entrega externa que ya se realizó. */
  function completar(id) {
   const r=filas.find(r=>r.id===Number(id));
   modal('Registrar devolución ya realizada',`<p class="dialog-context">${escapar(codigo('CIT',r.appointment_id))} · ${importeMonetario(r.amount_minor)}</p><p>Este registro documenta una entrega realizada fuera del sistema. No envía dinero ni ordena un cobro.</p><form id="refund-complete">${erroresFormulario}<div class="grid two">${campo('Persona que entregó el dinero','executor','text',estadoLocal.user.name,'required maxlength="255"')}${campo('Fecha y hora de entrega','completed_at','datetime-local',marcaTiempoLocal(),'required step="1"')}${campo('Medio utilizado','method','text','','required placeholder="Efectivo, transferencia…" maxlength="80"')}${campo('Comprobante o referencia (si existe)','reference','text','','maxlength="255"')}</div><label class="confirmation-check"><input type="checkbox" name="delivery_confirmed" required><span>Confirmo que se entregó el importe completo y que estos datos corresponden a la devolución realizada.</span></label>${botonGuardar('Registrar devolución realizada')}</form>`);
   enlazarFormulario('refund-complete',async datos=>{delete datos.delivery_confirmed;await clienteApi(`refunds/${id}/complete`,'POST',{...datos,amount_minor:r.amount_minor});cerrarModal();await navegar('CU26');notificacion('La devolución quedó registrada como completada.');});
  }
  /** Actualiza los registros visibles y sus acciones sin alterar su estado en el servidor. */
  function dibujar() {
   const visible=filas.filter(r=>etapa==='all'||r.status===etapa);
   $('#refund-cards').innerHTML=visible.length?visible.map(r=>{const a=original(r.appointment_id);return `<article class="refund-card"><div class="record-heading"><div><span class="record-code">${escapar(codigo('CIT',r.appointment_id))}</span><h3>${escapar(a?nombreCompleto(a.patient):'Solicitud '+r.id)}</h3><p>${escapar(r.reason)}</p></div><strong class="refund-amount">${importeMonetario(r.amount_minor)}</strong></div><span class="stage-badge ${r.status}">${escapar(etiquetas[r.status]||r.status)}</span><ol class="mini-journey"><li class="done"><span>✓</span>Solicitud</li><li class="${['approved','completed','rejected'].includes(r.status)?'done':''}"><span>2</span>Decisión</li><li class="${r.status==='completed'?'done':''}"><span>3</span>Entrega registrada</li></ol>${r.decision_reason?`<p>${escapar(r.decision_reason)}</p>`:''}${r.completed_at?aviso(`Entrega declarada por ${r.executor} · ${r.method} · ${r.completed_at}${r.reference?' · '+r.reference:''}`,'success'):''}<div class="record-actions">${administrador&&r.status==='pending'?boton('Aprobar',`data-approve-refund="${r.id}"`,'success')+boton('Rechazar',`data-reject-refund="${r.id}"`,''):administrador&&r.status==='approved'?boton('Registrar dinero devuelto',`data-complete-refund="${r.id}"`,'success'):''}</div></article>`;}).join(''):'<div class="friendly-empty"><strong>No hay solicitudes en este estado</strong><p>Las solicitudes y sus decisiones aparecerán aquí.</p></div>';
   enlazarEvento('[data-approve-refund]','click',e=>resolver(e.currentTarget.dataset.approveRefund,'approve'),$('#refund-cards'));
   enlazarEvento('[data-reject-refund]','click',e=>resolver(e.currentTarget.dataset.rejectRefund,'reject'),$('#refund-cards'));
   enlazarEvento('[data-complete-refund]','click',e=>completar(e.currentTarget.dataset.completeRefund),$('#refund-cards'));
  }
  return {html:`<div class="workspace-heading"><div><h2>${administrador?'Resolver y dar seguimiento a devoluciones':'Sus solicitudes de devolución'}</h2><p>${administrador?'Revise la solicitud, registre su decisión y después documente la entrega. Cada paso tiene un estado propio.':'Para solicitar una devolución, primero la clínica debe cancelar la cita. La cuenta del paciente es opcional: Recepción también puede tramitarla.'}</p></div></div>`
   +(!administrador?panel('Citas canceladas que puede presentar a evaluación',candidatas.length?candidatas.map(a=>`<div class="eligible-refund"><div><strong>${escapar(nombreCompleto(a.patient))}</strong><p>${escapar(codigo('CIT',a.id))} · ${escapar(a.date)} · ${escapar(a.specialty.name)} · ${importeMonetario(a.payment?.amount_minor)}</p></div>${boton('Solicitar evaluación',`data-request-refund="${a.id}"`)}</div>`).join(''):`<div class="friendly-empty"><strong>No hay otra cita cancelada con pago para solicitar</strong><p>Una cita activa debe cancelarse primero desde su ficha; no se cancela por solicitar reembolso.</p>${interfaz.vistasPermitidas().includes('CU11')?boton('Revisar citas de la clínica','data-refund-agenda',''):''}</div>`):'')
   +`<div class="status-filters">${[['all','Todas'],['pending','Por decidir'],['approved','Entrega pendiente'],['completed','Completadas'],['rejected','Rechazadas'],['ineligible','No admitidas']].map(([clave,etiqueta])=>`<button type="button" class="filter-chip ${etapa===clave?'selected':''}" data-refund-stage="${clave}">${etiqueta}</button>`).join('')}</div><div id="refund-cards" class="refund-grid"></div>`,bind:()=>{
    dibujar();enlazarEvento('[data-refund-stage]','click',e=>{etapa=e.currentTarget.dataset.refundStage;document.querySelectorAll('[data-refund-stage]').forEach(b=>b.classList.toggle('selected',b.dataset.refundStage===etapa));dibujar();});
    enlazarEvento('[data-request-refund]','click',e=>peticion(e.currentTarget.dataset.requestRefund));enlazarEvento('[data-refund-agenda]','click',()=>navegar('CU11'));
    if(contexto&&candidatas.some(a=>a.id===contexto)){estadoLocal.workflowContext=null;peticion(contexto);}
   }};
 }
 vistas.CU20=()=>reembolsos(false);vistas.CU26=()=>reembolsos(true);
}
