# Informe de avance provisional de Clínica Vida Salud

> Registro histórico del avance V3. La referencia vigente es la V6 definitiva y su [registro de adecuación](ADECUACION_WORD_V6_2026-11-07.md). Las funciones propias de perfil/contacto y retirada de aperturas aquí descritas se retiraron al adecuar el programa.

Fecha: 7 de noviembre de 2026.

Se adaptó el programa a la versión V3 del análisis y arquitectura entregada por el equipo. Esta entrega permite gestionar varios turnos, aplicar protección presencial y distribución de modalidades, administrar bloqueos y aperturas desde Recepción, consultar disponibilidad y supervisar auditoría. También completa operaciones de pacientes y corrige el final del registro de cuentas. El avance es provisional y no implica que todos los casos de uso estén aprobados ni terminados.

## Documento revisado y alcance

Fuente: `Clinica_Vida_Salud_V3_Analisis_Arquitectura_actualizado.docx`, de 7 603 208 bytes. SHA-256: `E72750520849BC34ECE062514990BF75AF3D7D41908D17841AB0B03421C467AA`.

Se contrastaron las reglas RN01–RN64, los requisitos no funcionales, los detalles de CU06, CU07, CU08, CU19, CU22 y CU23, y la propuesta de persistencia de la sección 6.1.4. El Word original se conserva sin modificaciones. Las observaciones de documentos anteriores son antecedentes; esta revisión sustituye sus afirmaciones sobre un solo turno, ausencia de porcentajes iniciales y consulta de auditoría pendiente.

El equipo autorizó avanzar provisionalmente. Durante esta entrega confirmó dos decisiones: **porcentajes enteros** en nuevas configuraciones y **alternancia cronológica de modalidades respetando los totales**. No se consideró cerrado globalmente CU06–CU23 por esas respuestas.

## Funcionalidades implementadas

| Área | Comportamiento incorporado | Trazabilidad |
| --- | --- | --- |
| Pacientes | Consulta individual de solo lectura con datos personales y contacto; activación y desactivación mediante operación independiente; las altas quedan activas aunque el cliente envíe otro estado. Se conservan identificadores, cuentas e historial. | CU06, RN01, RN11 y RN12 |
| Turnos médicos | Varios turnos por médico y día; alta y edición por identificador; rechazo de superposiciones y de duraciones que dejen minutos sobrantes; restricción de exclusión en PostgreSQL. | CU07, RN05, RN07 y RN08 |
| Protección y cupos | Primeros 120 minutos protegidos en cada turno, incluido el turno de tarde; resto con 50/50 por defecto o configuración del médico, mayor resto y desempate presencial. Nuevas configuraciones con porcentajes enteros. | CU07, RN35 y RN36 |
| Asignación cronológica | Los cupos del resto alternan presencial y online, empezando por presencial cuando tiene cupos. Al agotarse una cuota, los restantes pertenecen a la otra modalidad. Cada turno se calcula por separado. | Decisión confirmada por el equipo; complementa 6.1.4 |
| Bloqueos de demanda | Recepción indica especialidad, fecha y cantidad; revisa los próximos horarios presenciales propuestos y confirma. Se informa la cantidad que no puede protegerse. La propuesta no escribe datos; la confirmación revalida todo dentro de una transacción. | CU07, RN37 |
| Gestión de bloqueos | Liberación manual con responsable y fecha; advertencia por antigüedad, sin caducidad automática; conservación de movimientos liberados. No se crean pacientes, citas, fichas ni pagos. | CU07, RN37 y RN59–RN60 |
| Apertura anticipada | Recepción selecciona franjas libres protegidas y las habilita para online. No admite franjas ocupadas, pasadas, indisponibles ni bloqueadas. Puede retirar la apertura; queda registrado el movimiento. | CU07, RN35 |
| Disponibilidad provisional | Recepción consulta presencial y Paciente consulta online. Filtra médicos y especialidades activos, relación médico–especialidad, fecha, modalidad, citas activas, indisponibilidades, bloqueos y aperturas. No reserva ni emite fichas. | CU08 parcial, RN02, RN04–RN09 y RN35–RN37 |
| Citas afectadas | Detecta citas futuras vigentes fuera de los bloques actuales, afectadas por ausencia o por médico/especialidad desactivados o relación retirada. Se consultan sin modificar su estado. | CU07 y CU17 parcial, RN14, RN21 y RN24 |
| Auditoría | Pantalla exclusiva de Administrador con filtros por fechas, usuario, módulo, acción y resultado; paginación y detalle de solo lectura. No publica detalles arbitrarios ni habilita escrituras. | CU22, RN59–RN62 |
| Registro online | Tras crear o vincular la cuenta, regresa al inicio de sesión; ya no autentica automáticamente. Conserva verificación de identidad mediante código de recepción, aceptación de términos y hash de contraseña. | CU23, flujo básico paso 6 |

### Ejemplo del reparto por turno

Para un turno 08:00–12:30 con consultas de 30 minutos hay nueve bloques. Los cuatro primeros, 08:00–10:00, quedan protegidos para presencial. Solo los otros cinco participan en el reparto:

| Porcentajes presencial y online | Cupos del resto | Total del turno incluido el período protegido |
| --- | --- | --- |
| 50 y 50 | 3 y 2 | 7 y 2 |
| 60 y 40 | 3 y 2 | 7 y 2 |
| 70 y 30 | 4 y 1 | 8 y 1 |
| 35 y 65 | 2 y 3 | 6 y 3 |
| 20 y 80 | 1 y 4 | 5 y 4 |

Con 50/50, el resto queda presencial, online, presencial, online y presencial. Con 70/30 queda presencial, online, presencial, presencial y presencial. No se garantiza una separación uniforme cuando una modalidad tiene pocos cupos; se alterna mientras ambas conservan cupos.

Las modalidades base se calculan **antes** de descontar indisponibilidades. Una ausencia elimina los bloques que toca, sin reclasificar las otras horas. Una apertura anticipada puede aumentar los horarios online; un bloqueo activo conserva su carácter presencial aunque después cambien los porcentajes. Por estas excepciones operativas, la disponibilidad efectiva no tiene por qué mantener el porcentaje teórico exacto.

## Decisiones para incorporar o precisar en el Word

1. **Alternancia concreta.** Añadir a 6.1.4: «En el resto de cada turno se alternarán cupos presenciales y online, comenzando por presencial cuando tenga cupos asignados. Al agotarse una modalidad, las franjas restantes se asignarán a la otra, conservando los totales del mayor resto». Esta decisión fue confirmada en la conversación.
2. **Porcentajes enteros.** Mantener la precisión indicada en 6.1.4 y reflejarla en CU07 y el formulario. La migración conserva sin redondear posibles configuraciones decimales antiguas; cualquier nueva escritura debe usar enteros. Si existen filas antiguas con decimales, Administración debe revisar su sustitución antes de validar globalmente la restricción.
3. **Consulta que cruza los 120 minutos.** Provisionalmente se protege íntegra toda franja cuyo inicio esté dentro de las primeras dos horas. Ejemplo: con consultas de 90 minutos, la franja 09:30–11:00 de un turno que empieza a las 08:00 permanece presencial. No se parte una consulta. Requiere confirmación del equipo.
4. **Duraciones y remanentes.** Los nuevos turnos deben componerse de consultas completas, sin minutos residuales; se conserva el límite técnico previo de 5 a 240 minutos. Confirmar si debe rechazarse el remanente o permitirse tiempo libre al final, y cuáles son los límites operativos definitivos.
5. **Antigüedad de bloqueos.** Se adoptaron provisionalmente 30 minutos para mostrar una advertencia, configurados en `config/clinic.php`. No existe temporizador de liberación ni tarea que elimine bloqueos. Confirmar umbral y frecuencia de revisión operativa.
6. **Cambios con movimientos activos.** Se rechaza una edición de turno que invalidaría una franja con bloqueo o apertura activos. Recepción debe liberar primero el movimiento correspondiente. Los cambios que afecten citas ya formalizadas se guardan y muestran en CU17 sin cancelar ni reprogramar esas citas.
7. **Alcance de la propuesta de demanda.** La búsqueda comprende la fecha seleccionada y médicos activos habilitados para la especialidad. Ordena por hora de inicio y, ante empate, por identificador del médico. El máximo técnico por operación es 100; solicitudes mayores deben revisarse antes de ampliar ese límite.
8. **Persistencia provisional.** La propuesta de 6.1.4 se implementó parcialmente con horarios existentes, cálculo dinámico de franjas y `agenda_adjustments`, en lugar de materializar todavía todas las filas de `franjas_agenda`. Cada movimiento guarda médico, fecha, inicio, fin, tipo, especialidad cuando corresponde, estado y responsables. Antes de habilitar pagos debe fijarse una referencia estable de franja y enlazar retenciones, citas y transacciones de forma atómica.
9. **Registro y cuentas.** Mantener la redirección a CU01 del paso 6 de CU23. Precisar en ese caso el código de vinculación verificado por recepción: generación, vigencia técnica actual de 48 horas, uso único y revocación al emitir otro. La recuperación de contraseña sigue pendiente aunque CU23 menciona ofrecerla.
10. **Datos y consultas.** Los pacientes solo reciben nombres de médicos y especialidades habilitadas para buscar disponibilidad; no reciben padrones de pacientes, teléfonos profesionales, correos privados ni identidad de quienes ocupan un horario. En CU17, solo Recepción recibe nombre y teléfono del paciente afectado para poder contactarlo; la consulta administrativa de conflictos no incluye esos datos. Auditoría devuelve 50 registros por página, con fechas interpretadas en la zona de la aplicación.

Los valores de 30 minutos, 100 movimientos por operación, 50 eventos por página y la política de franja que cruza el límite son decisiones técnicas provisionales. No deben presentarse como reglas aprobadas por la clínica.

## Funciones que continúan pendientes

- **CU07:** consumir un bloqueo al formalizar una cita de su especialidad y comprobar retenciones de pago. Actualmente los movimientos pueden estar activos o liberados; no se expone un estado «consumido» sin implementar la operación económica que lo justifica.
- **CU08:** integración con retenciones de pago y franjas persistentes. Hoy no hay un flujo habilitado que cree retenciones, por lo que la consulta provisional no incluye aún esa entidad. No debe habilitarse CU18 sin incorporarla a la misma comprobación de disponibilidad.
- **CU09 y CU19:** tarifas, solicitud provisional de cita, pago presencial confirmado, exención autorizada, idempotencia, ficha y consumo de bloqueo en una sola operación coherente.
- **CU18 y CU19 online:** contrato real de WizPay en pruebas, confirmación verificable, dos intentos de cinco minutos, conciliación de pagos pendientes y liberación segura. No se simuló una confirmación como si viniera del proveedor.
- **CU10 y CU11:** aceptación del paciente, conservación del pago, motivo atribuible a clínica o paciente y efecto económico de reprogramación o cancelación.
- **CU13 y CU14:** adaptar el código anterior a presencia → Confirmada, finalización médica → Atendida y atención efectiva sin tolerancia fija. El código histórico no demuestra cumplimiento de las reglas nuevas de asistencia.
- **CU20 y CU21:** solicitudes de reembolso, decisión administrativa, ejecución de devolución y seguimiento vinculado a consulta Atendida con gratuidad verificable.
- **CU15 y CU16:** adaptar historial y reportes a pagos, nuevas modalidades y estados operativos.
- **Verificación adicional:** recorrido visual en navegador, comprobación móvil, ensayos de concurrencia con varios procesos y mediciones de carga. Las pruebas de esta entrega comprueban exclusiones SQL y propuestas obsoletas, pero no sustituyen una prueba de carga concurrente.

El comportamiento histórico de citas y reportes sigue deshabilitado por defecto con `CLINIC_APPOINTMENTS_ENABLED=false`. Activar ese indicador no implementa pagos ni garantiza el nuevo contrato; se conserva para regresión y desarrollo del código anterior.

## Preguntas pendientes para el equipo

| Prioridad | Pregunta | Qué permite cerrar |
| --- | --- | --- |
| Alta | ¿La tarifa depende de especialidad, médico o ambos? ¿Se cobra en bolivianos y con qué vigencia se conserva el importe de una solicitud? | Catálogo de precios, CU09 y CU19 |
| Alta | ¿Qué evidencia válida confirma un QR o transferencia presencial y quién puede verificarla? ¿Se exige referencia bancaria única? | RN63–RN64 y prevención de pagos duplicados |
| Alta | ¿Cuál es la documentación oficial y el acceso al entorno de pruebas de WizPay? ¿Permite verificar estados, notificaciones firmadas, idempotencia y devoluciones? Las credenciales deben configurarse localmente, no añadirse al informe o al repositorio. | Integración real de CU18–CU20 |
| Alta | ¿Cuándo vence una solicitud presencial mientras se verifica el pago y qué sucede con un importe recibido si el horario deja de ser válido? | Retención presencial y conciliación |
| Alta | ¿Qué actores pueden autorizar exenciones distintas de un seguimiento gratuito y qué comprobación debe quedar registrada? | RN39 y RN44 |
| Media | ¿Se confirma la protección completa de una consulta que atraviesa el límite de dos horas? ¿Se permiten remanentes al final de un turno? | Casos límite de CU07 |
| Media | ¿Se aprueban 30 minutos como advertencia? ¿Se necesitan conteos separados «contado» y «estimado», con historial de demanda además del historial de bloqueos? | Operación diaria de Recepción |
| Media | ¿Los bloqueos por demanda podrán prepararse para días futuros o solo para la jornada actual? Actualmente se admite fecha actual o futura. | Alcance temporal de RN37 |
| Media | Si hay varios médicos de una especialidad libres a la misma hora, ¿se mantiene el desempate por código o se prefiere rotación de carga? | Equidad en la propuesta presencial |
| Media | ¿Cómo se documenta la aceptación del paciente en reprogramaciones: constancia de llamada, aceptación en pantalla u otro mecanismo? | RN13 y RN23 |
| Media | ¿El paciente solicita cancelación directamente o siempre la ejecuta Recepción? ¿La solicitud de reembolso puede preceder a esa cancelación? | Coordinación de CU11 y CU20 |
| Media | ¿Cuántos seguimientos gratuitos puede originar una consulta y quién fija o valida la fecha acordada? | RN53–RN58 |
| Media | ¿La recuperación de contraseña se implementará por correo verificado o mediante verificación en recepción? | Excepción FA01 de CU23 |

## Organización del código

Los controladores reciben solicitudes y comprueban permisos; los servicios coordinan las reglas y transacciones; PostgreSQL protege rangos e integridad; los observadores escriben auditoría en la misma operación. Las pantallas nuevas están separadas en módulos JavaScript.

| Responsabilidad | Archivos principales |
| --- | --- |
| Turnos y citas afectadas | `ScheduleService`, `ScheduleBlocks`, `ScheduleImpact`, `ScheduleImpactController` |
| Distribución | `QuotaAllocator`, `ShiftModalities`, `DoctorQuotaService` |
| Operación de Recepción | `AgendaAdjustment`, `AgendaAdjustments`, `AgendaAdjustmentController` |
| Consulta de horarios | `MedicalAvailabilityController`, módulo `availability.js` |
| Auditoría | `AuditController`, módulo `audit.js`, observador existente |
| Interfaz operativa | `agenda-adjustments.js`, `schedules.js`, `impacts.js` |
| Verificación | `V3WorkflowTest`, `DoctorQuotaTest`, `FoundationModulesTest` y regresión anterior |

Las creaciones de movimientos bloquean médicos en orden de identificador y revalidan cada intervalo. Las confirmaciones obsoletas o lotes duplicados se rechazan y revierten completos. Las restricciones de exclusión de PostgreSQL impiden turnos y movimientos activos superpuestos incluso cuando se omite el servicio. No se instaló una extensión adicional de PostgreSQL para esas restricciones.

## Verificación y puesta en marcha

- Revisión completa: **86 pruebas PHP y 1235 aserciones aprobadas**.
- Cliente JavaScript: **6 pruebas aprobadas**.
- Compilación Vite correcta. Comprobaciones de formato y diferencias de Git incluidas en el cierre.
- Se aplicaron tres migraciones nuevas en la base aislada de pruebas y en la base local del proyecto, sin vaciar tablas ni recrear pacientes, usuarios o citas.
- La revisión visual no se completó porque el inventario de herramientas no devolvió navegadores conectados. No se presenta como validación realizada.
- El servidor local quedó iniciado y se comprobó una respuesta HTTP 200 en `http://127.0.0.1:8000`, con el contenedor de la aplicación presente.

Para visitar el programa, ejecutar desde `clinica-vida-salud`: `php artisan serve --host=127.0.0.1 --port=8000` y abrir `http://127.0.0.1:8000`.

Administrador: **Horarios médicos**, **Citas afectadas** y **Consultar auditoría**. Recepción: **Pacientes**, **Bloqueos y aperturas de cupos**, **Disponibilidad** y **Citas afectadas**. Paciente: **Disponibilidad** y **Mi perfil**. Los datos de acceso se conservan en los archivos privados locales ya existentes y no forman parte de esta entrega.
