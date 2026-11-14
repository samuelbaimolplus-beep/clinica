/** CU08/CU09/CU18/CU21: selección guiada de atención, cupo y confirmación, separada del registro definitivo en el servidor. */
import {desplazarFecha} from "../lib/flujo-clinico";

export function instalarFlujoReserva(interfaz) {
    const {$,estadoLocal: estadoLocal,vistas: vistas,clienteApi: clienteApi,escapar: escapar,nombreCompleto: nombreCompleto,fechaLocalLocal: fechaLocalLocal,campo: campo,seleccionar: seleccionar,areaTexto: areaTexto,boton: boton,panel,aviso: aviso,enlazarEvento: enlazarEvento,navegar: navegar,notificacion: notificacion}=interfaz;
    const importeMonetario=valorDato=>valorDato==null?'Tarifa por definir':`Bs ${(valorDato/100).toFixed(2)}`;
    const diaSemana=valorDato=>new Date(valorDato+'T12:00:00Z').toLocaleDateString('es-BO',{weekday:'long',day:'numeric',month:'long',timeZone:'America/La_Paz'});
    const normalizar=valorDato=>String(valorDato||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();

    /** Construye el recorrido según cita presencial, portal, seguimiento o consulta de disponibilidad. */
    async function reserva({online: porInternet=false,followup: seguimiento=false,consult: consultar=false}={}) {
        const vista=estadoLocal.view,secuencia=estadoLocal.sequence;
        const opciones=await clienteApi('medical-availability/options');
        const padres=seguimiento?(await clienteApi('appointments?mode=history&status=Atendida')).filter(a=>a.followup_suggested_at):[];
        const solicitudes=porInternet?await clienteApi('online-bookings'):[];
        const supervisorCuenta=porInternet&&estadoLocal.user.role==='Superadmin';
        const personal=!porInternet&&!consultar;
        const guardadoLocal=estadoLocal.bookingDraft?.view===vista?estadoLocal.bookingDraft:{};
        const contexto=estadoLocal.workflowContext||{};
        const borrador={view: vista,step:0,patient_id:contexto.patient_id||'',specialty_id:'',doctor_id:'',date:fechaLocalLocal(),selected:null,...guardadoLocal};
        const pasos=consultar?['Atención','Horarios']:seguimiento?['Consulta de origen','Atención','Horario','Confirmación']:personal?['Paciente','Atención','Horario','Confirmación']:['Atención','Horario','Confirmación'];
        let franjas=[],cargando=false,fallo='',revision=0,ocupadas=false;
        let busquedaPaciente='';
        const guardar=()=>{estadoLocal.bookingDraft={...borrador};};
        const especialidad=()=>opciones.specialties.find(s=>String(s.id)===String(borrador.specialty_id));
        const paciente=()=>estadoLocal.refs.patients.find(p=>String(p.id)===String(borrador.patient_id));
        const padre=()=>padres.find(p=>String(p.id)===String(borrador.parent_appointment_id));
        const indicePaso=etiqueta=>pasos.indexOf(etiqueta);
        const ir=paso=>{borrador.step=paso;fallo='';guardar();pintar();if(['Horario','Horarios'].includes(pasos[paso]))cargarFranjas();};
        const alternativas=(elementos,clase,actual)=>`<div class="choice-grid">${elementos.map(elemento=>`<button type="button" class="option-card ${String(elemento.id)===String(actual)?'chosen':''}" data-pick="${clase}" data-value="${escapar(elemento.id)}" aria-pressed="${String(elemento.id)===String(actual)}"><span class="option-symbol">${escapar(elemento.symbol||elemento.title.slice(0,1))}</span><span><strong>${escapar(elemento.title)}</strong><small>${escapar(elemento.description||'')}</small></span><span class="option-indicator" aria-hidden="true">${String(elemento.id)===String(actual)?'✓':'→'}</span></button>`).join('')}</div>`;
        /** Descarta respuestas tardías para no mostrar horarios de un criterio anterior. */
        async function cargarFranjas() {
            if(!borrador.specialty_id)return;
            const token=++revision;cargando=true;fallo='';pintar();
            try {
                const resultado=await clienteApi('medical-availability?'+new URLSearchParams({specialty_id:borrador.specialty_id,doctor_id:borrador.doctor_id,date:borrador.date,modality:porInternet||estadoLocal.user.role==='Paciente'?'online':'presential',...(!porInternet&&estadoLocal.user.role!=='Paciente'?{include_demand:'1'}:{})}));
                if(token!==revision||estadoLocal.view!==vista||estadoLocal.sequence!==secuencia)return;
                franjas=resultado.blocks;borrador.slotMessage=resultado.message;
            }catch(e){if(token===revision){franjas=[];fallo=e.message;}}
            finally{if(token===revision&&estadoLocal.view===vista&&estadoLocal.sequence===secuencia){cargando=false;pintar();}}
        }
        /** Presenta especialidades y tarifa antes de seleccionar horario. */
        function atencion() {
            return `<h2>¿Qué atención necesita?</h2><p class="flow-intro">Elija una especialidad. Verá su tarifa antes de seleccionar un horario.</p>`
                +alternativas(opciones.specialties.map(s=>({id:s.id,title:s.name,description:importeMonetario(s.fee_minor),symbol:'✚'})),'specialty',borrador.specialty_id)
                +(!opciones.specialties.length?aviso('No hay especialidades activas. Administración debe registrar una especialidad y su tarifa antes de programar citas.','warning'):'');
        }
        /** Permite elegir una ficha o la consulta que origina un seguimiento. */
        function quien() {
            if(seguimiento)return `<h2>Seleccione la consulta que requiere seguimiento</h2><p class="flow-intro">Solo aparecen consultas finalizadas con una recomendación del médico.</p>`
                +(padres.length?alternativas(padres.map(a=>({id:a.id,title:nombreCompleto(a.patient),description:`${a.specialty.name} · Sugerencia: ${diaSemana(a.followup_suggested_at.slice(0,10))}`})),'parent',borrador.parent_appointment_id):aviso('Todavía no hay consultas con una recomendación de seguimiento. El médico la registra al finalizar la atención.'));
            return `<div class="section-toolbar"><div><h2>¿Para quién es la cita?</h2><p class="flow-intro">Busque su ficha. El paciente no necesita una cuenta ni correo para recibir atención.</p></div>${boton('+ Registrar paciente','id="booking-register-patient"','')}</div>`
                +`<label class="field picker-search"><span>Buscar por nombre o documento</span><input id="booking-patient-search" type="search" value="${escapar(busquedaPaciente)}" placeholder="Escriba el nombre o CI del paciente" autocomplete="off"></label><div id="booking-patient-results">${resultadosPacientes()}</div>`;
        }
        function resultadosPacientes() {
            const pacientes=estadoLocal.refs.patients.filter(p=>p.status==='Activo'&&normalizar(`${nombreCompleto(p)} ${p.identity||''}`).includes(normalizar(busquedaPaciente)));
            return pacientes.length?alternativas(pacientes.slice(0,12).map(p=>({id:p.id,title:nombreCompleto(p),description:`${p.identity?'CI '+p.identity:'Sin CI'} · Ficha registrada`,symbol:nombreCompleto(p).slice(0,1)})),'patient',borrador.patient_id)+(pacientes.length>12?`<p class="help-text">Hay ${pacientes.length} coincidencias. Escriba más letras para encontrar la ficha.</p>`:''):aviso('No encontramos esa ficha. Puede registrar al paciente y volver a esta cita.');
        }
        /** Presenta médico, día y franjas libres sin crear la cita. */
        function cuando() {
            const medicos=opciones.doctors.filter(d=>d.specialties.some(s=>String(s.id)===String(borrador.specialty_id)));
            return `<h2>${consultar?'Explore los horarios disponibles':'Elija médico, día y hora'}</h2><p class="flow-intro">${escapar(especialidad()?.name)} · ${importeMonetario(especialidad()?.fee_minor)}. Seleccionar un horario todavía no crea una cita.</p>`
                +`<div class="doctor-chips" aria-label="Preferencia de médico"><button type="button" class="filter-chip ${!borrador.doctor_id?'selected':''}" data-doctor="">Cualquier médico disponible</button>${medicos.map(d=>`<button type="button" class="filter-chip ${String(d.id)===String(borrador.doctor_id)?'selected':''}" data-doctor="${d.id}">${escapar(nombreCompleto(d))}</button>`).join('')}</div>`
                +`<div class="date-strip-toolbar">${boton('‹ Semana anterior','data-date-page="-7"','')}<label class="field"><span>Ir a otra fecha</span><input id="booking-date" type="date" value="${borrador.date}" min="${fechaLocalLocal()}"></label>${boton('Semana siguiente ›','data-date-page="7"','')}</div>`
                +`<div class="date-strip" aria-label="Elegir día">${Array.from({length:7},(_,i)=>desplazarFecha(borrador.date,i)).map(fechaLocal=>`<button type="button" data-booking-date="${fechaLocal}" class="date-tile ${fechaLocal===borrador.date?'selected':''}" aria-pressed="${fechaLocal===borrador.date}"><span>${escapar(new Date(fechaLocal+'T12:00:00Z').toLocaleDateString('es-BO',{weekday:'short',timeZone:'America/La_Paz'}))}</span><strong>${fechaLocal.slice(-2)}</strong><small>${fechaLocal===fechaLocalLocal()?'Hoy':escapar(new Date(fechaLocal+'T12:00:00Z').toLocaleDateString('es-BO',{month:'short',timeZone:'America/La_Paz'}))}</small></button>`).join('')}</div>`
                +(cargando?'<div class="loading-state" role="status"><span class="spinner"></span><p>Buscando horarios libres…</p></div>':franjas.length?`<div class="slot-groups">${[...new Set(franjas.map(s=>s.doctor_id))].map(id=>`<section class="slot-group"><h3>${escapar(franjas.find(s=>s.doctor_id===id).doctor_name)}</h3><div class="slot-grid">${franjas.map((s,i)=>s.doctor_id===id?`<button type="button" data-flow-slot="${i}" class="slot-choice ${borrador.selected?.doctor_id===s.doctor_id&&borrador.selected?.start_time===s.start_time?'selected':''}"><strong>${escapar(s.start_time)}</strong><span>hasta ${escapar(s.end_time)}</span><small>${s.demand_blocked?'Cupo presencial protegido':'Elegir este horario'}</small></button>`:'').join('')}</div></section>`).join('')}</div>`:`<div class="friendly-empty"><strong>No hay horarios disponibles para este día</strong><p>${escapar(borrador.slotMessage||'Pruebe otro día o quite la preferencia de médico. No se ha creado ninguna cita.')}</p>${boton('Buscar al día siguiente','id="next-available-day"')}</div>`);
        }
        /** Resume la operación y exige comprobar el pago o la exención antes de confirmar. */
        function confirmacion() {
            const p=padre();
            const libres=p&&borrador.selected&&(p.followup_suggested_at.slice(0,16).replace('T',' ')===`${borrador.date} ${borrador.selected.start_time}`||borrador.followup_change_cause==='clinic');
            borrador.free=Boolean(libres);
            return `<h2>Revise la cita antes de confirmar</h2><p class="flow-intro">${porInternet?'El horario se retendrá al iniciar el pago; la ficha se emitirá cuando el servidor confirme el cobro.':'La ficha se emitirá al guardar la cita con el pago o la exención verificados.'}</p>`
                +`<div class="confirmation-ticket"><div><small>Paciente</small><strong>${escapar(porInternet?estadoLocal.user.name:nombreCompleto(paciente()))}</strong></div><div><small>Atención</small><strong>${escapar(especialidad()?.name)}</strong></div><div><small>Médico</small><strong>${escapar(borrador.selected?.doctor_name)}</strong></div><div><small>Fecha y hora</small><strong>${escapar(diaSemana(borrador.date))} · ${escapar(borrador.selected?.start_time)}</strong></div><div><small>Importe</small><strong>${libres?'Sin costo por seguimiento':importeMonetario(especialidad()?.fee_minor)}</strong></div></div>`
                +(seguimiento?`<details class="advanced-options" ${borrador.followup_change_cause==='clinic'?'open':''}><summary>¿Cambió la fecha sugerida por una causa de la clínica?</summary>${seleccionar('Origen del cambio','followup_change_cause',[['patient','Decisión del paciente / fecha original'],['clinic','Causa de la clínica']],borrador.followup_change_cause||'patient')}${areaTexto('Motivo y acuerdo del cambio','followup_change_reason',borrador.followup_change_reason||'','maxlength="3000"')}</details>`:'')
                +(porInternet?aviso(`PayPal Sandbox · dinero de prueba. Tasa fijada por el servidor: ${opciones.bob_per_usd||'—'} Bs por USD. Se mostrará el importe convertido antes de pagar.`):libres?aviso('La consulta de seguimiento cumple las condiciones de exención. No se solicitará un cobro.','success'):`<h3>¿Cómo recibió el pago?</h3><div class="segmented-control">${[['cash','Efectivo'],['qr','QR presencial'],['transfer','Transferencia']].map(([valorDato,etiqueta])=>`<button type="button" data-pay-method="${valorDato}" class="filter-chip ${(borrador.payment_method||'cash')===valorDato?'selected':''}">${etiqueta}</button>`).join('')}</div>${(borrador.payment_method||'cash')!=='cash'?campo('Referencia bancaria','bank_reference','text',borrador.bank_reference||'','required maxlength="180"'):''}<label class="confirmation-check"><input name="payment_confirmed" type="checkbox" value="1" ${borrador.payment_confirmed?'checked':''} required><span>Confirmo que recibí y verifiqué ${importeMonetario(especialidad()?.fee_minor)}. Esta acción registrará el cobro.</span></label>`)
                +(!porInternet?`<details class="advanced-options"><summary>Añadir motivo de consulta (opcional)</summary>${areaTexto('Motivo','reason',borrador.reason||'','maxlength="3000"')}</details>`:'');
        }
        /** Dibuja el paso actual y vuelve a enlazar sus acciones después de reemplazar el contenido. */
        function pintar() {
            const raiz=$('#booking-flow');if(!raiz)return;
            const etapa=pasos[borrador.step];
            const actual=etapa==='Paciente'||etapa==='Consulta de origen'?quien():etapa==='Atención'?atencion():etapa==='Horario'||etapa==='Horarios'?cuando():confirmacion();
            raiz.innerHTML=`<div class="flow-layout"><section class="flow-main"><ol class="journey-steps" aria-label="Pasos de ${consultar?'consulta':'programación'}">${pasos.map((s,i)=>`<li class="${i<borrador.step?'done':''}" ${i===borrador.step?'aria-current="step"':''}><span>${i<borrador.step?'✓':i+1}</span>${escapar(s)}</li>`).join('')}</ol><div id="flow-error" role="alert">${fallo?aviso(fallo,'error'):''}</div><form id="guided-booking">${actual}<div class="flow-navigation">${borrador.step?boton('← Volver','id="flow-back"',''):'<span></span>'}${['Paciente','Atención','Consulta de origen'].includes(etapa)?boton('Continuar →','id="flow-next"'):etapa==='Confirmación'?`<button type="submit" class="btn success" ${ocupadas||supervisorCuenta||porInternet&&!opciones.online_payment_available?'disabled':''}>${ocupadas?'Procesando…':porInternet?'Continuar a PayPal Sandbox':'Confirmar cita y emitir ficha'}</button>`:''}</div></form></section><aside class="flow-summary"><span class="eyebrow">SU RECORRIDO</span><h3>${consultar?'Consulta de disponibilidad':seguimiento?'Seguimiento médico':porInternet?'Reserva online':'Cita en la clínica'}</h3><p>${consultar?'Puede revisar horarios sin reservar.':porInternet?'Su reserva se confirma después del pago.':'Recepción puede completar todo el proceso sin cuenta del paciente.'}</p><dl><dt>Paciente</dt><dd>${escapar(porInternet?estadoLocal.user.name:paciente()?nombreCompleto(paciente()):'Por seleccionar')}</dd><dt>Especialidad</dt><dd>${escapar(especialidad()?.name||'Por seleccionar')}</dd><dt>Médico y horario</dt><dd>${borrador.selected?`${escapar(borrador.selected.doctor_name)}<br>${escapar(diaSemana(borrador.date))}<br>${escapar(borrador.selected.start_time)}–${escapar(borrador.selected.end_time)}`:'Por seleccionar'}</dd></dl><div class="outcome-hint"><strong>¿Qué ocurre después?</strong><p>${consultar?'La consulta no retiene cupos. Inicie una cita para reservar.':porInternet?'Una vez pagada, la cita aparecerá en su historial y en la agenda de la clínica.':'Después de emitir la ficha, registre la llegada el día de la cita. El médico verá al paciente en su agenda.'}</p></div></aside></div>`;
            const captura=()=>{const formularioLocal=$('#guided-booking');if(!formularioLocal)return;const valores=Object.fromEntries(new FormData(formularioLocal));Object.assign(borrador,valores);if(formularioLocal.elements.namedItem('payment_confirmed'))borrador.payment_confirmed=formularioLocal.elements.namedItem('payment_confirmed').checked;guardar();};
            enlazarEvento('#guided-booking input,#guided-booking textarea','input',captura);
            enlazarEvento('#flow-back','click',()=>{captura();ir(borrador.step-1);});
            enlazarEvento('#flow-next','click',()=>{
                if(etapa==='Paciente'&&!borrador.patient_id)fallo='Seleccione una ficha de paciente para continuar.';
                else if(etapa==='Consulta de origen'&&!borrador.parent_appointment_id)fallo='Seleccione la consulta que recomendó el seguimiento.';
                else if(etapa==='Atención'&&!borrador.specialty_id)fallo='Elija una especialidad para ver sus horarios.';
                else {ir(borrador.step+1);return;}
                pintar();
            });
            enlazarEvento('#booking-register-patient','click',async()=>{guardar();estadoLocal.returnToBooking=vista;await navegar('CU06');$('#new-entity')?.click();});
            enlazarEvento('#booking-patient-search','input',e=>{busquedaPaciente=e.target.value;$('#booking-patient-results').innerHTML=resultadosPacientes();enlazarOpciones();});
            enlazarEvento('[data-doctor]','click',e=>{borrador.doctor_id=e.currentTarget.dataset.doctor;borrador.selected=null;guardar();cargarFranjas();});
            const cambiarFecha=fechaLocal=>{if(fechaLocal<fechaLocalLocal())return;borrador.date=fechaLocal;borrador.selected=null;guardar();cargarFranjas();};
            enlazarEvento('[data-booking-date]','click',e=>cambiarFecha(e.currentTarget.dataset.bookingDate));
            enlazarEvento('[data-date-page]','click',e=>cambiarFecha(desplazarFecha(borrador.date,Number(e.currentTarget.dataset.datePage))));
            enlazarEvento('#booking-date','change',e=>{if(e.target.value)cambiarFecha(e.target.value);});
            enlazarEvento('#next-available-day','click',()=>cambiarFecha(desplazarFecha(borrador.date,1)));
            enlazarEvento('[data-flow-slot]','click',e=>{
                borrador.selected=franjas[Number(e.currentTarget.dataset.flowSlot)];guardar();
                if(consultar){estadoLocal.workflowContext={...estadoLocal.workflowContext,specialty_id:borrador.specialty_id,date:borrador.date,slot:borrador.selected};navegar(estadoLocal.user.role==='Paciente'?'CU18':'CU09');}
                else ir(borrador.step+1);
            });
            enlazarEvento('[data-pay-method]','click',e=>{captura();borrador.payment_method=e.currentTarget.dataset.payMethod;borrador.payment_confirmed=false;pintar();});
            enlazarEvento('[name=followup_change_cause]','change',()=>{captura();pintar();});
            $('#guided-booking').addEventListener('submit',async evento=>{
                evento.preventDefault();if(etapa!=='Confirmación'||ocupadas)return;captura();
                if(!borrador.selected)return;
                if(porInternet&&(supervisorCuenta||!opciones.online_payment_available))return;
                if(!porInternet&&!borrador.free&&!borrador.payment_confirmed){fallo='Verifique la recepción del pago antes de emitir la ficha.';pintar();return;}
                if(!borrador.operationKey)borrador.operationKey=crypto.randomUUID();
                const datos={patient_id:borrador.patient_id,specialty_id:borrador.specialty_id,doctor_id:borrador.selected.doctor_id,date:borrador.date,start_time:borrador.selected.start_time,idempotency_key:borrador.operationKey,reason:borrador.reason||'',...(seguimiento?{parent_appointment_id:borrador.parent_appointment_id,followup_change_cause:borrador.followup_change_cause||'patient',followup_change_reason:borrador.followup_change_reason||''}:{})};
                if(!porInternet&&!borrador.free)Object.assign(datos,{payment_method:borrador.payment_method||'cash',payment_amount_minor:especialidad()?.fee_minor,payment_confirmed:borrador.payment_confirmed,bank_reference:borrador.bank_reference||''});
                if(porInternet){delete datos.patient_id;delete datos.reason;}
                ocupadas=true;fallo='';guardar();pintar();
                try {
                    const resultado=await clienteApi(porInternet?'online-bookings':'appointments','POST',datos);
                    estadoLocal.bookingDraft=null;estadoLocal.workflowContext=null;
                    if(porInternet)await interfaz.accionesClinicas.mostrarReservaOnline(resultado);
                    else {estadoLocal.flowFeedback={message:'Cita emitida. El siguiente paso es registrar la llegada del paciente el día de la atención.',appointmentId:resultado.id};await navegar('CU12');interfaz.accionesClinicas.ficha(resultado);}
                }catch(e){ocupadas=false;fallo=e.message;pintar();}
            });
            enlazarOpciones();
        }
        /** Enlaza opciones de selección y conserva el borrador hasta confirmar. */
        function enlazarOpciones() {
            enlazarEvento('[data-pick]','click',e=>{
                const {pick: elegir,value: valorDato}=e.currentTarget.dataset;
                if(elegir==='patient')borrador.patient_id=valorDato;
                if(elegir==='specialty'){borrador.specialty_id=valorDato;borrador.selected=null;borrador.doctor_id='';}
                if(elegir==='parent'){const a=padres.find(p=>String(p.id)===valorDato);borrador.parent_appointment_id=valorDato;borrador.patient_id=a.patient_id;borrador.specialty_id=a.specialty_id;borrador.doctor_id=a.doctor_id;borrador.date=a.followup_suggested_at.slice(0,10);}
                guardar();pintar();
            },$('#booking-flow'));
        }
        if(!guardadoLocal.view&&seguimiento&&contexto.parent_appointment_id){const a=padres.find(p=>String(p.id)===String(contexto.parent_appointment_id));if(a){Object.assign(borrador,{parent_appointment_id:a.id,patient_id:a.patient_id,specialty_id:a.specialty_id,doctor_id:a.doctor_id,date:a.followup_suggested_at.slice(0,10),step:indicePaso('Horario')});}}
        if(!guardadoLocal.view&&contexto.specialty_id){borrador.specialty_id=contexto.specialty_id;borrador.date=contexto.date||borrador.date;borrador.selected=contexto.slot||null;borrador.doctor_id=borrador.selected?.doctor_id||'';borrador.step=personal&&!borrador.patient_id?0:borrador.selected?pasos.length-1:indicePaso('Horario');}
        const conciliarRetorno=()=>{
            if(!porInternet||supervisorCuenta)return;
            const parametros=new URLSearchParams(location.search),id=parametros.get('paypal_booking');
            if(!id||!/^\d+$/.test(id))return;
            for(const clave of ['paypal_booking','token','PayerID'])parametros.delete(clave);
            window.history.replaceState(null,'',`${location.pathname}${parametros.size?'?'+parametros:''}#reservar-cita`);
            clienteApi(`online-bookings/${id}/status`,'POST',{}).then(interfaz.accionesClinicas.mostrarReservaOnline).catch(e=>notificacion(e.message,true));
        };
        return {html:(porInternet&&!opciones.online_payment_available?aviso('El pago online no está habilitado. La clínica puede gestionar una cita presencial sin cuenta de paciente.','warning'):'')+(supervisorCuenta?aviso('Vista de supervisión. Las reservas personales se crean desde la cuenta del paciente.'): '')+`<div id="booking-flow"></div>`+(solicitudes.length?panel(supervisorCuenta?'Reservas online de la clínica':'Sus reservas en proceso',solicitudes.map(r=>`<div class="operation-row"><span>${escapar(r.date)} · ${escapar(String(r.start_time).slice(0,5))}</span><span>${escapar(({processing:'Pago en verificación',held:'Horario retenido',completed:'Cita confirmada',expired:'Reserva vencida',cancelled:'Reserva descartada'})[r.status]||r.status)}</span>${boton('Ver operación',`data-reservation="${r.id}"`,'')}</div>`).join('')):''),bind:()=>{pintar();conciliarRetorno();if(['Horario','Horarios'].includes(pasos[borrador.step]))cargarFranjas();enlazarEvento('[data-reservation]','click',async e=>{const id=e.currentTarget.dataset.reservation;await interfaz.accionesClinicas.mostrarReservaOnline(supervisorCuenta?await clienteApi(`online-bookings/${id}`):await clienteApi(`online-bookings/${id}/status`,'POST',{}));});}};
    }
    vistas.CU09=()=>reserva();vistas.CU18=()=>reserva({online:true});vistas.CU21=()=>reserva({followup:true});vistas.CU08=()=>reserva({consult:true});
}
