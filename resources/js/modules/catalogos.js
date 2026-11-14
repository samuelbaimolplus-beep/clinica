/** CU03–CU06: directorios y altas en contexto. La ficha de paciente es independiente de una cuenta opcional. */
export function instalarVistasCatalogos(interfaz) {
    const { $, estadoLocal: estadoLocal, vistas: vistas, clienteApi: clienteApi, escapar: escapar, nombreCompleto: nombreCompleto, codigo: codigo, distintivo: distintivo, campo: campo, seleccionar: seleccionar, areaTexto: areaTexto, opcionesPersonas: opcionesPersonas, fechaLocalLocal: fechaLocalLocal, tabla: tabla, boton: boton, modal, erroresFormulario: erroresFormulario, botonesAccion: botonesAccion, enlazarEvento: enlazarEvento, cerrarModal: cerrarModal, enlazarFormulario: enlazarFormulario, cargarReferencias: cargarReferencias, navegar: navegar, notificacion: notificacion, confirmarAccion: confirmarAccion, panel, detalles: detalles, aviso: aviso, hora: hora, obtenerVersionTerminos: obtenerVersionTerminos } = interfaz;
const limiteMayoriaEdad=()=>{const [anioCalendario,mesCalendario,diaSemana]=fechaLocalLocal().split('-').map(Number);const ultimoDia=new Date(Date.UTC(anioCalendario-18,mesCalendario,0)).getUTCDate();return new Date(Date.UTC(anioCalendario-18,mesCalendario-1,Math.min(diaSemana,ultimoDia),12)).toISOString().slice(0,10);};
// CU03–CU06: registros persistentes y activación sin eliminar información.
const configuraciones = {
    users:{title:'Usuarios registrados',new:'Registrar usuario',headers:['Usuario','Correo','Nombre','Rol','Médico vinculado','Estado','Activación'], row:r=>[escapar(r.username),escapar(r.email),escapar(r.name),escapar(r.role),escapar(nombreCompleto(estadoLocal.refs.doctors.find(d=>d.id===r.doctor_id))),distintivo(r.status),escapar(r.email_verified_at?'Correo confirmado':'Pendiente de código')]},
    doctors:{title:'Médicos registrados',new:'Registrar médico',headers:['Código','N.º registro','Médico','Teléfono','Especialidades','Estado'],row:r=>[codigo('MED',r.id),escapar(r.registration),escapar(nombreCompleto(r)),escapar(r.phone),escapar(r.specialties.map(s=>s.name).join(', ')),distintivo(r.status)]},
    specialties:{title:'Especialidades',new:'Registrar especialidad',headers:['Código','Nombre','Descripción','Estado'],row:r=>[codigo('ESP',r.id),escapar(r.name),escapar(r.description||'—'),distintivo(r.status)]},
    patients:{title:'Pacientes registrados',new:'Registrar paciente',headers:['Código','CI','Paciente','Nacimiento','Sexo','Teléfono','Estado','Cuenta de acceso'],row:r=>[codigo('PAC',r.id),escapar(r.identity),escapar(nombreCompleto(r)),escapar(r.birth_date),escapar(r.sex),escapar(r.phone),distintivo(r.status),r.account?`${escapar(r.account.username)} · ${r.account.email_verified_at?'Activada':'Pendiente de código'}`:'Portal opcional no solicitado']},
};
function camposCuenta(paciente={},incluirCorreo=true) {
    return (incluirCorreo?campo('Correo para activar la cuenta','email','email',paciente.email,'required maxlength="255" autocomplete="off"'):'')
        +campo('Nombre de usuario','username','text','','required minlength="3" maxlength="50" autocomplete="off"')
        +campo('Contraseña inicial','password','password','','required minlength="10" maxlength="128" autocomplete="new-password"')
        +campo('Repetir contraseña inicial','password_confirmation','password','','required minlength="10" maxlength="128" autocomplete="new-password"')
        +`<label class="terms-accept full"><input name="identity_verified" type="checkbox" value="1" required>He verificado presencialmente la identidad del paciente y revisado su correo de contacto.</label>`
        +`<label class="terms-accept full"><input name="terms_accepted" type="checkbox" value="1" required>El paciente adulto solicitó su cuenta y aceptó los <a href="/terms" target="_blank" rel="noopener">términos y condiciones</a> vigentes.</label><input type="hidden" name="terms_version" value="${escapar(obtenerVersionTerminos())}">`
        +`<div class="full">${aviso('Se enviará un código al correo del paciente. La cuenta permanecerá bloqueada hasta que lo confirme desde «Activar mi cuenta». Entregue las credenciales únicamente al titular.')}</div>`;
}
function camposEntidad(entidad,r={}) {
    const peticionBreve='required maxlength="100"';
    const personaRegistro = campo('Nombres','first_name','text',r.first_name,peticionBreve)+campo('Apellidos','last_name','text',r.last_name,'required maxlength="150"')+campo('Teléfono','phone','tel',r.phone,'required maxlength="30"');
    let contenido='';
    if(entidad==='users') {
        contenido = campo('Nombre de usuario','username','text',r.username,'required minlength="3" maxlength="50" autocomplete="off" title="Letras, numeros, punto, guion y guion bajo; sin espacios."')+campo('Nombre completo','name','text',r.name,'required maxlength="150"')+campo('Correo electrónico','email','email',r.email,'required maxlength="255" autocomplete="off"')+campo(r.id?'Nueva contraseña (vacía para conservar)':'Contraseña (mínimo 10 caracteres)','password','password','','minlength="10" maxlength="128" autocomplete="new-password" '+(r.id?'':'required'))+seleccionar('Rol','role',(estadoLocal.user.role==='Superadmin'?['Superadmin','Administrador','Recepcionista','Médico']:['Administrador','Recepcionista','Médico']),r.role||'Recepcionista')+`<div id="doctor-link">${seleccionar('Médico vinculado','doctor_id',[['','Seleccione un médico…'],...opcionesPersonas(estadoLocal.refs.doctors.filter(d=>d.status==='Activo'||d.id===r.doctor_id))],r.doctor_id)}</div>`;
    } else if(entidad==='doctors') {
        contenido=campo('Código de médico','code','text',r.id?codigo('MED',r.id):'Automático al guardar','readonly')+campo('Número de registro profesional','registration','text',r.registration,'required maxlength="80"')+personaRegistro+campo('Correo electrónico','email','email',r.email,'required maxlength="255"')+`<div class="full"><span class="label">Especialidades activas *</span><div class="checks">${estadoLocal.refs.specialties.filter(s=>s.status==='Activo').map(s=>`<label><input type="checkbox" name="specialty_ids" value="${s.id}" ${r.specialties?.some(x=>x.id===s.id)?'checked':''}>${escapar(s.name)}</label>`).join('')||'<small>Registre una especialidad activa antes de crear médicos.</small>'}</div>${r.specialties?.some(s=>s.status==='Inactivo')?aviso('Este médico conserva relaciones con especialidades inactivas. Al guardar la edición, seleccione sus especialidades activas; se identificarán las citas afectadas por las retiradas.') : ''}</div>`;
    } else if(entidad==='specialties')contenido=campo('Código','code','text',r.id?codigo('ESP',r.id):'Automático al guardar','readonly')+campo('Nombre de especialidad','name','text',r.name,'required maxlength="120"')+areaTexto('Descripción (opcional)','description',r.description,'maxlength="3000"')+campo('Tarifa de consulta (Bs)','fee_amount','number',r.fee_minor==null?'':(r.fee_minor/100).toFixed(2),'min="0.01" max="999999.99" step="0.01"');
    else contenido=campo('Código de paciente','code','text',r.id?codigo('PAC',r.id):'Automático al guardar','readonly')+campo('Documento de identidad (CI, opcional)','identity','text',r.identity,'maxlength="40"')+personaRegistro+campo('Fecha de nacimiento','birth_date','date',r.birth_date,`required max="${fechaLocalLocal()}"`)+seleccionar('Sexo','sex',['Femenino','Masculino'],r.sex)+campo('Correo electrónico (opcional)','email','email',r.email,'maxlength="255"')+areaTexto('Dirección (opcional)','address',r.address,'maxlength="1000"');
    if(entidad==='users')contenido=campo('Fecha de nacimiento','birth_date','date',r.birth_date||'',`required max="${limiteMayoriaEdad()}"`)+contenido;
    if (!r.id && ['doctors','specialties','patients'].includes(entidad)) {
        return contenido+'<input type="hidden" name="status" value="Activo">'+aviso('El registro se creará activo.');
    }
    return contenido+seleccionar('Estado','status',['Activo','Inactivo'],r.status||'Activo');
}
async function vistaGestion(entidad) {
    const filas=await clienteApi(`entities/${entidad}`), configuracion=configuraciones[entidad];
    const contenidoFilas = elementos=>tabla([...configuracion.headers,'Acciones'],elementos.map(r=>[...configuracion.row(r),(boton('Consultar',`data-show="${r.id}"`,'link'))+(entidad==='patients'&&interfaz.vistasPermitidas().includes('CU09')&&r.status==='Activo'?boton('Programar cita',`data-patient-book="${r.id}"`,'success'):'')+(entidad==='patients'&&interfaz.vistasPermitidas().includes('CU15')?boton('Ver citas',`data-patient-history="${r.id}"`,'link'):'')+boton('Modificar',`data-edit="${r.id}"`,'link')+(entidad==='doctors'?boton('Ver horarios',`data-doctor-schedule="${r.id}"`,'link'):'')+boton(r.status==='Activo'?'Desactivar':'Activar',`data-toggle="${r.id}"`,'link')+(entidad==='patients'&&r.status==='Activo'&&!r.account&&r.birth_date&&r.birth_date<=limiteMayoriaEdad()?boton('Habilitar portal opcional',`data-create-account="${r.id}"`,'link'):'')]));
    function contenidoImpacto(registro) {
        const afectadas = registro.affected_appointments || [];
        if (!afectadas.length) return '';
        return panel('Citas futuras afectadas', aviso('Las citas conservan su estado y quedan pendientes de gestión por recepción. No se cancelaron automáticamente.')+
            tabla(['Código','Fecha','Horario','Estado','Motivo'],afectadas.map(citaLocal=>[
                escapar(codigo('CIT',citaLocal.id)),escapar(citaLocal.date),`${escapar(hora(citaLocal.start_time))}–${escapar(hora(citaLocal.end_time))}`,
                distintivo(citaLocal.status),escapar(citaLocal.reasons.join(', ')),
            ])));
    }
    function mostrarImpacto(registro) {
        if (registro.affected_appointments?.length) modal('Resultado del cambio', contenidoImpacto(registro));
    }
    /** Abre datos editables del registro seleccionado y activa únicamente los campos pertinentes. */
    function editar(registro={}) {
        const cuentaPaciente=entidad==='patients'&&!registro.id;
        modal(registro.id?'Modificar registro':configuracion.new,`<form id="entity-form">${erroresFormulario}<div class="grid two">${camposEntidad(entidad,registro)}${cuentaPaciente?`<div class="full">${aviso('La ficha permite recibir atención, pagar y gestionar citas sin tener cuenta ni correo. El portal es opcional para adultos que lo soliciten.')}</div><label class="terms-accept full"><input id="create-account" type="checkbox">El paciente adulto solicita una cuenta de acceso al portal</label><div id="patient-account-fields" class="grid two full">${camposCuenta({},false)}</div>`:''}</div>${botonesAccion(boton('Cancelar','data-dismiss','')+'<button type="submit" class="btn success">Guardar</button>')}</form>`);
        enlazarEvento('[data-dismiss]','click',cerrarModal);
        if(cuentaPaciente) {
            const actualizarDato=()=>{
                const nacimiento=$('#entity-form [name="birth_date"]').value;
                const esMenor=nacimiento&&nacimiento>limiteMayoriaEdad();
                $('#create-account').disabled=Boolean(esMenor);
                if(esMenor)$('#create-account').checked=false;
                const habilitado=$('#create-account').checked;
                $('#patient-account-fields').hidden=!habilitado;
                $('#patient-account-fields').querySelectorAll('input').forEach(entrada=>entrada.disabled=!habilitado);
                const correo=$('#entity-form [name="email"]');correo.required=habilitado;
                correo.closest('label').querySelector('span').textContent=habilitado?'Correo electrónico para activar la cuenta':'Correo electrónico (opcional)';
            };
            enlazarEvento('#create-account,#entity-form [name="birth_date"]','change',actualizarDato);actualizarDato();
        }
        if(entidad==='users') {
            const actualizarDato=()=>{const medico=$('#doctor_id');$('#doctor-link').hidden=$('#role').value!=='Médico';medico.required=!$('#doctor-link').hidden;medico.disabled=$('#doctor-link').hidden;if(medico.disabled)medico.value='';};
            enlazarEvento('#role','change',actualizarDato);actualizarDato();
        }
        enlazarFormulario('entity-form',async(datos,formularioLocal)=>{
            delete datos.code;
            if(entidad==='doctors')datos.specialty_ids=new FormData(formularioLocal).getAll('specialty_ids');
            if(entidad==='users'&&datos.role!=='Médico')datos.doctor_id=null;
            const conCuenta=cuentaPaciente&&$('#create-account').checked;
            const guardadoLocal=await clienteApi(conCuenta?'patients/with-account':`entities/${entidad}${registro.id?'/'+registro.id:''}`,registro.id?'PUT':'POST',datos);
            cerrarModal();await cargarReferencias();if(entidad==='users'&&registro.id===estadoLocal.user.id)estadoLocal.user=(await clienteApi('session')).user;
            if(entidad==='patients'&&estadoLocal.returnToBooking){const destinoElemento=estadoLocal.returnToBooking;estadoLocal.returnToBooking=null;estadoLocal.bookingDraft={...(estadoLocal.bookingDraft||{}),view:destinoElemento,patient_id:String(guardadoLocal.id)};await navegar(destinoElemento);notificacion('Ficha registrada. Continúe con la cita del paciente.');return;}
            await navegar(estadoLocal.view);notificacion(conCuenta?(guardadoLocal.activation_email_sent?'Paciente y cuenta registrados. Se envió el código de activación a su correo.':'Paciente y cuenta registrados. Solicite el código desde Activar mi cuenta.'):entidad==='users'&&!guardadoLocal.email_verified_at?'Cuenta guardada pendiente de activación por correo.':'Registro guardado correctamente.');mostrarImpacto(guardadoLocal);
        });
    }
    /** Enlaza consulta, modificación, estado y siguientes pasos de cada registro. */
    function enlazarFilas(){
        enlazarEvento('[data-patient-book]','click',e=>{estadoLocal.bookingDraft=null;estadoLocal.workflowContext={patient_id:Number(e.currentTarget.dataset.patientBook)};return navegar('CU09');});
        enlazarEvento('[data-patient-history]','click',e=>{estadoLocal.workflowContext={patient_id:Number(e.currentTarget.dataset.patientHistory)};return navegar('CU15');});
        enlazarEvento('[data-doctor-schedule]','click',e=>{estadoLocal.scheduleDoctor=e.currentTarget.dataset.doctorSchedule;return navegar('CU07');});
        enlazarEvento('[data-show]','click',async evento=>{
            const registro=await clienteApi(`entities/${entidad}/${evento.currentTarget.dataset.show}`);
            const campos = configuracion.headers.map((etiqueta,indice)=>[etiqueta,configuracion.row(registro)[indice]]);
            if (entidad === 'patients') campos.push(['Correo electrónico', escapar(registro.email || '—')], ['Dirección', escapar(registro.address || '—')]);
            modal('Consultar registro',detalles(campos)+contenidoImpacto(registro));
        });
        enlazarEvento('[data-create-account]','click',e=>{
            const id=Number(e.currentTarget.dataset.createAccount);
            const paciente=filas.find(r=>r.id===id);
            modal('Crear cuenta del paciente',`<p>${escapar(nombreCompleto(paciente))} · CI: ${escapar(paciente.identity||'Sin documento')}</p><form id="patient-account-form">${erroresFormulario}<div class="grid two">${camposCuenta(paciente)}</div>${botonesAccion(boton('Cancelar','data-dismiss','')+'<button type="submit" class="btn success">Crear cuenta y enviar código</button>')}</form>`);
            enlazarEvento('[data-dismiss]','click',cerrarModal);
            enlazarFormulario('patient-account-form',async(datos,formularioLocal)=>{
                const resultado=await clienteApi(`patients/${id}/account`,'POST',datos);
                formularioLocal.reset();cerrarModal();await cargarReferencias();await navegar(estadoLocal.view);notificacion(resultado.message);
            });
        });
        enlazarEvento('[data-edit]','click',e=>editar(filas.find(r=>r.id===Number(e.currentTarget.dataset.edit))));
        enlazarEvento('[data-toggle]','click',e=>{
            const r=filas.find(r=>r.id===Number(e.currentTarget.dataset.toggle));
            confirmarAccion('Cambiar estado',`¿Desea ${r.status==='Activo'?'desactivar':'activar'} este registro?`,async()=>{
                const datos={...r,status:r.status==='Activo'?'Inactivo':'Activo'};
                if(entidad==='doctors')datos.specialty_ids=r.specialties.map(s=>s.id);
                const guardadoLocal = await clienteApi(`entities/${entidad}/${r.id}/status`,'PATCH',{status:datos.status});
                await cargarReferencias();await navegar(estadoLocal.view);notificacion('Estado actualizado.');
                return ()=>mostrarImpacto(guardadoLocal);
            },r.status==='Activo');
        });
    }
    return {html:`<div class="toolbar">${campo('Buscar por código, nombre o identificador','search','search','','placeholder="Buscar registros…"')}${boton('+ '+configuracion.new,'id="new-entity"')}${entidad==='users'?boton('Portal opcional de pacientes','id="new-patient-account"'):''}</div>${panel(configuracion.title,`<div id="records">${contenidoFilas(filas)}</div>`)}`,bind:()=>{
        enlazarEvento('#new-entity','click',()=>editar());enlazarFilas();
        enlazarEvento('#new-patient-account','click',()=>navegar('CU06'));
        enlazarEvento('#search','input',e=>{const q=e.target.value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();$('#records').innerHTML=contenidoFilas(filas.filter(r=>JSON.stringify(r).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().includes(q)));enlazarFilas();});
    }};
}
vistas.CU03=()=>vistaGestion('users');vistas.CU04=()=>vistaGestion('doctors');vistas.CU05=()=>vistaGestion('specialties');vistas.CU06=()=>vistaGestion('patients');


}
