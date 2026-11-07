# Implementación integrada de los 26 casos de uso de V6

Fuente funcional: `Clinica_Vida_Salud_V6_26CU_Revision_Final.docx`. El Word no se modifica. Este informe sustituye el estado de desarrollo incompleto de la entrega anterior; no abre decisiones de negocio ni propone casos adicionales.

## Correspondencia entre documento y código

| Casos de uso | Comportamiento programado | Código principal |
| --- | --- | --- |
| CU01–CU02 | Sesiones, cuentas activas, cuatro roles, cierre y revocación de acceso | `AuthController`, `ClinicSession`, `ClinicAccess` |
| CU03–CU05 | Gestión de personal, médicos y especialidades; estados e impacto de cambios | Controladores y solicitudes `Catalog`, `CatalogService`, `CatalogImpact` |
| CU06 | Pacientes, consulta, búsqueda, actualización, desactivación y prevención de duplicados | `Catalog/PatientController`, `CatalogService` |
| CU07 | Turnos independientes, duración, remanente, ausencias y distribución por médico | Servicios `Schedules`, `ScheduleController` |
| CU08 | Búsqueda por especialidad, preferencia opcional, equilibrio por carga y cupos, modalidad según rol y exclusión de retenciones | `MedicalAvailabilityController`, `DoctorQuotaService` |
| CU09 | Validación de cupo y cobro presencial íntegro, transacción única, ficha Programada y consumo de bloqueo compatible de hoy | `AppointmentService::book`, `PaymentVerifier` |
| CU10 | Solicitud del titular sin alterar su cita; bandeja de Recepción, rechazo o cambio acordado por WhatsApp, misma especialidad y conservación del pago | `ClinicalWorkflowController`, `RescheduleRequest`, `AppointmentService::book` |
| CU11 | Solo Recepción cancela citas Programadas o Confirmadas, con causa y motivo; libera el horario y conserva historial | `AppointmentController`, `AppointmentService::transition` |
| CU12 | Agenda compartida por modalidad; Médico limitado a su agenda; pacientes puntuales conservan prioridad y los retrasados se ordenan por llegada | `AppointmentController::index`, módulo `clinical.js` |
| CU13 | Llegada física cambia a Confirmada y registra hora. Inasistencia definitiva requiere determinación expresa y motivo; no tiene tolerancia fija | `AppointmentService::transition` |
| CU14 | Médico propio registra consulta finalizada con paciente presente; puede recomendar fecha/hora de seguimiento | `AppointmentService::transition` |
| CU15 | Historial con búsqueda, estado, detalle y eventos; Paciente consulta únicamente el suyo | `AppointmentController::index`, módulo `clinical.js` |
| CU16 | Siete reportes administrativos, filtros, porcentajes, impresión y CSV | `ReportController`, presentación de reportes |
| CU17 | Causas de afectación y registros de origen, contacto y acceso a reprogramación/cancelación por Recepción | `ScheduleImpact`, `ScheduleImpactController`, módulo `impacts.js` |
| CU18 | Retención online, hasta dos intentos de cinco minutos, confirmación en servidor e identificación persistente de la franja; sin ficha anticipada | `OnlineBookingService`, `BookingRequest`, `ClinicalSlot` |
| CU19 | Verifica efectivo, QR/transferencia presencial con referencia única, QR sandbox confirmado y exención; no emite ficha por sí mismo | `PaymentVerifier`, `WizPayGateway` |
| CU20 | Solicitud sobre cita ya cancelada por Recepción; controla titularidad, pago, duplicados, 24 horas, gracia de 15 minutos y causa clínica | `RefundService::request` |
| CU21 | Seguimiento de consulta Atendida y recomendación, misma especialidad, gratuidad en fecha/hora acordadas, sin límite fijo; cobro al perder exención | `AppointmentService::book`, `needsPayment` |
| CU22 | Auditoría transaccional e inmutable, consulta administrativa y registro de accesos clínicos denegados | `ClinicAuditObserver`, `AuditLogger`, `ClinicalAccessAudit` |
| CU23 | Registro y términos, vinculación verificada, correo de verificación y recuperación mediante enlace temporal de un uso | Controladores de cuenta, servicios `Patients` |
| CU24 | Demanda presencial solo de hoy, cantidad/lote, selección y liberación manual. Consumo automático dentro de la transacción que formaliza una ficha compatible | `AgendaAdjustments`, `AppointmentService::consumeDemand` |
| CU25 | Habilita franjas protegidas libres para online; no permite retirada ni toma franjas retenidas | `AgendaAdjustments` |
| CU26 | Administrador decide únicamente solicitudes admitidas; aprobación y devolución son estados distintos. Registra ejecutor declarado, fecha, importe íntegro, medio y referencia opcional | `RefundService::decide`, `complete`, `RefundRequest` |

CU19 se incorpora a los procesos que lo incluyen; no se añadió una pantalla independiente que cree citas o permita confirmar pagos online manualmente. La comunicación por WhatsApp queda a cargo de Recepción, conforme al Word: la aplicación registra acuerdo y respuesta; no envía mensajes automáticamente.

## Integración económica y protección de datos

El coordinador de citas bloquea paciente y médicos en orden estable, verifica disponibilidad y crea cobro, ficha y consumo de demanda dentro de una transacción PostgreSQL. Un fallo revierte todos esos cambios. Las restricciones GiST impiden superposición de citas activas por médico y paciente y de retenciones por médico. Las franjas conservan una identidad estable por médico, fecha e intervalo; la modalidad y disponibilidad se calculan con las reglas vigentes, sin convertir una retención en cita.

Las operaciones iniciales llevan una clave única para que reenviar la misma solicitud no genere otra ficha ni otro cobro. QR y transferencias presenciales requieren referencia bancaria no reutilizada y declaración de verificación del cobro por Recepción. Los importes se conservan en centavos de BOB; en pantalla aparecen en bolivianos.

Se conservaron los registros anteriores sin inventar comprobantes ni borrar citas. Si una cita anterior no tiene pago verificable ni exención válida, debe verificarse el cobro para continuar su reprogramación o atención. Una asistencia previamente registrada conserva su hora al regularizar ese dato económico.

La llegada puntual a un seguimiento mantiene la exención aunque el médico termine la consulta más tarde. Un seguimiento que pierde la gratuidad por retraso o cambio voluntario exige el pago completo; un cambio atribuible a la clínica conserva la exención. Reprogramar reinicia la presencia para el nuevo turno y conserva los datos anteriores en sus eventos.

El derecho a reembolso se evalúa con el momento de cancelación, no con el momento posterior de envío de la solicitud. Una cancelación por causa clínica mantiene el reembolso completo. Se impide resolver una solicitud dos veces, registrar devoluciones sin aprobación o repetir una devolución completada. CU26 utiliza la alternativa manual definida en V6; no se inventa una confirmación automática del proveedor.

## WizPay: integración implementada y condiciones de operación

El adaptador genera QR exclusivamente en el sandbox, con clave privada en servidor, importe, moneda y referencia de operación. La confirmación consulta el estado servidor a servidor y verifica referencia, importe y operación antes de crear la cita. Este contrato corresponde a la [generación de QR](https://wizpay.app/docs/referencia/qr-generate) y la [consulta de estado de WizPay](https://wizpay.app/docs/referencia/status).

El receptor público valida el secreto Bearer y el ambiente SANDBOX; encola una verificación adicional en servidor y tolera avisos repetidos sin duplicar fichas. Si se pierde la respuesta inicial de generación, un aviso autenticado puede recuperar la referencia mediante la operación original y someterla a conciliación. El formato y el mapeo se basan en la [documentación oficial de webhooks](https://wizpay.app/docs/webhooks).

Los intentos locales duran como máximo cinco minutos cada uno y diez minutos en total. No se genera un segundo cobro mientras el anterior esté confirmado, pendiente o desconocido. El QR del proveedor tiene su propio vencimiento, documentado como diez minutos; no se inventó un parámetro externo para acortarlo. Al vencer el plazo local se conserva la retención si el proveedor todavía mantiene una transacción pendiente, conforme a RN43, y se espera la conciliación antes de liberar. El navegador deja de mostrar el QR del intento vencido. Véase el [flujo QR del proveedor](https://wizpay.app/docs/flujos/qr).

Las consultas de respaldo usan espera creciente de 30 a 480 segundos; los avisos autenticados permiten comprobar antes sin mantener un ciclo agresivo de consultas. El comando `clinica:conciliar-pagos` también permite revisar las operaciones pendientes.

Para operar el sandbox se necesitan `WIZPAY_TEST_API_KEY`, `WIZPAY_TEST_WEBHOOK_KEY`, una URL pública registrada en el panel y los procesos `queue:work` y `schedule:work`. Sin la clave API el sistema rechaza iniciar pagos online y no retiene horarios inútilmente. No contiene claves reales, botones que simulen un pago confirmado ni acceso a dinero de producción.

## Decisiones técnicas que pueden reflejarse en el documento

No se agregaron casos de uso ni funciones de negocio ajenas a V6. Se concretaron estos detalles de implementación, que pueden describirse en la sección de diseño sin convertirlos en requisitos nuevos:

- La tarifa de CU19 se administra en el registro de la especialidad. Los registros preexistentes quedan sin precio asignado hasta que la clínica lo configure; no se establece un precio comercial por defecto.
- `clinical_slots` identifica intervalos; `booking_requests` conserva retenciones y operación; `payments` conserva intentos y confirmación; `reschedule_requests` y `refund_requests` conservan solicitudes y decisiones.
- Los estados técnicos de solicitudes y pagos se muestran en español en la interfaz y no sustituyen los cinco estados de la cita definidos en V6.
- Las migraciones agregan estructuras y conservan datos. Las exclusiones PostgreSQL y las referencias únicas refuerzan los controles del servicio.
- La bitácora conserva metadatos de los cambios y las denegaciones; no copia contraseñas, tokens, claves de WizPay ni cuerpos completos del proveedor.

No hay preguntas de negocio pendientes en este informe. Las credenciales y los procesos de correo/pasarela son configuración del entorno, no decisiones funcionales nuevas.

## Verificación y límites de la evidencia

Resultado final: **116 pruebas PHP aprobadas, 1393 aserciones y 6 pruebas JavaScript aprobadas**. Pint, compilación Vite y comprobación de espacios Git finalizaron correctamente. Las tres migraciones nuevas se aplicaron tanto en la base aislada de pruebas como en la base local, conservando los registros. El servidor respondió HTTP 200 y la sesión pública entregó los accesos de CU18 para Paciente y CU26 para Administrador.

En el entorno local se habilitaron los módulos de citas. Las claves API y webhook de WizPay todavía no están configuradas; el inicio de un pago online se rechaza con un mensaje de indisponibilidad, sin inventar una confirmación ni crear una retención. El correo permanece en modo de desarrollo por log. Estas condiciones se deben configurar para comprobar los servicios externos.

Las pruebas automatizadas cubren los flujos anteriores, incluidos cobro insuficiente, falta de cobro, referencia bancaria duplicada, reenvío de solicitudes, bloqueo online, dos intentos fallidos, pago pendiente después del plazo, importe de pasarela incorrecto, webhook autenticado e idempotente, permisos y titularidad, gracia de reembolso, aprobación separada de ejecución y pérdida/conservación de exención. También se conserva la regresión de autenticación, catálogos, horarios, demanda, auditoría y reportes.

La pasarela se prueba con `Http::fake` únicamente dentro de las pruebas, sin sustituir el adaptador de la aplicación. Las notificaciones de correo también se simulan en pruebas. La compilación no equivale a una prueba visual: no se acredita revisión de todas las pantallas en navegador ni un cobro externo con credenciales reales del comercio. El correo local `MAIL_MAILER=log` registra mensajes privados y no los entrega a una bandeja externa.
