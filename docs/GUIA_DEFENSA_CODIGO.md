# Guía de defensa del código

La [revisión de recorridos y acceso del 8 de noviembre](REVISION_RECORRIDOS_Y_ACCESO_2026-11-08.md) describe los cambios funcionales posteriores solicitados: ficha clínica independiente de la cuenta, portal opcional para adultos con consentimiento, validación de edad y recorridos conectados. La estructura física P01–P08 de esta guía se conserva. `AccountEligibility` pertenece a los servicios compartidos de P01; el resumen de jornada `WorkspaceController` comparte las consultas de P05.

Referencia: `../ofical.docx`, secciones 5.1.1 y 5.1.2 (identificación de paquetes y asignación de CU). El documento permanece intacto. Esta guía describe la ubicación física del código y su relación con los 26 casos de uso.

## Punto de entrada para la explicación

1. Abra `app/Paquetes`: los ocho directorios corresponden a P01–P08 del documento.
2. Elija el paquete y localice el CU o el componente compartido indicado en la matriz de esta guía.
3. Siga la ruta HTTP en `routes/web.php`, el controlador en `Http`, la validación en `Validacion` y la operación de negocio en `Servicios`.
4. Revise la entidad en `app/Models` y las restricciones físicas en `database/migrations`.
5. Muestre las pruebas existentes de `tests/Feature`, `tests/Unit` y `tests/Browser` para comprobar el proceso.

La organización es un monolito modular Laravel. Los paquetes no son aplicaciones separadas ni bases distintas. No se crearon controladores de reenvío para completar una carpeta por CU: la consulta de agenda e historial y las transiciones de citas comparten implementación.

## Paquetes del documento

| Paquete | Responsabilidad | CU principales | Carpeta física |
|---|---|---|---|
| P01 | Gestión de usuarios y acceso | CU01, CU02, CU03, CU23 | `app/Paquetes/P01_UsuariosYAcceso` |
| P02 | Gestión de personal médico y especialidades | CU04, CU05 | `app/Paquetes/P02_PersonalMedicoYEspecialidades` |
| P03 | Gestión de pacientes | CU06 | `app/Paquetes/P03_Pacientes` |
| P04 | Gestión de horarios y disponibilidad médica | CU07, CU08, CU24, CU25 | `app/Paquetes/P04_HorariosYDisponibilidad` |
| P05 | Gestión y seguimiento de citas médicas | CU09, CU10, CU11, CU12, CU13, CU14, CU17 | `app/Paquetes/P05_CitasMedicas` |
| P06 | Historial y reportes administrativos | CU15, CU16 | `app/Paquetes/P06_HistorialYReportes` |
| P07 | Reservas online, pagos, reembolsos y seguimientos | CU18, CU19, CU20, CU21, CU26 | `app/Paquetes/P07_ReservasPagosYSeguimientos` |
| P08 | Auditoría del sistema | CU22 | `app/Paquetes/P08_Auditoria` |

La asignación conserva exactamente 4 + 2 + 1 + 4 + 7 + 2 + 5 + 1 = 26 CU. El paquete principal es una responsabilidad funcional; cuando varias operaciones usan la misma consulta o transacción, el archivo compartido se referencia desde la matriz y no se duplica.

## Qué significa cada carpeta

| Carpeta | Responsabilidad |
|---|---|
| `app/Paquetes/Pxx.../CUxx.../Http` | Entrada HTTP de una responsabilidad concreta. Recibe solicitudes y devuelve las respuestas originales. |
| `Validacion` | Form Requests: permisos, normalización y reglas de entrada. |
| `Servicios` | Reglas y coordinación de operaciones. Las transacciones siguen conservando su alcance original. |
| `app/Paquetes/Pxx.../Compartido` | Consultas, servicios o integración usados por varios CU de ese paquete. |
| `app/Compartido/Catalogos` | Mantenimiento, cambios de estado, validación de personas e impacto usados por varios paquetes. |
| `app/Models` | Entidades Eloquent y relaciones compartidas. Se conserva la ubicación convencional de Laravel. |
| `app/Http/Middleware`, `app/Providers`, `app/Support` | Sesión, permisos, registro de servicios y utilidades transversales. |
| `app/Database`, `database/migrations` | Adaptador del esquema en español, tablas, restricciones, triggers y procedimientos. |
| `app/Jobs`, `app/Notifications`, `app/Console` | Integración con cola, correo y comandos de Laravel. Se conservan las ubicaciones convencionales. |
| `resources` | Interfaz. Su estructura no se reorganiza por esta solicitud. |

## Matriz para localizar los 26 CU

| CU / paquete | Caso de uso | Archivo principal | Métodos o flujo |
|---|---|---|---|
| CU01 / P01 | Iniciar sesión | [AuthController.php](../app/Paquetes/P01_UsuariosYAcceso/CU01_IniciarSesion/Http/AuthController.php) | login / session |
| CU02 / P01 | Cerrar sesión | [LogoutController.php](../app/Paquetes/P01_UsuariosYAcceso/CU02_CerrarSesion/Http/LogoutController.php) | logout |
| CU03 / P01 | Gestionar usuarios y roles | [UserController.php](../app/Paquetes/P01_UsuariosYAcceso/CU03_GestionarUsuariosYRoles/Http/UserController.php) | index / show / save |
| CU04 / P02 | Gestionar médicos | [DoctorController.php](../app/Paquetes/P02_PersonalMedicoYEspecialidades/CU04_GestionarMedicos/Http/DoctorController.php) | index / show / save |
| CU05 / P02 | Gestionar especialidades | [SpecialtyController.php](../app/Paquetes/P02_PersonalMedicoYEspecialidades/CU05_GestionarEspecialidades/Http/SpecialtyController.php) | index / show / save |
| CU06 / P03 | Gestionar pacientes | [PatientController.php](../app/Paquetes/P03_Pacientes/CU06_GestionarPacientes/Http/PatientController.php) | index / show / save |
| CU07 / P04 | Gestionar horarios médicos | [ScheduleController.php](../app/Paquetes/P04_HorariosYDisponibilidad/CU07_GestionarHorariosMedicos/Http/ScheduleController.php) | index / save / unavailable / quota / capacity |
| CU08 / P04 | Consultar disponibilidad médica | [MedicalAvailabilityController.php](../app/Paquetes/P04_HorariosYDisponibilidad/CU08_ConsultarDisponibilidadMedica/Http/MedicalAvailabilityController.php) | options / index |
| CU09 / P05 | Programar cita médica | [AppointmentController.php](../app/Paquetes/P05_CitasMedicas/CU09_ProgramarCitaMedica/Http/AppointmentController.php) | store |
| CU10 / P05 | Reprogramar cita médica | [ReprogrammingController.php](../app/Paquetes/P05_CitasMedicas/CU10_ReprogramarCitaMedica/Http/ReprogrammingController.php) | reschedule / requestReschedule / rejectReschedule |
| CU11 / P05 | Cancelar cita médica | [AppointmentActionController.php](../app/Paquetes/P05_CitasMedicas/Compartido/Http/AppointmentActionController.php) | action → transition(cancel) |
| CU12 / P05 | Consultar agenda médica | [AppointmentQueryController.php](../app/Paquetes/P05_CitasMedicas/Compartido/Http/AppointmentQueryController.php) | index, mode=agenda |
| CU13 / P05 | Registrar asistencia e inasistencia | [AppointmentActionController.php](../app/Paquetes/P05_CitasMedicas/Compartido/Http/AppointmentActionController.php) | action → transition(attendance / absent) |
| CU14 / P05 | Registrar cita como atendida | [AppointmentActionController.php](../app/Paquetes/P05_CitasMedicas/Compartido/Http/AppointmentActionController.php) | action → transition(attend / missed-call) |
| CU15 / P06 | Consultar historial de citas | [AppointmentQueryController.php](../app/Paquetes/P05_CitasMedicas/Compartido/Http/AppointmentQueryController.php) | index, mode=history |
| CU16 / P06 | Generar reportes administrativos | [ReportController.php](../app/Paquetes/P06_HistorialYReportes/CU16_GenerarReportesAdministrativos/Http/ReportController.php) | index |
| CU17 / P05 | Consultar citas afectadas | [ScheduleImpactController.php](../app/Paquetes/P05_CitasMedicas/CU17_ConsultarCitasAfectadas/Http/ScheduleImpactController.php) | __invoke |
| CU18 / P07 | Reservar cita online | [OnlineBookingController.php](../app/Paquetes/P07_ReservasPagosYSeguimientos/CU18_ReservarCitaOnline/Http/OnlineBookingController.php) | onlineStart / onlineStatus / onlineRetry / onlineCancel |
| CU19 / P07 | Verificar pago o exención de cita | [PaymentVerifier.php](../app/Paquetes/P07_ReservasPagosYSeguimientos/CU19_VerificarPagoOExencion/Servicios/PaymentVerifier.php) | tariff / reception |
| CU20 / P07 | Solicitar reembolso | [RefundRequestController.php](../app/Paquetes/P07_ReservasPagosYSeguimientos/CU20_SolicitarReembolso/Http/RefundRequestController.php) | requestRefund / refunds |
| CU21 / P07 | Registrar cita de seguimiento | [FollowupPolicy.php](../app/Paquetes/P07_ReservasPagosYSeguimientos/CU21_RegistrarCitaDeSeguimiento/Servicios/FollowupPolicy.php) | evaluate |
| CU22 / P08 | Consultar bitácora de auditoría | [AuditController.php](../app/Paquetes/P08_Auditoria/CU22_ConsultarBitacoraDeAuditoria/Http/AuditController.php) | filters / index / show |
| CU23 / P01 | Registrar cuenta de paciente | [ManagedPatientAccountController.php](../app/Paquetes/P01_UsuariosYAcceso/CU23_RegistrarCuentaDePaciente/Http/ManagedPatientAccountController.php) | register / store |
| CU24 / P04 | Gestionar bloqueos provisionales por demanda presencial | [AgendaAdjustmentController.php](../app/Paquetes/P04_HorariosYDisponibilidad/CU24_GestionarBloqueosProvisionales/Http/AgendaAdjustmentController.php) | propose / store / release / releaseSelected |
| CU25 / P04 | Habilitar anticipadamente cupos online | [ProtectedOnlineSlotsController.php](../app/Paquetes/P04_HorariosYDisponibilidad/CU25_HabilitarCuposOnline/Http/ProtectedOnlineSlotsController.php) | protectedBlocks |
| CU26 / P07 | Resolver solicitud de reembolso | [RefundResolutionController.php](../app/Paquetes/P07_ReservasPagosYSeguimientos/CU26_ResolverSolicitudDeReembolso/Http/RefundResolutionController.php) | decideRefund / completeRefund |

La matriz descargable `MAPA_CU_PAQUETES.csv` incluye además los endpoints HTTP. Las rutas, métodos HTTP, middleware, límites de intentos y permisos se conservaron durante la reorganización.

## Recorridos para la defensa

### Horarios y disponibilidad — P04

CU07 entra por `ScheduleController`, valida con `SaveScheduleRequest` y guarda mediante `ScheduleService`. `DoctorQuotaService` y `QuotaAllocator` calculan los cupos; `ScheduleBlocks` y `ShiftModalities`, en `Compartido`, producen las franjas que también consumen otros CU. CU08 consulta mediante `MedicalAvailabilityController`. CU24 propone, crea y libera bloqueos; CU25 consulta las franjas protegidas. La escritura de los ajustes de CU24/CU25 conserva el endpoint y la validación común de `AgendaAdjustmentController::store`.

### Programación, reprogramación y seguimiento — P05/P07

CU09 usa `AppointmentController::store` y CU10 usa `ReprogrammingController::reschedule`. Ambos coordinan la transacción mediante `P05/Compartido/Servicios/AppointmentService`. Para CU21, ese coordinador consulta `P07/CU21/Servicios/FollowupPolicy` dentro de la misma transacción: valida la consulta original y la exención. CU19 verifica el pago con `PaymentVerifier`; no emite una ficha por sí solo. La cita se formaliza cuando todas las verificaciones terminan correctamente.

### Agenda, historial, asistencia y atención — código compartido

`AppointmentQueryController::index` atiende la consulta de agenda de CU12 y la consulta histórica de CU15 mediante el parámetro `mode`. Conserva los filtros de privacidad por rol. `AppointmentActionController::action` valida la operación de CU11, CU13 o CU14 y llama a `AppointmentService::transition`. Se comparten estas validaciones para evitar que el mismo permiso o una restricción diverja entre tres copias del código.

### Reservas, pagos y devoluciones — P07

CU18 utiliza `OnlineBookingController` y `OnlineBookingService`. La integración con PayPal permanece en `P07/Compartido/Integraciones`; el job de conciliación permanece en `app/Jobs`. CU20 tiene su entrada de solicitud en `RefundRequestController`; CU26 decide y registra la devolución en `RefundResolutionController`. Ambos consumen `RefundService` compartido, manteniendo separadas solicitud, aprobación y ejecución.

### Acceso y cuentas — P01

CU01 y CU02 controlan el inicio y cierre de sesión. CU03 administra el personal. CU23 crea la cuenta del paciente mediante el alta asistida ya implementada. Activación, recuperación, perfil propio y seguridad de sesión están en `P01/Compartido`. Esta organización no habilita autorregistro ni desactiva la verificación por correo. Las cuentas ficticias preparadas por consola son una excepción local solicitada para la demostración.

### Auditoría — P08

`AuditController` permite consultar los eventos de CU22; `AuditLogger` registra metadatos y `app/Observers/ClinicAuditObserver` observa cambios de los demás paquetes. Los triggers y procedimientos siguen en las mismas migraciones. Los usuarios no obtienen permisos para modificar la bitácora.

## Alcance de esta reorganización

Se cambiaron carpetas, namespaces, imports y la ubicación de métodos. Se mantienen las entidades y sus nombres, el esquema de la base, las rutas públicas y sus protecciones. Las mejoras de horarios y navegación solicitadas previamente son una actividad distinta y no se justifican como cambios de arquitectura. No se ejecutaron operaciones de Git.

Los informes de fechas anteriores en `docs` describen versiones previas. Para localizar clases en la estructura actual, utilice esta guía y el CSV.
