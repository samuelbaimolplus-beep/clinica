# Adecuación del programa al Word V6

Fecha: 7 de noviembre de 2026.

La V6 se adopta como referencia funcional definitiva. Este registro describe correcciones efectivamente programadas y su verificación. Los acuerdos del Word se consideran decisiones de implementación, no preguntas por volver a validar ni propuestas de casos de uso adicionales.

Fuente: `Clinica_Vida_Salud_V6_26CU_Revision_Final.docx`, de 16 619 585 bytes. SHA-256: `D3EEFFC21374C819C71468449805B5CD79D0FFA6F2E2E4650F66F79B0F730D8D`. El archivo original se conserva sin modificaciones y permanece fuera del repositorio del programa.

## Correcciones realizadas

| Referencia V6 | Adecuación realizada | Comprobación |
| --- | --- | --- |
| CU25 y 4.6.5 | Retirada de «Retirar apertura» en pantalla; rechazo en servidor tanto por la operación individual como por la liberación en lote. | Una apertura no cambia de estado cuando se intenta liberarla. Un lote que incluye una apertura se revierte completo. |
| CU24 y RN37 | Creación, propuesta y liberación de bloqueos de demanda restringidas al día actual. El formulario ya no permite elegir una fecha futura. | Rechazo de fechas pasadas y futuras; validación también en el servicio. |
| CU24 | Selección de franjas dentro de la propuesta y liberación de uno o varios bloqueos con confirmación. | Validación transaccional de cada intervalo y rollback de lotes inválidos. |
| CU24 | Cantidad de demanda persistida junto con la referencia del lote, responsable y fechas. | La cantidad contada o estimada se conserva aunque se seleccionen menos franjas disponibles. |
| CU24 | Retirada del umbral de 30 minutos y de los campos de advertencia temporizada. Se conserva la liberación manual. | Un bloqueo sigue activo con el paso del tiempo. |
| CU07 | Se admite un remanente al final del turno. Solo se generan consultas completas de la duración configurada. Se retiró el límite arbitrario anterior de 5–240 minutos. | Turno 08:00–10:10 con consultas de 30 minutos produce cuatro bloques, terminando a las 10:00. |
| CU07, CU08 y RN36 | Se conserva el cálculo independiente por turno: 120 minutos protegidos, porcentajes enteros, 50/50 inicial, mayor resto, desempate presencial y alternancia. | Regresión de ejemplos, dos turnos diarios y límites de protección. |
| RN33 | Búsqueda por especialidad sin médico obligatorio; orden por menor carga de citas vigentes y, en empate, más cupos libres. La preferencia explícita limita la consulta al médico elegido, respetando disponibilidad. | Se prioriza al profesional menos cargado y la preferencia no devuelve horarios ocupados. |
| CU17 | Acceso limitado a Recepción. Especialidad visible y detalle de los registros que originan la afectación. | Administrador y Paciente reciben denegación; Recepción conserva datos necesarios para coordinar la solución. |
| CU24 FA05 y CU25 FA04 | Denegación auditada para usuarios sin permiso de Recepción. | Se registra usuario, módulo, acción y resultado sin copiar datos secretos del formulario. |
| CU23 y acuerdos definitivos | Verificación mediante enlace firmado por correo; el Paciente debe verificarlo antes de iniciar sesión. | Se rechaza el inicio sin verificación y un enlace sin firma. Verificar no inicia sesión automáticamente. |
| CU23 y recuperación por correo | Solicitud de recuperación con respuesta genérica, enlace temporal de un solo uso y restablecimiento de contraseña mediante el broker de Laravel. | Rechazo de enlace vencido o reutilizado; contraseña con hash; revocación de sesiones anteriores. |
| Límite de alcance del Word | Retirados «Mi perfil», edición propia de teléfono/dirección y cambio propio de contraseña, junto con sus rutas y componentes sin uso. | Las rutas retiradas devuelven 404. La recuperación por correo es el flujo de acceso definido en V6. |

La consulta administrativa de impacto al guardar médicos, especialidades u horarios se conserva dentro de sus operaciones de configuración. Eso no concede acceso al caso de uso CU17.

Los bloqueos futuros que pudieran proceder de la entrega anterior dejan de aplicarse a la disponibilidad futura: se conserva el registro histórico sin borrarlo ni simular una liberación por temporizador. Las aperturas online registradas se preservan; una edición de turno incompatible se rechaza con un mensaje que no instruye retirarlas.

## Verificación de correo y recuperación

La verificación utiliza un enlace firmado temporal relacionado con el correo actual del usuario. La recuperación solo restablece cuentas activas con correo verificado; las cuentas sin verificar reciben las instrucciones de verificación antes de poder recuperar. No se autentica automáticamente después de ninguna de esas operaciones.

Las contraseñas se almacenan mediante hash. Los tokens de recuperación se almacenan mediante el mecanismo de Laravel, se invalidan tras el uso y no se copian a la auditoría. La transacción bloquea el registro del token antes de comprobarlo y restablecer la contraseña. Un cambio administrativo de correo borra su verificación anterior.

Los plazos técnicos de enlace son de 60 minutos y se aplican límites de solicitudes para proteger el acceso. Son parámetros de seguridad de la implementación, no nuevas funciones de negocio.

**Entorno local:** `MAIL_MAILER=log` registra los mensajes en el log privado de Laravel. No hay entrega a una bandeja externa con esa configuración. El código utiliza el transporte de correo configurado en `.env`; la entrega SMTP real necesita la configuración de ese entorno. Las pruebas de esta entrega simulan las notificaciones y no acreditan entrega por un proveedor real.

## Persistencia y conservación de información

La migración `align_schedules_and_demand_with_v6` ajusta la validación de duración y agrega cantidad y referencia de lote a los movimientos de demanda. Se aplicó en la base de pruebas aislada y en la base local sin vaciar tablas. La restricción de exclusión de turnos y la protección de la bitácora permanecen vigentes.

Se conservan los datos e historiales existentes. La retirada de funciones elimina sus accesos y componentes, no usuarios, pacientes ni citas.

## Alcance comprobado de esta entrega

Las correcciones anteriores corresponden a los módulos ya disponibles. Esta entrega **no acredita que los 26 casos de uso estén totalmente implementados**. El código histórico de citas y reportes permanece deshabilitado por defecto. La formalización con pago inmediato, retenciones, WizPay, consumo de bloqueos con cita pagada, reembolsos, seguimiento y solicitudes de reprogramación con respuesta operativa por WhatsApp aún requieren implementación conforme a la V6.

Esos comportamientos ya están definidos en el Word: no se proponen reglas alternativas, límites nuevos de seguimientos gratuitos, pagos aplazados, verificación física de devoluciones ni casos de uso adicionales. Al continuar el desarrollo, corresponde programar los acuerdos tal como están escritos. La situación técnica del código debe distinguirse de una decisión funcional abierta.

## Validación

Resultado: **91 pruebas PHP aprobadas, 1196 aserciones y 6 pruebas JavaScript aprobadas**. La compilación Vite y el formato PHP finalizaron correctamente; `git diff --check` no detectó errores de espacios. El servidor local respondió HTTP 200. No se realizó revisión visual en navegador ni validación con un servicio SMTP o WizPay real.
