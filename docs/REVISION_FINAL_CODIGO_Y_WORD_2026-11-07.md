# Revisión final del código y del documento Clínica Vida Salud

Fecha: 7 de noviembre de 2026.

## Resultado y alcance

Se contrastó la implementación actual con `Clinica_Vida_Salud_V6_Integrada_Limpia.docx`, disponible en la carpeta superior del proyecto. Se revisaron los requisitos funcionales, reglas de negocio, las especificaciones de los 26 casos de uso y los apartados 7.1–7.3, incluidas las imágenes del diagrama general y los ocho subsistemas. No se modificó el Word.

Los 26 CU tienen implementación identificable. La revisión encontró y corrigió tres diferencias concretas en CU09 y CU16. Las pruebas del código resultante pasaron. Esto acredita los escenarios comprobados, no todas las combinaciones de errores, una prueba de carga ni una operación bancaria real. CU18/CU19 necesitan todavía comprobarse con credenciales reales del entorno de pruebas de WizPay; CU23 y recuperación necesitan verificar la recepción efectiva de los correos.

El archivo de referencia es diferente del `Clinica_Vida_Salud_V6_26CU_Revision_Final.docx` citado en entregas anteriores. Su SHA-256 es `52E5918AADF1FA933642132B8AE71DA32CA5FC6BFE9AD734B02CECB2569686AB`. Este informe y las instrucciones actuales del README prevalecen para describir esta revisión; los informes anteriores conservan sus resultados históricos.

## Diferencias corregidas en el programa

| Hallazgo | Comportamiento anterior | Corrección y evidencia |
| --- | --- | --- |
| CU09, flujo básico paso 10: referencia del pago o exención en la ficha | La relación persistente existía, pero la ficha solo mostraba importe y estado. | La ficha muestra el medio y referencia bancaria o del proveedor; para efectivo muestra el identificador interno del pago y para seguimiento exento la consulta original. No se inventan referencias bancarias. Ver `resources/js/modules/clinical.js` y pruebas de navegador de ficha online, efectivo, transferencia y exención. |
| CU09, pasos 5–6: distinguir bloqueos provisionales | Las franjas compatibles con un bloqueo de la misma especialidad podían seleccionarse, pero visualmente no se diferenciaban de las libres. | La columna Estado del cupo identifica el bloqueo provisional de esa especialidad. No lo presenta como una cita ni lo consume por consultarlo. Tres escenarios de navegador comprueban la etiqueta; `ClinicalPaymentsTest` comprueba el consumo transaccional solo tras pago válido. |
| CU16, REP02/REP03/REP07 y FA02: criterios propios del reporte | Se admitía omitir médico o especialidad y obtener resultados generales bajo el título de un reporte específico. | REP02/REP07 exigen médico y REP03 exige especialidad tanto en servidor como en interfaz. Se conservan REP01 por fecha/período, REP04 por estados, REP05 y REP06. `ClinicTest` prueba los siete reportes y el rechazo de los tres filtros omitidos. |

Estas correcciones concretan requisitos del Word. No crean nuevos CU ni funciones clínicas adicionales.

## Contraste de los 26 casos de uso

La palabra Implementado describe la operación encontrada en código y los escenarios comprobados; no representa un porcentaje de cobertura. Las operaciones internas más desglosadas se conservan en [la matriz funcional](MATRIZ_FUNCIONAL_V6_2026-11-07.md).

| CU | Operaciones y restricciones contrastadas | Implementación principal y evidencia | Resultado |
| --- | --- | --- | --- |
| CU01 | Identificador/correo, contraseña, cuenta y perfil activos, correo verificado de Paciente, permisos del servidor, regeneración y recuperación del panel tras login. | `AuthController`, `ClinicSession`, `SessionSecurity`, `ClinicAccess`; `EstablishedUseCasesTest`, `AccountSecurityTest`, `UsernameLoginTest`, navegador. | Implementado. |
| CU02 | Confirmación de salida, invalidación y CSRF renovado, denegación posterior, manejo del error sin anunciar un cierre inexistente. | `AuthController`, `app.js`; `EstablishedUseCasesTest`, `ClinicTest`, pruebas Node del cliente. | Implementado. |
| CU03 | Alta, consulta, búsqueda, modificación y activación/desactivación del personal; rol válido, identificador único, contraseña con hash, vínculo Médico; protección del acceso administrativo. | Controladores/Requests de catálogo, `CatalogService`; `ClinicTest`, `FoundationModulesTest`, `EstablishedUseCasesTest`, `SuperadminAccessTest`. | Implementado; Superadmin es ampliación autorizada. |
| CU04 | Registro profesional único, datos, varias especialidades activas, búsqueda, detalle y estados; detectar citas afectadas sin cancelarlas. | `DoctorController`, `SaveDoctorRequest`, `CatalogService`, `CatalogImpact`; pruebas de catálogos y casos establecidos. | Implementado. |
| CU05 | Nombre normalizado único, descripción opcional, consulta, búsqueda y estados; conservación de relaciones y citas afectadas. | `SpecialtyController`, `SaveSpecialtyRequest`, `SpecialtyName`, `CatalogImpact`; `EstablishedUseCasesTest`. | Implementado. |
| CU06 | Paciente único, CI cuando existe, validación y búsqueda por CI/nombres/apellidos, detalle, modificación y estados, historial preservado. | `PatientController`, `SavePatientRequest`, `CatalogService`; `FoundationModulesTest`. | Implementado; la cuenta online se separa en CU23. |
| CU07 | Turnos independientes, duración configurable, exclusión de superposiciones e indisponibilidades; 120 minutos protegidos por turno, porcentajes enteros, mayor resto, desempate presencial y alternancia. | `ScheduleService`, `ScheduleBlocks`, `ShiftModalities`, `DoctorQuotaService`, `QuotaAllocator`; `DoctorQuotaTest`, `V6RulesTest`, `ClinicTest`. | Implementado. Se protegen citas y retenciones ya existentes. |
| CU08 | Filtros por especialidad/médico/fecha, modalidad según actor, profesionales habilitados, carga/disponibilidad, exclusión de ocupaciones y consulta sin reserva. | `MedicalAvailabilityController`, `AppointmentService`, `DoctorQuotaService`; `V3WorkflowTest`, `DoctorQuotaTest`, `ClinicalPaymentsTest`, navegador. | Implementado. |
| CU09 | Paciente activo, franja presencial compatible, nueva comprobación de disponibilidad, pago íntegro/exención, ficha, referencia, consumo compatible y formalización idempotente. | `AppointmentService::book`, `PaymentVerifier`, `clinical.js`; `ClinicalPaymentsTest`, navegador. | Implementado; ficha y diferenciación del bloqueo corregidas en esta revisión. |
| CU10 | Solicitud propia de Paciente, aviso a Recepción, coordinación externa, aceptación, cambio de fecha/hora/médico de la misma especialidad, rechazo e historial; conservar pago/exención cuando corresponde. | `ClinicalWorkflowController`, `AppointmentService`, `reception-inbox.js`, `clinical.js`; `ClinicalPaymentsTest`, `SuperadminAccessTest`, navegador. | Implementado. La solicitud del portal no cambia directamente la cita. |
| CU11 | Cancelación exclusiva de Recepción, motivo y causa, estado compatible, liberación y conservación del historial; información para reembolso separado. | `AppointmentController`, `AppointmentService::transition`; `ClinicTest`, `ClinicalPaymentsTest`. | Implementado. Paciente no cancela autónomamente una cita. |
| CU12 | Agenda por médico/fecha, detalle y estados; Médico limitado a su propia agenda, incluso ante filtros manipulados. | `AppointmentController::index`, `clinical.js`; `ClinicTest`, `SuperadminAccessTest`. | Implementado. |
| CU13 | Presencia y hora de llegada, Confirmada, ausencia al llamado distinta de inasistencia definitiva; evitar duplicados/contradicciones, cobro de seguimiento que pierde gratuidad. | `AppointmentService::transition/needsPayment`, `clinical.js`; `ClinicTest`, `ClinicalPaymentsTest`. | Implementado. Sin tolerancia fija ni conversión automática a Atendida. |
| CU14 | Médico responsable, presencia previa, consulta finalizada, estados compatibles y recomendación opcional de seguimiento sin reservarlo. | `AppointmentController::action`, `AppointmentService::transition`; `ClinicTest`, `ClinicalPaymentsTest`, `SuperadminAccessTest`. | Implementado. Superadmin supervisa; no finaliza consultas suplantando al Médico. |
| CU15 | Historial y filtros, detalle con creación/cancelación/reprogramación, datos persistentes y Paciente limitado a sus citas. | `AppointmentController`, eventos, `clinical.js`; `ClinicTest`, `SuperadminAccessTest`. | Implementado. |
| CU16 | Siete reportes, períodos, filtros propios de cada reporte, resumen por estados, vacíos y permisos. | `ReportController`, formulario en `app.js`; `ClinicTest`, `SuperadminAccessTest`. | Implementado; filtros obligatorios corregidos en esta revisión. |
| CU17 | Citas futuras afectadas por indisponibilidad, cambio de turno/duración, médico o especialidad inactivos y habilitación retirada; causa/origen, contacto y acceso a CU10/CU11 sin alterar la cita al consultar. | `ScheduleImpactController`, `ScheduleImpact`, `CatalogImpact`, `impacts.js`; `V6RulesTest`, `EstablishedUseCasesTest`, `SuperadminAccessTest`. | Implementado. La pantalla usa la consulta combinada `schedule-impacts`. |
| CU18 | Reserva de Paciente, cupos online autorizados, retención distinta de cita, dos intentos de hasta cinco minutos y diez acumulados, descarte seguro y ficha solo tras confirmación. | `OnlineBookingService`, `ClinicalWorkflowController`, `clinical.js`; `ClinicalPaymentsTest`, navegador. | Implementado con proveedor simulado en pruebas; validación sandbox real pendiente. |
| CU19 | Verificación presencial de efectivo/QR/transferencia e importe; referencias únicas, verificación online servidor a servidor, webhook, idempotencia y exención; resultado para el coordinador que formaliza. | `PaymentVerifier`, `WizPayGateway`, webhook, job y conciliador; `ClinicalPaymentsTest`. | Presencial comprobado; integración online comprobada con respuestas simuladas. |
| CU20 | Solo cita previamente cancelada y pago confirmado; titularidad, duplicados, 24 horas, gracia de 15 minutos y causa clínica; admisión o rechazo automático con motivo. | `RefundService::request`; `ClinicalPaymentsTest`, `SuperadminAccessTest`. | Implementado. Solicitar no cancela, aprueba ni devuelve dinero. |
| CU21 | Consulta original Atendida con recomendación, mismo paciente/especialidad, disponibilidad, vínculo, pago o gratuidad según fecha/hora y causa; pérdida de gratuidad por cambio voluntario/retraso; sin límite numérico fijo añadido. | `AppointmentService`, `PaymentVerifier`, `clinical.js`; `ClinicalPaymentsTest`. | Implementado. Recomendación y cita formalizada son operaciones distintas. |
| CU22 | Registro automático, responsable/fecha/acción/registro/resultado, filtros, paginación, detalles permitidos, consulta administrativa y eventos inmutables sin secretos. | `ClinicAuditObserver`, `AuditLogger`, `AuditController`, trigger PostgreSQL; `FoundationModulesTest`, `EstablishedUseCasesTest`, `V6RulesTest`. | Implementado. |
| CU23 | Registro público, términos, contraseña confirmada y con hash, duplicados, vínculo seguro de paciente existente, verificación de correo, vuelta al login y recuperación por correo. | `RegisterPatient`, Requests, invitación, `PatientRegistrationController`, `AccountRecoveryController`; `FoundationModulesTest`, `AccountSecurityTest`, `UsernameLoginTest`, navegador. | Implementado; recepción real de correo todavía no acreditada por esta revisión. |
| CU24 | Conteo/estimación por especialidad y solo hoy, propuesta de franjas próximas, selección/confirmación, liberación manual individual o múltiple, consumo solo por CU09; sin caducidad automática. | `AgendaAdjustments`, controlador y módulo de agenda; `V6RulesTest`, `V3WorkflowTest`, `ClinicalPaymentsTest`, navegador de ficha. | Implementado. |
| CU25 | Selección de franjas libres de la protección inicial, permisos de Recepción, revalidación y publicación online; impedir retirada en interfaz y servidor. | `AgendaAdjustments`, `DoctorQuotaService`, `agenda-adjustments.js`; `V6RulesTest`, `V3WorkflowTest`. | Implementado. |
| CU26 | Resolver solo admitidas pendientes, motivo de rechazo, responsable y fecha, aprobación separada de entrega; registro externo de ejecutor, fecha, medio, importe y referencia opcional; impedir duplicados. | `RefundService::decide/complete`, `clinical.js`; `ClinicalPaymentsTest`, `SuperadminAccessTest`. | Registro manual implementado según V6. La entrega externa del dinero queda fuera de la aplicación. |

## Arquitectura e inventario técnico

El proyecto mantiene un monolito modular Laravel con presentación Blade/JavaScript, entrada HTTP, validación/autorización, servicios de negocio y persistencia Eloquent/PostgreSQL. P01–P08 son agrupaciones funcionales dentro de esa aplicación, no ocho aplicaciones independientes. Hay consultas SQL parametrizadas y restricciones específicas de PostgreSQL para integridad y exclusión de superposiciones.

Se verificaron PHP 8.4.27, Laravel 13.38.0, PostgreSQL 17.11, Composer 2.8.12, Node.js 24.21.0, npm 11.19.0, Vite 7.3.7, Git 2.55.0.windows.3, PHPUnit 12.5.40 y Pint 1.32.1. Coinciden con las versiones indicadas para esas herramientas en 7.1. No se repitió el inventario de instalación de Windows, DBeaver, VS Code, Enterprise Architect ni Codex; no se acredita una comprobación nueva de sus versiones.

La consulta de solo lectura a la base local confirmó 26 tablas públicas y 19 migraciones aplicadas, sin migraciones pendientes. Las tablas coinciden con las enumeradas en el diagrama general de 7.2. Las tablas de infraestructura pueden existir aunque el controlador local use archivos para sesiones/caché o ejecución síncrona de trabajos.

### Ajustes documentales concretos

| Lugar del Word | Hallazgo comprobado | Ajuste que corresponde documentar |
| --- | --- | --- |
| 4.1 y matriz de permisos; CU03 y supervisión | El Word enumera los cuatro actores originales; el programa incorpora Superadmin por solicitud posterior expresa. | Agregar el actor y su alcance de gestión/supervisión, manteniendo privacidad, hash de contraseñas y separación de funciones médicas y del paciente. |
| CU01/CU02 y componentes P01 | Se pidió posteriormente elegir/cambiar el usuario propio, gestionar contraseñas desde Superadmin y mostrar/ocultar lo que se escribe en campos de contraseña. | Registrar esas solicitudes aprobadas y las restricciones descritas en el informe de acceso. No describir una consulta de contraseñas almacenadas. |
| 4.3, párrafo posterior a la priorización | Permanece la frase que presenta CU23 como pendiente de especificación, aunque 4.6.4 ya lo desarrolla. | Actualizar la afirmación histórica para que no contradiga la especificación vigente. |
| CU24, Resultado esperado y fila Responsable y fechas | Habla de advertencias por antigüedad, mientras la descripción y reglas explícitas dicen que no se requiere temporizador de advertencia y que no hay caducidad automática. | Conservar trazabilidad de responsable/fechas y quitar la exigencia contradictoria de advertencias por antigüedad. El código sigue la regla explícita sin temporizador. |
| CU18, subflujo de pago online, paso 3 | La frase «Con confirmación genera ficha» dentro del subflujo realizado mediante CU19 puede atribuirle la formalización. | Precisar que CU19 devuelve el resultado y CU18 formaliza y emite la ficha. Esa separación ya figura correctamente en la especificación principal de CU19. |
| 7.1.4 | Indica 16 migraciones registradas. | Actualizar a 19 para esta revisión; las tablas siguen siendo 26. Las migraciones posteriores amplían estados/roles/identificadores sin añadir tablas. |
| 7.1.16 y capítulo 8 | La auditoría anterior declara que no ejecutó pruebas y el capítulo de pruebas está reservado. | Incorporar las ejecuciones actuales, cantidades, entorno y límites de la sección siguiente. La frase anterior puede conservarse solo como antecedente fechado. |
| 7.3.1, componente de inicio de sesión | El diagrama nombra `auth/login.js`, archivo que no existe en el repositorio. | Cambiar esa referencia a `resources/js/app.js`, donde está `loginView()`. El controlador `AuthController` sí existe. Agregar `modules/session.js`/`AccountUsernameController` y `modules/accounts.js`/`AccountManagementController` para las ampliaciones solicitadas. |
| 7.3.1, registro de cuenta | El diagrama enlaza el registro con `patient_account_invitations`, pero esa tabla solo representa la verificación/vinculación previa. | Mostrar también `users` y `patients`, creadas o vinculadas transaccionalmente mediante `RegisterPatient`. No presentar la tabla de invitaciones como almacenamiento exclusivo de una cuenta. |
| 7.3.6, etiqueta del reporte de médicos/especialidades | Puede interpretarse como un padrón de médicos, que no es uno de los siete REP de CU16. | Precisar «Citas por médico/especialidad» (REP02/REP03). `ReportController` consulta citas y sus relaciones; el padrón de pacientes corresponde a REP05. |
| 7.3.8, dos componentes marcados Formulario | «Registro de eventos del sistema» y «observación de cambios del dominio» son procesos automáticos, no formularios navegables. | Representarlos como procesos/componentes de infraestructura; conservar el formulario real `modules/audit.js` para consulta. |

Los diagramas 7.3.2–7.3.7 utilizan clases y tablas existentes. Son vistas simplificadas: por ejemplo, CU10 ejecuta la modificación mediante `AppointmentController`/`AppointmentService`, y `ClinicalWorkflowController` gestiona las solicitudes. Para defender el recorrido completo, conviene dibujar esa intermediación de servicios sin afirmar que cada controlador escribe directamente en una sola tabla.

La comprobación del Word fue de contenido y correspondencia técnica, con inspección de las imágenes de arquitectura incrustadas. No fue una revisión de paginación, índice ni maquetación completa del documento.

## Ampliaciones autorizadas posteriores al Word

El código conserva las solicitudes expresas del propietario: Superadmin y vistas por rol, consulta global de cuentas/pagos, cambio de contraseñas desde Superadmin, acceso por usuario o correo, selección del usuario propio con confirmación de contraseña y botones para mostrar/ocultar contraseñas introducidas. El [informe de acceso](ACCESO_POR_USUARIO_Y_GESTION_DE_CUENTAS_2026-11-07.md) describe seguridad y archivos.

La contraseña almacenada no se muestra ni puede recuperarse en texto plano. El cambio propio del usuario de acceso no cambia correo, contraseña, rol ni otras cuentas; renueva la sesión actual y revoca firmas anteriores. Superadmin no resuelve solicitudes de reembolso que él mismo presentó, no reserva personalmente en nombre de un paciente y no finaliza consultas como si fuera el médico responsable.

En esta revisión no se añadieron actores, políticas de negocio, módulos ni integraciones nuevas. Se corrigieron las tres diferencias existentes respecto al Word y se añadieron sus comprobaciones.

## Pruebas ejecutadas y límites

| Comprobación actual | Resultado | Alcance |
| --- | --- | --- |
| `php artisan test --compact --no-ansi` | 149 pruebas correctas, 1.708 aserciones. | PostgreSQL aislado `clinica_vida_salud_test`, transacciones revertidas. Incluye la nueva validación de filtros. HTTP de proveedor y notificaciones se simulan donde corresponde. |
| `node --test tests/Frontend/api.test.mjs tests/Frontend/navigation.test.mjs` | 10 pruebas correctas. | Cliente HTTP, CSRF, errores de sesión y permisos de navegación. |
| `npm.cmd run test:browser` | 26 pruebas correctas. | Edge con interfaz compilada y API simulada; cinco roles, apertura de 25 vistas de Superadmin, login, usuario propio, contraseñas, reservas/ficha/descarte, referencias de pago/exención, bloqueos y términos. No es una prueba integrada contra WizPay ni modifica cuentas reales. |
| `npm.cmd run build` | Correcto, Vite 7.3.7. | Recursos actualizados localmente; `public/build` permanece fuera de Git. |
| `php vendor/bin/pint --test` | Correcto en el proyecto. | Formato PHP, además del formato de los archivos modificados. |
| `git diff --check` | Correcto. | Espacios y marcadores del cambio. |
| Consulta de estructura/configuración local | 26 tablas, 19 migraciones, ninguna pendiente; módulos clínicos habilitados. | Solo metadatos y presencia/ausencia de configuración; no se vuelcan datos personales ni credenciales. |

La primera ejecución completa de PHP tuvo un error de Windows 1450 por recursos insuficientes al abrir `config`, con 147 pruebas aprobadas. La repetición previa a las correcciones pasó las 148 pruebas; después de las correcciones pasó la ejecución completa de 149. No se ocultó ni clasificó ese error de entorno como una regla funcional incumplida. Las pruebas Node necesitaron permiso de ejecución fuera del aislamiento porque este impedía crear sus procesos.

No se realizaron pruebas de carga, una matriz de navegadores/dispositivos móviles, ejecución remota de GitHub Actions ni una evaluación exhaustiva de todos los escenarios de concurrencia. Los bloqueos y restricciones de PostgreSQL se revisaron y se ejercitaron en escenarios automatizados concretos; eso no equivale a una campaña de estrés simultáneo.

## Dependencias externas y operación real

| Dependencia | Estado comprobado ahora | Verificación que aún no puede afirmarse |
| --- | --- | --- |
| WizPay sandbox | Adaptador y flujo programados; `WIZPAY_TEST_API_KEY` y `WIZPAY_TEST_WEBHOOK_KEY` ausentes en la configuración local. La interfaz impide iniciar un pago cuando no está disponible. | Generación y confirmación reales con el comercio, recepción de webhook público HTTPS y conciliación con el proveedor. No se configuraron claves inventadas ni se realizaron cobros. |
| Correo | Transporte local configurado como `smtp`. La entrega anterior comprobó autenticación SMTP sin enviar mensajes. | Recepción real de enlaces de verificación y recuperación. Esta revisión no envió correos a terceros ni repitió la autenticación externa. |
| Conciliación | Comando programado cada minuto y job de webhook existentes; cola local `sync`. | Ejecución permanente del programador. Debe mantenerse `php artisan schedule:work` cuando se opere con pagos; no se inició ni se acredita un proceso permanente en esta revisión. |
| Coordinación y devoluciones externas | Recepción registra acuerdos; Administración registra la devolución ejecutada fuera del sistema según CU26. | Envío automático de WhatsApp o devolución bancaria automática. El Word contempla coordinación y ejecución externa; no se agregaron esas integraciones. |

No se identificó una decisión de negocio nueva necesaria para las correcciones de esta revisión. Los puntos restantes son actualización documental y verificación de servicios externos; el sistema no debe presentarse como validado integralmente con una pasarela real hasta completarla.
