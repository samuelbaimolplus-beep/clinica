# Matriz de operaciones internas de los 26 CU — V6

Complementa el [informe de entrega](REVISION_FUNCIONAL_Y_SUPERADMIN_2026-11-07.md). Los sufijos `.1`, `.2`, etc. sirven para rastrear funciones dentro del CU; no cambian la numeración del Word.

**I:** implementado en el código revisado. **I/E:** implementado, con verificación externa pendiente. **O:** operación externa de la clínica prevista por el documento. Las suites citadas comprueban escenarios concretos; no equivalen a una certificación exhaustiva de cada rama posible. La prueba de navegador usa API simulada; Feature utiliza PostgreSQL aislado y simula únicamente servicios externos cuando corresponde.

## CU01 — Iniciar sesión

Entrada: formulario público de acceso. Código: `AuthController`, `ClinicSession`, `SessionSecurity`, `ClinicAccess`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU01.1 | Introducir identificador y contraseña; mostrar/ocultar contraseña a petición del usuario | I |
| CU01.2 | Validar requeridos y credenciales sin revelar cuál credencial falló | I |
| CU01.3 | Rechazar cuenta/perfil inactivo y exigir correo verificado al Paciente | I |
| CU01.4 | Regenerar sesión y CSRF, asignar permisos del servidor y abrir panel del rol | I |
| CU01.5 | Impedir segundo login sobre una sesión ya autenticada | I |
| CU01.6 | Revocar sesiones tras cambios de credenciales/permisos; no dejar sesión autenticada si falla su creación | I |

Evidencia: `ClinicTest`, `EstablishedUseCasesTest`, `AccountSecurityTest`, `SuperadminAccessTest`, pruebas Node del cliente y navegador por rol.

## CU02 — Cerrar sesión

Entrada: botón Salir y Mi sesión. Código: `AuthController`, middleware de sesión.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU02.1 | Solicitar cierre de sesión y confirmar la intención | I |
| CU02.2 | Invalidar sesión, regenerar CSRF y volver al acceso | I |
| CU02.3 | Denegar consultas y escrituras protegidas después del cierre o expiración | I |
| CU02.4 | No confirmar cierre si ocurre un error y la sesión continúa activa | I |

Evidencia: `ClinicTest`, `EstablishedUseCasesTest`, `api.test.mjs`; cierre local real del Superadmin.

## CU03 — Gestionar usuarios y roles

Entrada: Administración → Usuarios y roles. Código: controladores de catálogo, `SaveUserRequest`, `CatalogService`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU03.1 | Registrar personal con contraseña cifrada mediante hash y rol permitido | I |
| CU03.2 | Consultar listado/detalle y buscar registros | I |
| CU03.3 | Modificar nombre, identificador, rol y contraseña opcional | I |
| CU03.4 | Vincular una cuenta Médico con un profesional habilitado, sin duplicar la vinculación | I |
| CU03.5 | Activar y desactivar con confirmación, sin borrar datos | I |
| CU03.6 | Rechazar identificadores duplicados, datos inválidos y roles no permitidos | I |
| CU03.7 | Impedir desactivación propia, cambio propio de rol y pérdida del último Administrador activo | I |
| CU03.8 | Gestionar Superadmin exclusivamente desde Superadmin, conservando acceso propio y revocando sesiones al cambiar permisos | I — ampliación autorizada |

Evidencia: `ClinicTest`, `EstablishedUseCasesTest`, `AccountSecurityTest`, `SuperadminAccessTest`. No se agregan cuentas Paciente mediante este catálogo.

## CU04 — Gestionar médicos

Entrada: Administración → Médicos. Código: `DoctorController`, `SaveDoctorRequest`, `CatalogService`, `CatalogImpact`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU04.1 | Registrar identificación profesional y contacto; generar código y estado activo inicial | I |
| CU04.2 | Buscar por nombre/especialidad y consultar detalle | I |
| CU04.3 | Modificar información y asignar una o varias especialidades activas | I |
| CU04.4 | Activar/desactivar sin eliminar historial | I |
| CU04.5 | Impedir duplicación de registro profesional y asociaciones inexistentes/inactivas | I |
| CU04.6 | Identificar citas futuras afectadas por desactivación o retiro de especialidad sin cancelarlas | I |

Evidencia: `EstablishedUseCasesTest`, `ClinicTest`, `V3WorkflowTest`.

## CU05 — Gestionar especialidades

Entrada: Administración → Especialidades y tarifas. Código: `SpecialtyController`, `SaveSpecialtyRequest`, `SpecialtyName`, `CatalogService`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU05.1 | Registrar nombre, descripción opcional y estado activo | I |
| CU05.2 | Buscar y consultar código, descripción y estado | I |
| CU05.3 | Modificar datos y administrar la tarifa utilizada por CU19 | I |
| CU05.4 | Normalizar mayúsculas y espacios para impedir duplicados en aplicación y PostgreSQL | I |
| CU05.5 | Activar/desactivar conservando relaciones históricas | I |
| CU05.6 | Identificar citas afectadas por desactivación sin modificarlas automáticamente | I |

Evidencia: `EstablishedUseCasesTest`, `ClinicTest`, `ClinicalPaymentsTest`. La tarifa responde a RN39 y no constituye un nuevo CU.

## CU06 — Gestionar pacientes

Entrada: Recepción → Pacientes. Código: `PatientController`, `SavePatientRequest`, `CatalogService`, `PatientAccountInvitationController`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU06.1 | Registrar datos personales/contacto y generar código activo | I |
| CU06.2 | Buscar por CI, nombres o apellidos y consultar detalle | I |
| CU06.3 | Modificar datos autorizados | I |
| CU06.4 | Activar/desactivar conservando citas e historial | I |
| CU06.5 | Rechazar CI duplicado y coincidencias exactas de pacientes sin CI | I |
| CU06.6 | Verificar identidad y generar código temporal para vincular una cuenta mediante CU23, sin duplicar paciente | I |
| CU06.7 | Impedir nuevas reservas y revocar acceso del perfil de paciente inactivo | I |

Evidencia: `FoundationModulesTest`, `V3WorkflowTest`, `ClinicTest` y seguridad de cuentas.

## CU07 — Gestionar horarios médicos

Entrada: Administración → Horarios médicos. Código: `ScheduleController`, FormRequests de horarios y servicios `Schedules`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU07.1 | Consultar turnos por médico y registrar varios turnos por día | I |
| CU07.2 | Modificar día, inicio, fin y duración, sin desplazar citas | I |
| CU07.3 | Validar rangos/duración e impedir turnos superpuestos | I |
| CU07.4 | Registrar indisponibilidades actuales/futuras y previsualizar citas afectadas | I |
| CU07.5 | Proteger los primeros 120 minutos de cada turno, incluso turnos cortos | I |
| CU07.6 | Configurar porcentajes enteros por médico, suma 100 y valor inicial 50/50 | I |
| CU07.7 | Distribuir por mayor resto, desempate presencial y alternancia, independientemente por turno | I |
| CU07.8 | Dejar sobrantes de duración sin crear consultas más cortas | I |
| CU07.9 | Preservar citas, habilitaciones y retenciones durante cambios concurrentes e identificar afectaciones | I |

Evidencia: `DoctorQuotaTest`, `QuotaAllocatorTest`, `V3WorkflowTest`, `V6RulesTest`, `ClinicalPaymentsTest`.

## CU08 — Consultar disponibilidad médica

Entrada: Disponibilidad médica, CU09, CU18 y CU21. Código: `MedicalAvailabilityController`, `DoctorQuotaService`, selección en `AppointmentService`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU08.1 | Consultar especialidad, fecha y preferencia de médico | I |
| CU08.2 | Mostrar presencial a Recepción y online al Paciente; Superadmin puede consultar ambas | I |
| CU08.3 | Excluir indisponibilidades, citas, retenciones y bloqueos incompatibles | I |
| CU08.4 | Respetar protección inicial y habilitaciones anticipadas | I |
| CU08.5 | Ordenar profesionales según carga y disponibilidad cuando no hay preferencia | I |
| CU08.6 | Informar ausencia de profesionales, turnos o cupos y permitir cambiar criterios | I |
| CU08.7 | Consultar sin crear retención, cita, ficha o pago | I |

Evidencia: `V6RulesTest`, `V3WorkflowTest`, `DoctorQuotaTest`, `ClinicalPaymentsTest`, `SuperadminAccessTest`; pantalla de navegador.

## CU09 — Programar cita médica presencial

Entrada: Recepción → Programar cita presencial. Código: `AppointmentController`, `AppointmentService`, `PaymentVerifier`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU09.1 | Seleccionar paciente activo, especialidad, médico habilitado y franja presencial | I |
| CU09.2 | Revalidar franja, modalidad, fecha y superposición de paciente/médico al confirmar | I |
| CU09.3 | Verificar pago íntegro o exención válida antes de formalizar | I |
| CU09.4 | Crear cita Programada, conservar referencia de pago y emitir/imprimir ficha | I |
| CU09.5 | Consumir bloqueo compatible de la misma especialidad dentro de la transacción | I |
| CU09.6 | Rechazar duplicados mediante clave de operación y revertir cambios parciales ante error | I |

Evidencia: `ClinicTest`, `ClinicalPaymentsTest`, identidad de franja en `V3WorkflowTest`.

## CU10 — Reprogramar cita médica

Entrada: Reprogramaciones y resolución desde CU17. Código: `ClinicalWorkflowController`, `AppointmentController`, `AppointmentService`, bandeja/aviso.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU10.1 | Paciente titular solicita cambio de una cita vigente sin alterarla | I |
| CU10.2 | Impedir solicitudes pendientes duplicadas y avisar a Recepción | I |
| CU10.3 | Recepción consulta, procesa o rechaza con respuesta registrada | I |
| CU10.4 | Coordinar fecha/hora o reasignación con aceptación del paciente por WhatsApp | O — coordinación externa |
| CU10.5 | Revalidar disponibilidad y médico de la misma especialidad; registrar motivo, causa y aceptación | I |
| CU10.6 | Conservar pago original o cobrar si se pierde exención; preservar gratuidad si la causa es clínica | I |
| CU10.7 | Registrar programación anterior/nueva y responsable, liberar franja anterior y resolver solicitud | I |
| CU10.8 | Rechazar estados finales, horarios ocupados/pasados y cambios no guardados; comunicar resultado por WhatsApp | I para controles; O para comunicación |

Evidencia: `ClinicTest`, `ClinicalPaymentsTest`, `SuperadminAccessTest` y prueba de navegador del aviso.

## CU11 — Cancelar cita médica

Entrada: Recepción → Cancelar cita y CU17. Código: transición `cancel` en `AppointmentService`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU11.1 | Buscar cita vigente y consultar datos | I |
| CU11.2 | Confirmar causa Paciente/Clínica y motivo obligatorio | I |
| CU11.3 | Cambiar a Cancelada y liberar la franja conservando historia/pago | I |
| CU11.4 | Cerrar solicitudes pendientes relacionadas y conservar trazabilidad | I |
| CU11.5 | Impedir cancelación desde el portal y cambios de citas ya finalizadas | I |
| CU11.6 | Tramitar reembolso por CU20, sin asumir que cancelar ya devuelve dinero | I |

Evidencia: `ClinicTest`, `ClinicalPaymentsTest`, controles de titularidad y roles.

## CU12 — Consultar agenda médica

Entrada: Agenda médica. Código: `AppointmentController::index` y pantalla clínica.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU12.1 | Recepción consulta agendas por médico y fecha | I |
| CU12.2 | Médico consulta exclusivamente su agenda y se rechaza el identificador de otro profesional | I |
| CU12.3 | Ordenar citas y mostrar paciente, especialidad, horario y estado | I |
| CU12.4 | Consultar detalle y cambiar filtros sin modificar citas | I |
| CU12.5 | Mostrar resultados vacíos y atender errores de consulta | I |

Evidencia: `ClinicTest`, `SuperadminAccessTest`, navegador de roles y navegación local real.

## CU13 — Registrar asistencia e inasistencia

Entrada: Recepción → Asistencia e inasistencia. Código: transiciones de `AppointmentService`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU13.1 | Consultar citas del día y registrar hora de presencia | I |
| CU13.2 | Confirmar presencia sin registrar automáticamente Atendida | I |
| CU13.3 | Verificar cobro exigible antes de registrar presencia en seguimiento tardío o pago no acreditado | I |
| CU13.4 | Registrar ausente al llamado desde la agenda del Médico, sin declarar inasistencia definitiva | I |
| CU13.5 | Registrar inasistencia definitiva con determinación expresa y motivo | I |
| CU13.6 | Impedir contradicciones, duplicación y cambios sobre citas canceladas/atendidas | I |

Evidencia: `ClinicTest`, `ClinicalPaymentsTest`. No se agrega una tolerancia fija de minutos.

## CU14 — Registrar cita como atendida

Entrada: Médico → Finalizar consulta; Superadmin consulta sin finalizar. Código: transición `attend`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU14.1 | Presentar consultas propias de hoy con prioridad de puntuales y luego llegada de retrasados | I |
| CU14.2 | Exigir presencia, estado válido, pago/exención y confirmación de consulta terminada | I |
| CU14.3 | Registrar Atendida, responsable y momento de finalización | I |
| CU14.4 | Rechazar otra agenda, estados finales y repetición de atención | I |
| CU14.5 | Registrar opcionalmente recomendación de seguimiento con fecha/hora e indicación, sin reservar aún | I |

Evidencia: `ClinicTest`, `ClinicalPaymentsTest`, prohibición de suplantación en `SuperadminAccessTest`.

## CU15 — Consultar historial de citas

Entrada: Historial de citas. Código: `AppointmentController::index`, detalle clínico y eventos.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU15.1 | Administrador/Recepción consultan historial autorizado; Paciente solo el propio | I |
| CU15.2 | Filtrar por texto, fecha, médico y estado según permisos | I |
| CU15.3 | Buscar también pacientes sin documento de identidad | I |
| CU15.4 | Mostrar estado, registro, motivo, cancelación, asistencia y antecedentes de cambios | I |
| CU15.5 | Conservar historia pese a cancelaciones/desactivaciones y consultar sin mutar datos | I |

Evidencia: `ClinicTest`, `EstablishedUseCasesTest`, `SuperadminAccessTest`, `ClinicalPaymentsTest`.

## CU16 — Generar reportes administrativos

Entrada: Reportes administrativos. Código: `ReportController`, generador en `app.js`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU16.1 | REP01: citas por fecha específica o período | I |
| CU16.2 | REP02: citas por médico; REP03: citas por especialidad | I |
| CU16.3 | REP04: citas por estado; REP05: pacientes registrados | I |
| CU16.4 | REP06: resumen de atendidas, canceladas e inasistencias por período, totales y porcentajes | I |
| CU16.5 | REP07: agenda diaria por médico ordenada | I |
| CU16.6 | Validar fechas/filtros y mostrar resultados vacíos sin datos inventados | I |
| CU16.7 | Imprimir y exportar CSV, neutralizando fórmulas en la exportación | I |

Evidencia: `ClinicTest` recorre siete reportes; `SuperadminAccessTest` comprueba REP01 por período.

## CU17 — Consultar citas afectadas

Entrada: Recepción → Citas afectadas. Código: `ScheduleImpactController`, `ScheduleImpact`, `CatalogImpact`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU17.1 | Detectar indisponibilidad total/parcial y cambio incompatible de horario | I |
| CU17.2 | Detectar médico desactivado, especialidad desactivada y pérdida de habilitación médico/especialidad | I |
| CU17.3 | Unificar causas y mostrar origen, cita, contacto y detalle autorizado | I |
| CU17.4 | Ofrecer CU10 para reprogramar/reasignar y CU11 para cancelar | I |
| CU17.5 | Excluir citas resueltas/finalizadas y recalcular sin alterar las existentes | I |

Evidencia: `EstablishedUseCasesTest`, `V3WorkflowTest`, `ClinicTest`; apertura local real.

## CU18 — Reservar cita online

Entrada: inicio del Paciente y acceso desde CU08. Superadmin supervisa. Código: `ClinicalWorkflowController`, `OnlineBookingService`, pantalla clínica.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU18.1 | Consultar especialidad, preferencia médica y fecha; seleccionar solo franjas online habilitadas | I |
| CU18.2 | Revalidar disponibilidad y titularidad al confirmar; retener por intento sin generar cita definitiva | I |
| CU18.3 | Iniciar QR sandbox y mostrar operación, importe y estados verificables | I/E — transacción externa pendiente |
| CU18.4 | Consultar estado y reanudar operaciones propias; hasta dos intentos de cinco minutos y diez acumulados | I/E — respuesta real del proveedor pendiente |
| CU18.5 | Formalizar una sola cita Programada y ficha tras confirmación suficiente | I/E — automatizado con proveedor simulado |
| CU18.6 | Descartar solicitud sin pago solo después de comprobar que no hay pago confirmado/pendiente/desconocido; no cancelar una cita ya pagada desde el portal | I/E — automatizado con proveedor simulado |
| CU18.7 | Al agotar intentos, liberar solo si es seguro; mantener retención si hay transacción pendiente o desconocida | I/E — automatizado con proveedor simulado |
| CU18.8 | Impedir acceso a operaciones ajenas, reservas superpuestas y cobros repetidos por reenvío | I |
| CU18.9 | Mostrar indisponibilidad de servicio sin crear pagos ni fichas ficticios | I |

Evidencia: `ClinicalPaymentsTest`, navegador con consulta/retención/ficha/descarte y comprobación de servicio ausente. La navegación local del Superadmin revisó la supervisión sin realizar un cobro.

## CU19 — Verificar pago o exención

Entrada: incluida en CU09/CU18/CU21; supervisión de Superadmin. Código: `PaymentVerifier`, `WizPayGateway`, webhook, job y conciliador.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU19.1 | Verificar efectivo confirmado por Recepción | I |
| CU19.2 | Verificar QR/transferencia presencial, importe exacto y referencia bancaria única | I |
| CU19.3 | Consultar WizPay desde el servidor; validar referencia, orden, moneda e importe | I/E |
| CU19.4 | Autenticar webhook sandbox y conciliar sin confiar en el navegador | I/E |
| CU19.5 | Controlar estados, reintentos, respuestas perdidas, notificaciones repetidas y confirmación concurrente | I/E |
| CU19.6 | Verificar exención vinculada a consulta Atendida y condiciones de gratuidad | I |
| CU19.7 | Entregar resultado al coordinador de cita; no emitir ficha de manera independiente | I |
| CU19.8 | Consultar pagos y exenciones globalmente, sin confirmar online manualmente | I — supervisión autorizada |

Evidencia: `ClinicalPaymentsTest`, `SuperadminAccessTest`. El proveedor externo permanece simulado en las pruebas; no se acredita un cobro sandbox real.

## CU20 — Solicitar reembolso

Entrada: Solicitar reembolso para Paciente/Recepción. Código: `RefundService::request`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU20.1 | Seleccionar cita previamente cancelada y con pago confirmado | I |
| CU20.2 | Comprobar permisos/titularidad y ausencia de solicitud o devolución duplicada | I |
| CU20.3 | Evaluar anticipación de 24 horas, gracia de 15 minutos y causa atribuible a la clínica | I |
| CU20.4 | Registrar improcedencia con motivo o solicitud admitida Pendiente para CU26 | I |
| CU20.5 | No cancelar la cita, aprobar la devolución ni entregar dinero al solicitar | I |

Evidencia: `ClinicalPaymentsTest`, `SuperadminAccessTest`.

## CU21 — Registrar cita de seguimiento

Entrada: Recepción → Registrar seguimiento. Código: selección de consulta original y `AppointmentService::book`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU21.1 | Seleccionar consulta Atendida con recomendación y recuperar fecha/hora sugeridas | I |
| CU21.2 | Validar mismo paciente/especialidad y comprobar disponibilidad | I |
| CU21.3 | Crear nueva cita vinculada a la original, no modificar la consulta ya realizada | I |
| CU21.4 | Validar exención en horario recomendado o alternativa por causa de la clínica con motivo/acuerdo registrado | I |
| CU21.5 | Exigir pago para cambio voluntario; cobrar ante llegada tardía/pérdida del turno | I |
| CU21.6 | Conservar gratuidad al reprogramar por causa de la clínica; no imponer límite fijo de seguimientos gratuitos | I |
| CU21.7 | Emitir ficha únicamente después de pago o exención válidos | I |

Evidencia: `ClinicalPaymentsTest`, incluidos escenarios nuevos de alternativa inicial causada por clínica y alternativa voluntaria.

## CU22 — Consultar bitácora de auditoría

Entrada: Administración → Bitácora de auditoría. Código: `AuditController`, observer/logger, restricción PostgreSQL de inmutabilidad.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU22.1 | Registrar automáticamente autenticación y cambios relevantes en módulos | I |
| CU22.2 | Consultar eventos paginados por fechas, responsable, módulo, acción y resultado | I |
| CU22.3 | Inspeccionar detalle permitido, registro afectado y resultado | I |
| CU22.4 | Mostrar resultados vacíos sin alterar información | I |
| CU22.5 | Impedir modificación/borrado de auditoría y excluir contraseñas, tokens y secretos | I |
| CU22.6 | Revertir auditoría junto con operación fallida cuando corresponde | I |

Evidencia: `FoundationModulesTest`, `EstablishedUseCasesTest`, `V6RulesTest`, apertura local real.

## CU23 — Registrar cuenta de paciente

Entrada: acceso público → Crear cuenta de paciente; supervisión global desde Superadmin.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU23.1 | Registrar datos y contraseña fuerte con confirmación; impedir asignación de roles privilegiados | I |
| CU23.2 | Exigir aceptación de términos y guardar fecha/versión; deshabilitar creación si no se aceptan | I |
| CU23.3 | Crear paciente/cuenta atómicamente o vincular registro previo mediante identidad verificada | I |
| CU23.4 | Rechazar duplicados, código inválido/expirado/revocado y vinculación de historia no autorizada | I |
| CU23.5 | Volver a login sin autenticación automática y enviar enlace firmado de verificación | I/E — entrega real de correo pendiente |
| CU23.6 | Recuperar acceso por enlace temporal de un uso, con token cifrado mediante hash y revocación de sesiones | I/E — entrega real de correo pendiente |
| CU23.7 | Superadmin consulta estado, vinculación, verificación y términos sin crear una cuenta personal suplantando al paciente | I — supervisión autorizada |

Evidencia: `FoundationModulesTest`, `AccountSecurityTest`, `SuperadminAccessTest`, navegador de términos y contraseña. La validación del enlace está probada. Tras configurar SMTP se comprobó autenticación correcta con Gmail, sin enviar un mensaje; la entrega externa de los enlaces permanece pendiente de verificación.

## CU24 — Gestionar bloqueos provisionales por demanda presencial

Entrada: Recepción → Bloqueos provisionales. Código: `AgendaAdjustmentController`, `AgendaAdjustments`.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU24.1 | Ingresar especialidad y conteo/estimación de pacientes presentes hoy | I |
| CU24.2 | Proponer franjas próximas y advertir cantidad sin disponibilidad | I |
| CU24.3 | Seleccionar y confirmar franjas; revalidar concurrencia sin desplazar citas/retenciones | I |
| CU24.4 | Consultar bloqueos activos, liberados y consumidos con responsable | I |
| CU24.5 | Seleccionar uno o varios bloqueos activos y liberar manualmente con confirmación | I |
| CU24.6 | Impedir liberar consumidos y mantenerlos sin caducidad automática | I |
| CU24.7 | Consumir bloqueo de especialidad compatible únicamente al formalizar CU09 tras pago/exención | I |
| CU24.8 | Rechazar fechas futuras y usuarios no autorizados; auditar operaciones/intentos | I |

Evidencia: `V6RulesTest`, `V3WorkflowTest`, `ClinicalPaymentsTest`. Se sigue la regla explícita de no introducir temporizadores de advertencia/caducidad.

## CU25 — Habilitar anticipadamente cupos online

Entrada: Recepción → Habilitar cupos online. Código: `AgendaAdjustments`, disponibilidad y pantalla operativa.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU25.1 | Consultar médico, turno y franjas protegidas libres de hoy | I |
| CU25.2 | Seleccionar una o varias franjas de los primeros 120 minutos | I |
| CU25.3 | Confirmar y revalidar ausencia de citas, retenciones y bloqueos; registrar responsable | I |
| CU25.4 | Publicar las franjas habilitadas en CU08/CU18 sin cambiar porcentajes generales | I |
| CU25.5 | Rechazar franja fuera del período, ocupación concurrente y falta de permisos | I |
| CU25.6 | Impedir retirada/reversión de la habilitación en interfaz y servidor | I |

Evidencia: `V3WorkflowTest`, `V6RulesTest`, `DoctorQuotaTest`, apertura de vista en navegador.

## CU26 — Resolver solicitud de reembolso

Entrada: Administración → Resolver reembolsos. Código: `RefundService::decide/complete` y pantalla administrativa.

| Referencia | Operación/regla | Estado |
| --- | --- | --- |
| CU26.1 | Consultar solicitudes y distinguir admitidas de improcedentes | I |
| CU26.2 | Aprobar o rechazar exclusivamente solicitudes admitidas pendientes; registrar responsable/fecha y motivo al rechazar | I |
| CU26.3 | Impedir que el solicitante resuelva su propia solicitud, también para Superadmin | I |
| CU26.4 | Mantener devolución pendiente después de aprobar, sin marcarla como entregada | I |
| CU26.5 | Registrar ejecución externa declarada: ejecutor, fecha, importe completo, medio y referencia cuando existe | I para registro; O para entrega externa |
| CU26.6 | Rechazar importe incorrecto, ejecución anterior a autorización y devolución repetida | I |

Evidencia: `ClinicalPaymentsTest`, `SuperadminAccessTest`. No se presenta como implementada una devolución bancaria automática ni se afirma que la aplicación compruebe la entrega física.
