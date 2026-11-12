/** CU08/CU09/CU18/CU21: selección guiada de atención, cupo y confirmación, separada del registro definitivo en el servidor. */
import {shiftDate} from '../lib/clinical-flow';

export function installBookingWorkflow(ui) {
    const {$,state,views,api,esc,full,localDate,field,select,textarea,button,panel,notice,on,navigate,toast}=ui;
    const money=value=>value==null?'Tarifa por definir':`Bs ${(value/100).toFixed(2)}`;
    const day=value=>new Date(value+'T12:00:00Z').toLocaleDateString('es-BO',{weekday:'long',day:'numeric',month:'long',timeZone:'America/La_Paz'});
    const normalize=value=>String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();

    /** Construye el recorrido según cita presencial, portal, seguimiento o consulta de disponibilidad. */
    async function booking({online=false,followup=false,consult=false}={}) {
        const view=state.view,sequence=state.sequence;
        const options=await api('medical-availability/options');
        const parents=followup?(await api('appointments?mode=history&status=Atendida')).filter(a=>a.followup_suggested_at):[];
        const requests=online?await api('online-bookings'):[];
        const supervisor=online&&state.user.role==='Superadmin';
        const staff=!online&&!consult;
        const saved=state.bookingDraft?.view===view?state.bookingDraft:{};
        const context=state.workflowContext||{};
        const draft={view,step:0,patient_id:context.patient_id||'',specialty_id:'',doctor_id:'',date:localDate(),selected:null,...saved};
        const steps=consult?['Atención','Horarios']:followup?['Consulta de origen','Atención','Horario','Confirmación']:staff?['Paciente','Atención','Horario','Confirmación']:['Atención','Horario','Confirmación'];
        let slots=[],loading=false,error='',version=0,busy=false;
        let patientQuery='';
        const save=()=>{state.bookingDraft={...draft};};
        const specialty=()=>options.specialties.find(s=>String(s.id)===String(draft.specialty_id));
        const patient=()=>state.refs.patients.find(p=>String(p.id)===String(draft.patient_id));
        const parent=()=>parents.find(p=>String(p.id)===String(draft.parent_appointment_id));
        const stepIndex=label=>steps.indexOf(label);
        const go=step=>{draft.step=step;error='';save();paint();if(['Horario','Horarios'].includes(steps[step]))loadSlots();};
        const choices=(items,kind,current)=>`<div class="choice-grid">${items.map(item=>`<button type="button" class="option-card ${String(item.id)===String(current)?'chosen':''}" data-pick="${kind}" data-value="${esc(item.id)}" aria-pressed="${String(item.id)===String(current)}"><span class="option-symbol">${esc(item.symbol||item.title.slice(0,1))}</span><span><strong>${esc(item.title)}</strong><small>${esc(item.description||'')}</small></span><span class="option-indicator" aria-hidden="true">${String(item.id)===String(current)?'✓':'→'}</span></button>`).join('')}</div>`;
        /** Descarta respuestas tardías para no mostrar horarios de un criterio anterior. */
        async function loadSlots() {
            if(!draft.specialty_id)return;
            const token=++version;loading=true;error='';paint();
            try {
                const result=await api('medical-availability?'+new URLSearchParams({specialty_id:draft.specialty_id,doctor_id:draft.doctor_id,date:draft.date,modality:online||state.user.role==='Paciente'?'online':'presential',...(!online&&state.user.role!=='Paciente'?{include_demand:'1'}:{})}));
                if(token!==version||state.view!==view||state.sequence!==sequence)return;
                slots=result.blocks;draft.slotMessage=result.message;
            }catch(e){if(token===version){slots=[];error=e.message;}}
            finally{if(token===version&&state.view===view&&state.sequence===sequence){loading=false;paint();}}
        }
        /** Presenta especialidades y tarifa antes de seleccionar horario. */
        function attention() {
            return `<h2>¿Qué atención necesita?</h2><p class="flow-intro">Elija una especialidad. Verá su tarifa antes de seleccionar un horario.</p>`
                +choices(options.specialties.map(s=>({id:s.id,title:s.name,description:money(s.fee_minor),symbol:'✚'})),'specialty',draft.specialty_id)
                +(!options.specialties.length?notice('No hay especialidades activas. Administración debe registrar una especialidad y su tarifa antes de programar citas.','warning'):'');
        }
        /** Permite elegir una ficha o la consulta que origina un seguimiento. */
        function who() {
            if(followup)return `<h2>Seleccione la consulta que requiere seguimiento</h2><p class="flow-intro">Solo aparecen consultas finalizadas con una recomendación del médico.</p>`
                +(parents.length?choices(parents.map(a=>({id:a.id,title:full(a.patient),description:`${a.specialty.name} · Sugerencia: ${day(a.followup_suggested_at.slice(0,10))}`})),'parent',draft.parent_appointment_id):notice('Todavía no hay consultas con una recomendación de seguimiento. El médico la registra al finalizar la atención.'));
            return `<div class="section-toolbar"><div><h2>¿Para quién es la cita?</h2><p class="flow-intro">Busque su ficha. El paciente no necesita una cuenta ni correo para recibir atención.</p></div>${button('+ Registrar paciente','id="booking-register-patient"','')}</div>`
                +`<label class="field picker-search"><span>Buscar por nombre o documento</span><input id="booking-patient-search" type="search" value="${esc(patientQuery)}" placeholder="Escriba el nombre o CI del paciente" autocomplete="off"></label><div id="booking-patient-results">${patientResults()}</div>`;
        }
        function patientResults() {
            const patients=state.refs.patients.filter(p=>p.status==='Activo'&&normalize(`${full(p)} ${p.identity||''}`).includes(normalize(patientQuery)));
            return patients.length?choices(patients.slice(0,12).map(p=>({id:p.id,title:full(p),description:`${p.identity?'CI '+p.identity:'Sin CI'} · Ficha registrada`,symbol:full(p).slice(0,1)})),'patient',draft.patient_id)+(patients.length>12?`<p class="help-text">Hay ${patients.length} coincidencias. Escriba más letras para encontrar la ficha.</p>`:''):notice('No encontramos esa ficha. Puede registrar al paciente y volver a esta cita.');
        }
        /** Presenta médico, día y franjas libres sin crear la cita. */
        function when() {
            const doctors=options.doctors.filter(d=>d.specialties.some(s=>String(s.id)===String(draft.specialty_id)));
            return `<h2>${consult?'Explore los horarios disponibles':'Elija médico, día y hora'}</h2><p class="flow-intro">${esc(specialty()?.name)} · ${money(specialty()?.fee_minor)}. Seleccionar un horario todavía no crea una cita.</p>`
                +`<div class="doctor-chips" aria-label="Preferencia de médico"><button type="button" class="filter-chip ${!draft.doctor_id?'selected':''}" data-doctor="">Cualquier médico disponible</button>${doctors.map(d=>`<button type="button" class="filter-chip ${String(d.id)===String(draft.doctor_id)?'selected':''}" data-doctor="${d.id}">${esc(full(d))}</button>`).join('')}</div>`
                +`<div class="date-strip-toolbar">${button('‹ Semana anterior','data-date-page="-7"','')}<label class="field"><span>Ir a otra fecha</span><input id="booking-date" type="date" value="${draft.date}" min="${localDate()}"></label>${button('Semana siguiente ›','data-date-page="7"','')}</div>`
                +`<div class="date-strip" aria-label="Elegir día">${Array.from({length:7},(_,i)=>shiftDate(draft.date,i)).map(date=>`<button type="button" data-booking-date="${date}" class="date-tile ${date===draft.date?'selected':''}" aria-pressed="${date===draft.date}"><span>${esc(new Date(date+'T12:00:00Z').toLocaleDateString('es-BO',{weekday:'short',timeZone:'America/La_Paz'}))}</span><strong>${date.slice(-2)}</strong><small>${date===localDate()?'Hoy':esc(new Date(date+'T12:00:00Z').toLocaleDateString('es-BO',{month:'short',timeZone:'America/La_Paz'}))}</small></button>`).join('')}</div>`
                +(loading?'<div class="loading-state" role="status"><span class="spinner"></span><p>Buscando horarios libres…</p></div>':slots.length?`<div class="slot-groups">${[...new Set(slots.map(s=>s.doctor_id))].map(id=>`<section class="slot-group"><h3>${esc(slots.find(s=>s.doctor_id===id).doctor_name)}</h3><div class="slot-grid">${slots.map((s,i)=>s.doctor_id===id?`<button type="button" data-flow-slot="${i}" class="slot-choice ${draft.selected?.doctor_id===s.doctor_id&&draft.selected?.start_time===s.start_time?'selected':''}"><strong>${esc(s.start_time)}</strong><span>hasta ${esc(s.end_time)}</span><small>${s.demand_blocked?'Cupo presencial protegido':'Elegir este horario'}</small></button>`:'').join('')}</div></section>`).join('')}</div>`:`<div class="friendly-empty"><strong>No hay horarios disponibles para este día</strong><p>${esc(draft.slotMessage||'Pruebe otro día o quite la preferencia de médico. No se ha creado ninguna cita.')}</p>${button('Buscar al día siguiente','id="next-available-day"')}</div>`);
        }
        /** Resume la operación y exige comprobar el pago o la exención antes de confirmar. */
        function confirmation() {
            const p=parent();
            const free=p&&draft.selected&&(p.followup_suggested_at.slice(0,16).replace('T',' ')===`${draft.date} ${draft.selected.start_time}`||draft.followup_change_cause==='clinic');
            draft.free=Boolean(free);
            return `<h2>Revise la cita antes de confirmar</h2><p class="flow-intro">${online?'El horario se retendrá al iniciar el pago; la ficha se emitirá cuando el servidor confirme el cobro.':'La ficha se emitirá al guardar la cita con el pago o la exención verificados.'}</p>`
                +`<div class="confirmation-ticket"><div><small>Paciente</small><strong>${esc(online?state.user.name:full(patient()))}</strong></div><div><small>Atención</small><strong>${esc(specialty()?.name)}</strong></div><div><small>Médico</small><strong>${esc(draft.selected?.doctor_name)}</strong></div><div><small>Fecha y hora</small><strong>${esc(day(draft.date))} · ${esc(draft.selected?.start_time)}</strong></div><div><small>Importe</small><strong>${free?'Sin costo por seguimiento':money(specialty()?.fee_minor)}</strong></div></div>`
                +(followup?`<details class="advanced-options" ${draft.followup_change_cause==='clinic'?'open':''}><summary>¿Cambió la fecha sugerida por una causa de la clínica?</summary>${select('Origen del cambio','followup_change_cause',[['patient','Decisión del paciente / fecha original'],['clinic','Causa de la clínica']],draft.followup_change_cause||'patient')}${textarea('Motivo y acuerdo del cambio','followup_change_reason',draft.followup_change_reason||'','maxlength="3000"')}</details>`:'')
                +(online?notice(`PayPal Sandbox · dinero de prueba. Tasa fijada por el servidor: ${options.bob_per_usd||'—'} Bs por USD. Se mostrará el importe convertido antes de pagar.`):free?notice('La consulta de seguimiento cumple las condiciones de exención. No se solicitará un cobro.','success'):`<h3>¿Cómo recibió el pago?</h3><div class="segmented-control">${[['cash','Efectivo'],['qr','QR presencial'],['transfer','Transferencia']].map(([value,label])=>`<button type="button" data-pay-method="${value}" class="filter-chip ${(draft.payment_method||'cash')===value?'selected':''}">${label}</button>`).join('')}</div>${(draft.payment_method||'cash')!=='cash'?field('Referencia bancaria','bank_reference','text',draft.bank_reference||'','required maxlength="180"'):''}<label class="confirmation-check"><input name="payment_confirmed" type="checkbox" value="1" ${draft.payment_confirmed?'checked':''} required><span>Confirmo que recibí y verifiqué ${money(specialty()?.fee_minor)}. Esta acción registrará el cobro.</span></label>`)
                +(!online?`<details class="advanced-options"><summary>Añadir motivo de consulta (opcional)</summary>${textarea('Motivo','reason',draft.reason||'','maxlength="3000"')}</details>`:'');
        }
        /** Dibuja el paso actual y vuelve a enlazar sus acciones después de reemplazar el contenido. */
        function paint() {
            const root=$('#booking-flow');if(!root)return;
            const stage=steps[draft.step];
            const current=stage==='Paciente'||stage==='Consulta de origen'?who():stage==='Atención'?attention():stage==='Horario'||stage==='Horarios'?when():confirmation();
            root.innerHTML=`<div class="flow-layout"><section class="flow-main"><ol class="journey-steps" aria-label="Pasos de ${consult?'consulta':'programación'}">${steps.map((s,i)=>`<li class="${i<draft.step?'done':''}" ${i===draft.step?'aria-current="step"':''}><span>${i<draft.step?'✓':i+1}</span>${esc(s)}</li>`).join('')}</ol><div id="flow-error" role="alert">${error?notice(error,'error'):''}</div><form id="guided-booking">${current}<div class="flow-navigation">${draft.step?button('← Volver','id="flow-back"',''):'<span></span>'}${['Paciente','Atención','Consulta de origen'].includes(stage)?button('Continuar →','id="flow-next"'):stage==='Confirmación'?`<button type="submit" class="btn success" ${busy||supervisor||online&&!options.online_payment_available?'disabled':''}>${busy?'Procesando…':online?'Continuar a PayPal Sandbox':'Confirmar cita y emitir ficha'}</button>`:''}</div></form></section><aside class="flow-summary"><span class="eyebrow">SU RECORRIDO</span><h3>${consult?'Consulta de disponibilidad':followup?'Seguimiento médico':online?'Reserva online':'Cita en la clínica'}</h3><p>${consult?'Puede revisar horarios sin reservar.':online?'Su reserva se confirma después del pago.':'Recepción puede completar todo el proceso sin cuenta del paciente.'}</p><dl><dt>Paciente</dt><dd>${esc(online?state.user.name:patient()?full(patient()):'Por seleccionar')}</dd><dt>Especialidad</dt><dd>${esc(specialty()?.name||'Por seleccionar')}</dd><dt>Médico y horario</dt><dd>${draft.selected?`${esc(draft.selected.doctor_name)}<br>${esc(day(draft.date))}<br>${esc(draft.selected.start_time)}–${esc(draft.selected.end_time)}`:'Por seleccionar'}</dd></dl><div class="outcome-hint"><strong>¿Qué ocurre después?</strong><p>${consult?'La consulta no retiene cupos. Inicie una cita para reservar.':online?'Una vez pagada, la cita aparecerá en su historial y en la agenda de la clínica.':'Después de emitir la ficha, registre la llegada el día de la cita. El médico verá al paciente en su agenda.'}</p></div></aside></div>`;
            const capture=()=>{const form=$('#guided-booking');if(!form)return;const values=Object.fromEntries(new FormData(form));Object.assign(draft,values);if(form.elements.namedItem('payment_confirmed'))draft.payment_confirmed=form.elements.namedItem('payment_confirmed').checked;save();};
            on('#guided-booking input,#guided-booking textarea','input',capture);
            on('#flow-back','click',()=>{capture();go(draft.step-1);});
            on('#flow-next','click',()=>{
                if(stage==='Paciente'&&!draft.patient_id)error='Seleccione una ficha de paciente para continuar.';
                else if(stage==='Consulta de origen'&&!draft.parent_appointment_id)error='Seleccione la consulta que recomendó el seguimiento.';
                else if(stage==='Atención'&&!draft.specialty_id)error='Elija una especialidad para ver sus horarios.';
                else {go(draft.step+1);return;}
                paint();
            });
            on('#booking-register-patient','click',async()=>{save();state.returnToBooking=view;await navigate('CU06');$('#new-entity')?.click();});
            on('#booking-patient-search','input',e=>{patientQuery=e.target.value;$('#booking-patient-results').innerHTML=patientResults();bindChoices();});
            on('[data-doctor]','click',e=>{draft.doctor_id=e.currentTarget.dataset.doctor;draft.selected=null;save();loadSlots();});
            const changeDate=date=>{if(date<localDate())return;draft.date=date;draft.selected=null;save();loadSlots();};
            on('[data-booking-date]','click',e=>changeDate(e.currentTarget.dataset.bookingDate));
            on('[data-date-page]','click',e=>changeDate(shiftDate(draft.date,Number(e.currentTarget.dataset.datePage))));
            on('#booking-date','change',e=>{if(e.target.value)changeDate(e.target.value);});
            on('#next-available-day','click',()=>changeDate(shiftDate(draft.date,1)));
            on('[data-flow-slot]','click',e=>{
                draft.selected=slots[Number(e.currentTarget.dataset.flowSlot)];save();
                if(consult){state.workflowContext={...state.workflowContext,specialty_id:draft.specialty_id,date:draft.date,slot:draft.selected};navigate(state.user.role==='Paciente'?'CU18':'CU09');}
                else go(draft.step+1);
            });
            on('[data-pay-method]','click',e=>{capture();draft.payment_method=e.currentTarget.dataset.payMethod;draft.payment_confirmed=false;paint();});
            on('[name=followup_change_cause]','change',()=>{capture();paint();});
            $('#guided-booking').addEventListener('submit',async event=>{
                event.preventDefault();if(stage!=='Confirmación'||busy)return;capture();
                if(!draft.selected)return;
                if(online&&(supervisor||!options.online_payment_available))return;
                if(!online&&!draft.free&&!draft.payment_confirmed){error='Verifique la recepción del pago antes de emitir la ficha.';paint();return;}
                if(!draft.operationKey)draft.operationKey=crypto.randomUUID();
                const data={patient_id:draft.patient_id,specialty_id:draft.specialty_id,doctor_id:draft.selected.doctor_id,date:draft.date,start_time:draft.selected.start_time,idempotency_key:draft.operationKey,reason:draft.reason||'',...(followup?{parent_appointment_id:draft.parent_appointment_id,followup_change_cause:draft.followup_change_cause||'patient',followup_change_reason:draft.followup_change_reason||''}:{})};
                if(!online&&!draft.free)Object.assign(data,{payment_method:draft.payment_method||'cash',payment_amount_minor:specialty()?.fee_minor,payment_confirmed:draft.payment_confirmed,bank_reference:draft.bank_reference||''});
                if(online){delete data.patient_id;delete data.reason;}
                busy=true;error='';save();paint();
                try {
                    const result=await api(online?'online-bookings':'appointments','POST',data);
                    state.bookingDraft=null;state.workflowContext=null;
                    if(online)await ui.clinicalActions.showOnline(result);
                    else {state.flowFeedback={message:'Cita emitida. El siguiente paso es registrar la llegada del paciente el día de la atención.',appointmentId:result.id};await navigate('CU12');ui.clinicalActions.ficha(result);}
                }catch(e){busy=false;error=e.message;paint();}
            });
            bindChoices();
        }
        /** Enlaza opciones de selección y conserva el borrador hasta confirmar. */
        function bindChoices() {
            on('[data-pick]','click',e=>{
                const {pick,value}=e.currentTarget.dataset;
                if(pick==='patient')draft.patient_id=value;
                if(pick==='specialty'){draft.specialty_id=value;draft.selected=null;draft.doctor_id='';}
                if(pick==='parent'){const a=parents.find(p=>String(p.id)===value);draft.parent_appointment_id=value;draft.patient_id=a.patient_id;draft.specialty_id=a.specialty_id;draft.doctor_id=a.doctor_id;draft.date=a.followup_suggested_at.slice(0,10);}
                save();paint();
            },$('#booking-flow'));
        }
        if(!saved.view&&followup&&context.parent_appointment_id){const a=parents.find(p=>String(p.id)===String(context.parent_appointment_id));if(a){Object.assign(draft,{parent_appointment_id:a.id,patient_id:a.patient_id,specialty_id:a.specialty_id,doctor_id:a.doctor_id,date:a.followup_suggested_at.slice(0,10),step:stepIndex('Horario')});}}
        if(!saved.view&&context.specialty_id){draft.specialty_id=context.specialty_id;draft.date=context.date||draft.date;draft.selected=context.slot||null;draft.doctor_id=draft.selected?.doctor_id||'';draft.step=staff&&!draft.patient_id?0:draft.selected?steps.length-1:stepIndex('Horario');}
        const reconcileReturn=()=>{
            if(!online||supervisor)return;
            const params=new URLSearchParams(location.search),id=params.get('paypal_booking');
            if(!id||!/^\d+$/.test(id))return;
            for(const key of ['paypal_booking','token','PayerID'])params.delete(key);
            window.history.replaceState(null,'',`${location.pathname}${params.size?'?'+params:''}#reservar-cita`);
            api(`online-bookings/${id}/status`,'POST',{}).then(ui.clinicalActions.showOnline).catch(e=>toast(e.message,true));
        };
        return {html:(online&&!options.online_payment_available?notice('El pago online no está habilitado. La clínica puede gestionar una cita presencial sin cuenta de paciente.','warning'):'')+(supervisor?notice('Vista de supervisión. Las reservas personales se crean desde la cuenta del paciente.'): '')+`<div id="booking-flow"></div>`+(requests.length?panel(supervisor?'Reservas online de la clínica':'Sus reservas en proceso',requests.map(r=>`<div class="operation-row"><span>${esc(r.date)} · ${esc(String(r.start_time).slice(0,5))}</span><span>${esc(({processing:'Pago en verificación',held:'Horario retenido',completed:'Cita confirmada',expired:'Reserva vencida',cancelled:'Reserva descartada'})[r.status]||r.status)}</span>${button('Ver operación',`data-reservation="${r.id}"`,'')}</div>`).join('')):''),bind:()=>{paint();reconcileReturn();if(['Horario','Horarios'].includes(steps[draft.step]))loadSlots();on('[data-reservation]','click',async e=>{const id=e.currentTarget.dataset.reservation;await ui.clinicalActions.showOnline(supervisor?await api(`online-bookings/${id}`):await api(`online-bookings/${id}/status`,'POST',{}));});}};
    }
    views.CU09=()=>booking();views.CU18=()=>booking({online:true});views.CU21=()=>booking({followup:true});views.CU08=()=>booking({consult:true});
}
