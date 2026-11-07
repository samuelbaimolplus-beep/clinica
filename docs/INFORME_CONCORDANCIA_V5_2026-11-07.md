# Informe de concordancia del programa con la versión V5

Fecha: 7 de noviembre de 2026.

**El programa tiene una base funcional reutilizable, pero todavía no cumple íntegramente la V5.** La configuración de turnos, la distribución de modalidades, los pacientes, la consulta provisional de disponibilidad y la auditoría ya tienen implementación. Las diferencias más inmediatas son la reversión de aperturas online que la V5 aún no autoriza, el acceso administrativo adicional a CU17 y las partes incompletas de CU24, CU25 y de la gestión económica.

Esta revisión actualiza el informe anterior frente a los **26 casos de uso**. En esta entrega se revisó el documento y el código; **no se modificaron funcionalidades, pruebas ni datos del programa**. Las acciones de corrección que se describen a continuación son trabajo pendiente, no cambios ya realizados.

## Fuentes y criterio de revisión

- Documento: `Clinica_Vida_Salud_V5_26CU_Casos_Corregidos.docx`, de 16 618 015 bytes.
- SHA-256 del documento: `B1C3CB47525D14E89DC25A9C2036AE3FFF6DC63A1724601B76172F83F967ACDA`.
- Código revisado: entrega **Adaptar avance provisional a V3 con turnos, cupos y auditoría**.
- Antecedente: [informe de avance V3](INFORME_AVANCE_V3_2026-11-07.md). Conserva lo implementado entonces, pero su asociación de bloqueos y aperturas a CU07 queda sustituida por CU24 y CU25 en esta revisión.

Se contrastaron la identificación y priorización de casos de uso, las reglas de negocio, los detalles modificados de CU07, CU17, CU19, CU20 y CU24–CU26, las decisiones pendientes de 4.6.5 y la propuesta de persistencia de 6.1.4. También se inspeccionaron rutas, permisos, servicios, pantallas, migraciones y pruebas pertinentes del programa. La extracción textual permite revisar requisitos; no constituye una validación visual de las imágenes UML del Word.

La valoración distingue implementación habilitada, implementación parcial y código histórico deshabilitado. Un caso marcado «Definido» en el Word expresa su estado documental y no demuestra que esté programado o aprobado mediante pruebas de aceptación.

## Cambios relevantes de la V5

| Caso | Delimitación de la V5 | Consecuencia para el proyecto |
| --- | --- | --- |
| CU07 | Configuración exclusiva del Administrador: turnos, duración, indisponibilidades y porcentajes. | Los movimientos operativos de Recepción deben trazarse aparte. Los permisos actuales ya separan ambas responsabilidades. |
| CU24 | Recepción crea y libera bloqueos por demanda. CU09 consume el bloqueo compatible al formalizar la cita. | La creación y liberación existentes son una base parcial; falta consumo y algunos pasos de interfaz y registro. |
| CU25 | Recepción habilita franjas protegidas para online. La reversión permanece sin autorización funcional. | El botón y el servicio actuales que retiran aperturas contradicen la delimitación vigente. |
| CU17 | Detecta ausencia, cambio de horario, médico o especialidad inactivos y pérdida de habilitación profesional. Su actor es Recepción. | El motor cubre esas causas, pero la pantalla y el contrato de detalle todavía son incompletos y existe acceso extra de Administrador. |
| CU19 | Verifica y registra pago o exención; devuelve un resultado económico. | La formalización y ficha corresponden a CU09, CU18 o CU21, no al verificador de pago. No existe aún esta implementación económica. |
| CU20 | Solicita reembolso de una cita pagada y previamente cancelada por Recepción; aplica admisión automática. | Solicitar desde el portal no cancela la cita ni ejecuta una devolución. |
| CU26 | El Administrador resuelve solicitudes admitidas y supervisa la devolución real. | Deben separarse decisión administrativa, ejecución económica y comprobación del resultado. |

La V5 no añade tres funcionalidades ajenas al alcance anterior: separa responsabilidades que antes estaban agrupadas. Sus secciones de prototipos y diagramas reconocen expresamente que las imágenes heredadas requieren revisión; no deben presentarse como modelos actualizados de los 26 casos.

## Estado del programa frente a los 26 casos

«Base implementada» significa que existe el flujo principal y evidencia de pruebas previas, sin certificar todos los criterios de aceptación de la V5. «Histórico deshabilitado» significa que hay código anterior, pero no está disponible por defecto ni acredita las reglas nuevas.

| Caso de uso | Estado observado | Alcance y diferencia principal |
| --- | --- | --- |
| CU01 Iniciar sesión | Base implementada | Cuatro roles, credenciales, estado activo, sesión y permisos. La tabla 4.3 de V5 lo mantiene «En revisión». |
| CU02 Cerrar sesión | Base implementada | Cierre autenticado, invalidación de sesión y renovación de CSRF. V5 también lo clasifica «En revisión». |
| CU03 Gestionar usuarios y roles | Base implementada | Cuentas del personal, consulta, edición y estados; protección del último administrador. |
| CU04 Gestionar médicos | Base implementada | Datos, especialidades activas, consulta, estados y detección de citas afectadas. |
| CU05 Gestionar especialidades | Base implementada | Consulta, edición, estados y unicidad normalizada del nombre. |
| CU06 Gestionar pacientes | Base implementada | Consulta individual, búsqueda, registro activo, edición y cambios de estado independientes, con protección de duplicados e historial. |
| CU07 Gestionar horarios médicos | Parcial avanzado | Varios turnos sin superposición, duración, ausencias, protección inicial y porcentajes. Falta integrar retenciones y reflejar en el Word decisiones técnicas de borde. |
| CU08 Consultar disponibilidad | Parcial habilitado | Modalidad según rol, médicos y especialidades activos, citas, ausencias, bloqueos y aperturas. Faltan retenciones de pago y la integración definitiva de franjas. |
| CU09 Programar cita médica | Histórico deshabilitado | Falta solicitud, pago/exención verificados, ficha y consumo atómico de bloqueo compatible. |
| CU10 Reprogramar cita médica | Histórico deshabilitado | Falta adaptar aceptación del paciente, modalidades, retenciones, efectos del seguimiento y conservación económica. |
| CU11 Cancelar cita médica | Histórico deshabilitado | Falta cancelación alineada con causas, tiempos y vínculo posterior con CU20. El portal del paciente no debe cancelar. |
| CU12 Consultar agenda médica | Histórico deshabilitado | Hay consulta anterior por rol; falta adaptarla a los nuevos flujos de presencia y atención. |
| CU13 Registrar asistencia e inasistencia | Histórico deshabilitado | El código anterior no acredita la nueva transición presencia → Confirmada ni la política sin tolerancia fija. |
| CU14 Registrar cita como atendida | Histórico deshabilitado | Falta integrar finalización efectiva, estado previo y recomendación de seguimiento. |
| CU15 Consultar historial de citas | Histórico deshabilitado e incompleto para Paciente | Falta historial propio del paciente y actualización de relaciones económicas y seguimiento. |
| CU16 Generar reportes administrativos | Histórico deshabilitado | Existen siete reportes anteriores; el indicador de citas también bloquea su acceso. «Definido» en V5 no significa que estén habilitados. |
| CU17 Consultar citas afectadas | Parcial habilitado | Cubre las cinco causas y conserva estados. Faltan origen detallado, especialidad visible, selección de detalle y continuidad con CU10/CU11; hay permiso administrativo adicional. |
| CU18 Reservar cita online | Pendiente | No existe flujo de retención, intentos de pago y formalización online. |
| CU19 Verificar pago o exención de cita | Pendiente | No hay registro económico verificable, referencias idempotentes ni verificador de exenciones. |
| CU20 Solicitar reembolso | Pendiente | Faltan titularidad, cancelación previa, pago confirmado, admisión automática y rechazo motivado sin devolución. |
| CU21 Registrar cita de seguimiento | Pendiente | Falta recomendación, vínculo con consulta Atendida y validación de gratuidad antes de formalizar. |
| CU22 Consultar bitácora de auditoría | Base habilitada | Solo Administrador; filtros, paginación, detalle permitido e inmutabilidad. Falta auditoría específica de denegaciones exigida por los nuevos flujos y de futuros módulos económicos. |
| CU23 Registrar cuenta de paciente | Base implementada con detalles por cerrar | Registro/vinculación verificada, términos y retorno a inicio de sesión. Recuperación de contraseña y mecanismo operativo definitivo de verificación siguen pendientes. |
| CU24 Gestionar bloqueos provisionales | Parcial habilitado en «CUPOS» | Propone, confirma, bloquea, advierte y libera. Faltan consumo, selección parcial de la propuesta, persistencia explícita de la cantidad y pasos completos de liberación. |
| CU25 Habilitar anticipadamente cupos online | Parcial con diferencia expresa | La apertura funciona; la retirada de apertura expuesta actualmente no está autorizada por la V5. Faltan retenciones y auditoría de accesos denegados. |
| CU26 Resolver solicitud de reembolso | Pendiente | Faltan decisión, evidencia, estados de ejecución e impedimento de duplicaciones. |

No se calcula un porcentaje de cumplimiento: los casos tienen tamaños distintos y varios dependen de la misma infraestructura económica que todavía no existe.

## Diferencias que requieren corregir el programa

### Reversión de aperturas online

La delimitación de CU25 y la sección 4.6.5 dejan fuera la reversión automática y manual hasta validación de negocio. El programa sí muestra **Retirar apertura** en `resources/js/modules/agenda-adjustments.js`. La ruta de liberación llega a `AgendaAdjustments::release()`, que acepta cualquier movimiento activo sin restringir su tipo; al liberar una apertura, deja de aplicarse la excepción online.

**Corrección necesaria para ajustarse a V5:** ocultar esa acción para aperturas y rechazarla también en el servidor. La liberación de bloqueos presenciales de CU24 debe conservarse. No basta con cambiar la etiqueta del botón. La prueba anterior que verifica la retirada de apertura debe actualizarse para comprobar el rechazo exigido por la nueva delimitación. Esta corrección no se realizó en la presente revisión documental.

### Permisos y detalle de citas afectadas

CU17 identifica exclusivamente a Recepción como actor. `ClinicAccess::VIEWS` y `ScheduleImpactController` conceden acceso también al Administrador, aunque sin los datos de contacto del paciente. Esa consulta administrativa adicional no aparece autorizada como parte de CU17 en la V5.

**Corrección o aprobación expresa:** restringir CU17 a Recepción conforme a V5. Si se necesita mantener la advertencia de impacto al Administrador cuando cambia una configuración, documentarla como salida de CU04/CU05/CU07 y delimitar sus datos; no atribuirle por ello todo CU17.

La detección actual combina cambios de horario, ausencias y cambios de habilitación; agrupa las causas por cita y no modifica la cita. Sin embargo, devuelve motivos textuales sin una referencia estructurada al registro que originó cada afectación. La pantalla tampoco presenta la especialidad ni ofrece una selección de detalle con continuidad a CU10 o CU11.

**Trabajo pendiente:** asociar cada causa a su tipo e identificador de origen; mostrar especialidad y detalle autorizado; integrar las acciones de resolución cuando CU10/CU11 estén adaptados. La coordinación con el paciente seguirá siendo externa, como indica V5.

### Creación y liberación de bloqueos

CU24 paso 3 permite seleccionar las franjas deseadas de una propuesta. La interfaz actual confirma el conjunto propuesto completo; el servidor admite un conjunto de franjas, pero no hay selección parcial en pantalla. El paso 4 pide conservar la cantidad: hoy se usa para construir la propuesta y se informa el faltante, pero no se persiste explícitamente el conteo o estimación original como dato relacionado con el lote de demanda.

La liberación actual se realiza por registro con una sola acción. Faltan la selección de uno o varios bloqueos y el paso de confirmación de L2–L3; la consulta tampoco filtra los movimientos por especialidad. La advertencia de antigüedad y la ausencia de liberación automática sí están implementadas.

**Trabajo pendiente:** selección parcial, referencia de lote o registro de demanda con cantidad, consulta por especialidad y confirmación de liberación. El consumo deberá ocurrir exclusivamente en la misma transacción que formaliza CU09, tras pago/exención favorable. El modelo actual solo tiene estados `active` y `released`; no existe todavía `consumed` ni su relación con una cita.

### Auditoría de denegaciones

CU24 FA05 y CU25 FA04 piden denegar y registrar intentos sin permisos. Las rutas y Gates actuales deniegan el acceso, pero no se encontró registro de auditoría específico para esas denegaciones. La bitácora de modificaciones exitosas no cubre automáticamente un rechazo anterior al controlador.

**Trabajo pendiente:** registrar módulo, acción, responsable cuando exista y resultado denegado sin copiar cuerpos HTTP ni secretos. Incluir pruebas de denegación con evento de auditoría. Los futuros CU20 y CU26 también deberán aplicar sus excepciones de autorización auditadas.

### Retenciones y responsabilidades económicas

La consulta de horarios y los movimientos no comprueban retenciones de pago porque esa entidad y su flujo aún no están implementados. Las franjas se calculan dinámicamente y los movimientos guardan médico, fecha e intervalo; la V5 propone franjas persistentes y bloqueo de la fila de franja. Ambas estructuras no deben describirse como si fueran la misma implementación.

Antes de habilitar reservas, se necesita una identidad estable de franja y una comprobación transaccional compartida por citas, bloqueos y retenciones. El bloqueo actual de la fila de médico es parte de la base de concurrencia, pero no sustituye los contratos económicos pendientes.

CU19 deberá devolver un resultado de pago o exención; CU09, CU18 y CU21 serán quienes formalicen y emitan ficha. CU20 requiere cancelación previa realizada por Recepción y no cancela desde el portal. CU26 debe distinguir resolución administrativa de estado real de devolución, con evidencia verificable antes de marcar Completado. Estos módulos económicos no están implementados.

## Qué aclaraciones del informe anterior ya quedaron resueltas

| Pregunta anterior | Respuesta que aporta V5 | Estado de implementación |
| --- | --- | --- |
| ¿Quién ejecuta la cancelación? | Recepción mediante CU11. La cancelación autónoma del Paciente queda fuera del alcance vigente. | Pendiente adaptar el flujo histórico. |
| ¿Puede pedirse reembolso antes de cancelar? | No. CU20 exige cita previamente cancelada y pago confirmado. | Pendiente. |
| ¿Quién solicita y quién decide el reembolso? | Paciente/Recepción solicitan en CU20; Administrador decide solicitudes admitidas en CU26. | Pendiente. |
| ¿Una aprobación acredita dinero devuelto? | No. Se exige confirmación del proveedor o evidencia externa verificable. | Pendiente; procedimiento manual aún por validar. |
| ¿Se puede revertir una apertura online? | No está autorizado en la línea base actual; se mantiene como decisión futura. | El programa ofrece la acción y requiere corrección. |
| ¿CU19 formaliza la cita? | No. Solo verifica; formalizan CU09, CU18 o CU21. | Pendiente. |

Estas definiciones ya no deben presentarse como preguntas abiertas sobre el comportamiento vigente. Una ampliación futura requeriría actualizar el alcance correspondiente.

## Decisiones del programa que conviene incorporar al Word

1. **Alternancia cronológica.** El equipo confirmó alternar modalidades respetando los totales. Ya está implementado: inicia presencial si tiene cupos, alterna mientras ambas modalidades conservan cuota y después utiliza la restante. Sin embargo, 4.6.5 y 6.1.4 aún dejan el orden pendiente. Incorporar la decisión confirmada y un ejemplo; no volver a tratarla como una regla inexistente ni como aprobación formal de la clínica.
2. **Consultas que cruzan los 120 minutos.** La implementación protege íntegra toda franja cuyo inicio caiga dentro del período inicial. Con consultas de 90 minutos, una franja 09:30–11:00 de un turno iniciado a las 08:00 queda protegida. V5 habla de franjas dentro de las dos horas, sin resolver este borde. Confirmar y redactar el criterio antes de cerrarlo.
3. **Duración y remanentes.** El servidor admite 5–240 minutos y exige que la duración divida el turno sin sobrantes. El Word pide bloques compatibles, pero no define expresamente ese rango ni si se permite tiempo libre residual. Mantenerlo como decisión técnica por validar.
4. **Advertencia de bloqueos.** La aplicación usa 30 minutos; V5 exige advertir, sin fijar el valor. Añadir el umbral aprobado y cómo se revisa, manteniendo la liberación exclusivamente manual.
5. **Propuesta de demanda.** La búsqueda actual comprende la fecha elegida, médicos activos de la especialidad y un máximo de 100 franjas por operación. Ordena por inicio y, en empate, por identificador de médico. Documentar o sustituir estos parámetros según la operación de la clínica.
6. **Cambios con movimientos activos.** La edición de un turno se rechaza si invalida una franja con bloqueo o apertura activos. Dado que la V5 no autoriza retirar aperturas, hay que definir cómo corregirá Administración un turno con apertura online activa. No debe resolverse autorizando implícitamente la reversión ni desplazando compromisos.
7. **Cuentas y seguridad complementarias.** Siguen existiendo código de vinculación de 48 horas y uso único, contraseña mínima de diez caracteres, límites de intentos, perfil propio y revocación de sesiones ante cambios de credenciales o permisos. Conviene ubicarlos en CU01–CU03/CU23, requisitos de seguridad o subflujos aplicables, sin atribuirlos a funciones nuevas no acordadas.
8. **Persistencia real.** Reflejar qué partes de 6.1.4 ya existen y cuáles son propuestas. Hoy hay `schedules`, `doctor_quotas`, cálculo dinámico y `agenda_adjustments`; todavía no hay la integración propuesta de franjas, retenciones y pagos. Los nombres concretos en inglés pueden mantenerse si la trazabilidad explica su correspondencia conceptual.

Los porcentajes enteros ya constan en 6.1.4 y fueron confirmados por el equipo. No se mantienen como pregunta pendiente. Las configuraciones decimales antiguas se conservan sin redondearlas; las nuevas escrituras están restringidas a enteros.

## Observaciones de coherencia interna de V5

- **Estado de definición:** 4.3 clasifica CU03–CU06 y CU16 como Definidos, CU01–CU02 como En revisión y CU23 como Identificado, aunque este último tiene detalle en 4.6. La clasificación de CU01–CU02 difiere de la comunicación anterior del equipo que los daba por establecidos. Sincronizar la tabla con la decisión vigente; no confundir esta observación con una regresión del programa.
- **Consumo de bloqueos:** CU24 y CU09 sitúan el consumo en la formalización transaccional de una cita compatible. En 6.1.4 permanecen expresiones «Recepción libera o consume manualmente» y «su liberación o consumo corresponde a Recepción». Precisarlas para evitar una acción manual independiente que consuma un bloqueo sin cita y pago/exención favorable.
- **Orden de modalidades:** 4.6.5 y 6.1.4 aún lo declaran pendiente. Actualizarlas con la alternancia confirmada por el equipo.
- **Modelos visuales:** V5 advierte que los diagramas heredados de comunicación, clases y secuencia no están automáticamente corregidos. Revisarlos contra CU17, CU19 y CU24–CU26, incluyendo las tres situaciones de CU24. La advertencia es adecuada; el trabajo gráfico sigue pendiente.
- **Prototipos:** la nota de 4.7 reconoce que CU24–CU26 requieren revisión de interfaz. Al actualizar capturas, mostrar solo flujos vigentes: la retirada de apertura actual no puede representarse como una función aprobada de CU25.

## Preguntas que continúan pendientes

| Prioridad | Pregunta | Dependencia |
| --- | --- | --- |
| Alta | ¿La tarifa depende de médico, especialidad o ambos, cuál es la moneda y por cuánto tiempo se conserva el importe de una solicitud? | CU09 y CU19 |
| Alta | ¿Qué referencia y evidencia confirman un QR o transferencia presencial y quién los valida? | RN63–RN64 |
| Alta | ¿Qué documentación y capacidades verificadas ofrece WizPay en pruebas para estados, notificaciones, idempotencia y devoluciones? Las credenciales deben quedar en configuración privada. | CU18, CU19 y CU26 |
| Alta | ¿Cuánto puede durar una solicitud presencial pendiente de pago y cómo se concilia un pago recibido cuando el horario ya no puede formalizarse? | Reserva provisional y consistencia económica |
| Alta | ¿Quién puede autorizar exenciones distintas del seguimiento gratuito y con qué evidencia? | CU19 y CU21 |
| Alta | ¿Qué persona ejecuta una devolución externa y quién verifica su comprobante antes de marcarla Completada? | Decisión pendiente explícita de CU26 |
| Alta | ¿Los plazos de reembolso se evalúan con la fecha de cancelación, la de solicitud o una combinación? Si Recepción demora en cancelar, ¿cómo se conserva la evidencia de que el paciente pidió cancelar dentro del plazo? | CU11, CU20 y RN46–RN47 |
| Media | ¿Se confirma la protección de una consulta que cruza el límite de 120 minutos y el rechazo de turnos con minutos sobrantes? | CU07 y CU25 |
| Media | ¿Se aprueban 30 minutos de advertencia y bloqueos de demanda para días futuros, o deben limitarse a la jornada actual? | CU24 |
| Media | ¿Debe conservarse como dato separado si la demanda fue contada o estimada, además de su cantidad e historial de movimientos? | CU24 |
| Media | Ante una propuesta que queda parcialmente ocupada al confirmar, ¿se rechaza el lote completo o se permite confirmar solo las franjas aún libres con resultado explícito? La implementación actual revierte todo el lote. | CU24 FA02 y CU25 FA03 |
| Media | ¿Cómo se registra la aceptación del paciente al reprogramar o reasignar y cuántos seguimientos gratuitos puede generar una consulta? | CU10 y CU21 |
| Media | ¿La recuperación de contraseña se realizará mediante correo verificado o comprobación en Recepción? | Excepción de CU23 |

La posibilidad de revertir aperturas online es una decisión futura consignada por la V5. Hasta que se apruebe una ampliación, el comportamiento de referencia sigue siendo no ofrecer esa reversión.

## Orden recomendado de correcciones y desarrollo

1. Ajustar la reversión de aperturas en interfaz y servidor; actualizar su prueba de aceptación. Precisar la política de edición de turnos con aperturas activas.
2. Actualizar trazabilidad y navegación a CU24/CU25, delimitar el permiso de CU17 y registrar denegaciones. Los servicios comunes pueden seguir compartidos con responsabilidades explícitas.
3. Completar selección y liberación de CU24, cantidad de demanda, origen y detalle de afectaciones de CU17.
4. Definir franjas estables, retenciones y tarifas; implementar CU19 como verificador económico, separado de la formalización.
5. Integrar CU09 con pago/exención y consumo de bloqueo. Después adaptar CU10–CU14 y CU21.
6. Implementar CU20 y CU26 por separado con estados administrativos y económicos distintos; ampliar CU18 solo tras verificar la integración de WizPay.
7. Adaptar historial y reportes, completar prototipos y modelos UML y ejecutar pruebas de aceptación de la V5, revisión visual y concurrencia con varios procesos.

## Evidencia y límites de la verificación

La última ejecución de la entrega de código revisada aprobó **86 pruebas PHP con 1235 aserciones**, **6 pruebas JavaScript** y la compilación Vite. Las tres migraciones de esa entrega se aplicaron sin vaciar tablas. Esos resultados proceden de la entrega V3 realizada el mismo 7 de noviembre; **no se repitieron en esta revisión de documentación**.

Esas pruebas acreditan comportamientos concretos existentes, no conformidad total con V5. En particular, una prueba anterior acepta retirar aperturas, que ahora debe reemplazarse por una prueba de rechazo conforme a CU25. También harán falta pruebas de auditoría de denegaciones, actor de CU17, origen de afectaciones, selección de bloqueos, consumo con pago y separación de solicitud/resolución/devolución.

No se modificó el Word, no se ejecutaron migraciones nuevas y no se añadieron funciones en esta entrega. La revisión visual en navegador, la validación de imágenes UML y las pruebas de carga concurrente continúan pendientes. Los documentos fuente y las credenciales locales no deben incluirse automáticamente en la publicación del informe.
