# Revisión del Word actualizado y diferencias con la implementación

**Correcciones implementadas posteriormente:** el [ajuste de código a CU01–CU05](AJUSTE_CU01_A_CU05_2026-11-04.md) registra la nueva lectura del Word actualizado, las diferencias corregidas y sus pruebas. Las brechas de autenticación y cierre descritas más abajo corresponden a la revisión histórica y ya se atendieron en ese ajuste.

**Actualización posterior del equipo:** CU01–CU05 están establecidos; CU06–CU23 siguen en revisión. Esta indicación sustituye el estado anterior de aprobación mencionado en el informe. El contraste documental de CU01–CU02 que sigue conserva su carácter histórico; no se ha realizado aquí una nueva revisión del detalle de CU03–CU05.

Se revisó `../Nuevo parcial angelica.docx` el 4 de noviembre de 2026, contrastando sus requisitos, reglas y especificaciones de CU01–CU02 con el código existente. Según la indicación del equipo, CU01 y CU02 constituyen la referencia ya estructurada; el detalle de los demás casos continúa en elaboración. Tener código o una descripción anterior no equivale a que esos casos estén aprobados.

Esta revisión actualiza las conclusiones de los informes del 2 y 3 de noviembre. No modifica el Word, las funciones de la aplicación ni la base de datos. El ZIP sigue siendo únicamente una referencia.

Identificación de la copia revisada: 15 963 216 bytes; SHA-256 `2667AF90DA8B29ED06DE38B4D02D0ED1274885BF38D131FE6C7222A43F04A4C1`. Se utilizó el contenido textual actual, sin cambios controlados; las ubicaciones siguientes corresponden a secciones y códigos, no a páginas verificadas mediante renderizado. No se evaluaron los diagramas incrustados ni la presentación visual del Word.

## Ampliaciones que ya están documentadas

| Referencia del Word | Contenido vigente | Consecuencia para el proyecto |
| --- | --- | --- |
| 4.2 y 4.5.1 | Se identifican 23 casos de uso y cuatro actores, incluido Paciente | La cuenta del paciente ya forma parte del alcance escrito |
| 4.6.1.1 y 4.6.1.2; RF01–RF02 | CU01 y CU02 incluyen Administrador, Recepcionista, Médico y Paciente | La ampliación a cuatro roles ya no debe presentarse como una incorporación ajena al Word |
| RF28; 4.2.5; 4.3 | CU23 Registrar cuenta de paciente, con datos, aceptación de términos y prevención de duplicados; estado Identificado | CU23 ya está identificado formalmente, aunque todavía no tiene su especificación detallada propia en 4.6 |
| RF07–RF09, RF18–RF19; RN35–RN45, RN63–RN64 | Cupos por modalidad, agenda compartida y formalización condicionada al pago o exención | El código anterior de citas no cumple por sí solo este alcance ampliado |
| RF20; RN48 y RN50 | Rechazo automático de reembolsos fuera de plazo y paso al Administrador solo de solicitudes admitidas | La futura gestión de reembolsos requiere una validación previa del sistema |
| RF08 y RF15 | Paciente puede consultar disponibilidad y exclusivamente su historial | Son funciones previstas, todavía pendientes de la integración correspondiente |
| RNF06–RNF08, RNF20–RNF21 | Seguridad para todos los roles, integridad de auditoría y protección de datos personales | Respaldan medidas técnicas de seguridad y privacidad; no definen por sí solos cada pantalla o parámetro añadido |
| RF22; RN59–RN62 | Auditoría automática y consulta administrativa; referencias de RN60–RN61 a CU22 | Se corrige la observación anterior sobre referencias de auditoría a CU18 |
| 4.4.1–4.4.4 | CU19 comienza en Ciclo I para pagos presenciales; CU20–CU21 en II, CU22 en III y CU23/CU18 en IV | La planificación ya no deja todos los pagos para el ciclo online; se mantiene auditoría desde las primeras operaciones |

## Contraste de CU01 y CU02 con el código

La revisión de código muestra coincidencia en el flujo habitual: búsqueda de la cuenta por correo utilizado como identificador, comprobación de contraseña mediante hash, estado activo, rol y permisos, regeneración de sesión y navegación autorizada. El cierre invalida la sesión y regenera CSRF; las rutas protegidas exigen autenticación.

| Punto del caso de uso | Implementación / evidencia existente | Situación |
| --- | --- | --- |
| CU01: cuatro actores y permisos en servidor | `AuthController`, `ClinicAccess`, `ClinicSession`, Gates y Form Requests | Alineado en el flujo habitual; las pruebas anteriores cubren acceso, permisos e inactividad |
| CU01 FA01–FA03: campos, credenciales e inactividad | Validación del formulario, `Hash::check`, `ClinicAccess::isActive` | Implementado; mantiene mensajes de credenciales inválidas sin distinguir usuario inexistente de contraseña incorrecta |
| CU01: precondición sin sesión autenticada previa | La interfaz muestra login solo al visitante; `POST /api/login` no rechaza expresamente una sesión ya autenticada | Diferencia concreta que debe cerrarse al ajustar CU01; no agregar un nuevo flujo de cambio de cuenta implícitamente |
| CU01 FA04: fallo al crear la sesión, permanecer sin autenticar | Login, regeneración y auditoría se ejecutan en secuencia; no hay prueba de fallo inducido en esa secuencia | No declarar este flujo verificado. Debe probarse que un fallo posterior a `Auth::login` no conserve una sesión autenticada |
| CU02: invalidación y acceso posterior denegado | `AuthController::logout`, middleware y cliente HTTP | Implementado; las pruebas anteriores comprueban logout y acceso protegido |
| CU02 FA01: sesión ya caducada | Cliente HTTP gestiona 401/419, renueva CSRF y comunica la necesidad de autenticarse | Existe cobertura del cliente; falta cerrar la prueba del flujo completo de cierre ya caducado |
| CU02 FA02: error de cierre sin confirmar un éxito falso | El cliente muestra éxito solo después de una respuesta correcta | Requiere prueba específica de fallo del servidor durante el cierre; el caso no debe considerarse validado solo por el flujo habitual |

Este contraste es una revisión estática apoyada en las pruebas ya existentes, no una nueva certificación de conformidad de todos los flujos. No se ejecutaron pruebas adicionales al tratarse de una revisión documental.

## Informe de elementos añadidos o concretados fuera del detalle del Word

Se distinguen funciones nuevas, decisiones de negocio todavía no especificadas y mecanismos técnicos que realizan requisitos ya escritos.

| Elemento implementado | Clasificación | Qué falta documentar o confirmar |
| --- | --- | --- |
| Mi perfil para los cuatro roles y cambio de contraseña propia | Ampliación funcional no descrita expresamente en CU01–CU02 | Ubicar la función en un caso de uso o subflujo: contraseña actual, nueva, confirmación, errores y efecto sobre sesiones |
| Edición por el paciente de teléfono y dirección | Concreción funcional de RN12, que autoriza gestionar información propia en términos generales | Precisar campos editables, campos verificados por recepción y responsabilidad sobre correo de acceso |
| Código para vincular un paciente ya registrado, emitido por recepción tras verificación presencial | Flujo adicional de negocio para cumplir la prevención de duplicados de RF28/RN01 | Especificar en CU23 cómo se verifica identidad y si esta es la vía aprobada para vincular registros existentes |
| Código de 48 caracteres, un uso, expiración de 48 horas y revocación al emitir otro | Parámetros y comportamiento adicionales | Confirmar vigencia y reglas de uso en CU23; la longitud y el hash pueden quedar en diseño técnico |
| Rechazo sin CI por coincidencia exacta de nombres, apellidos, nacimiento y teléfono | Criterio de duplicidad elegido en la implementación | Definirlo o sustituirlo en CU06/CU23; no es una regla clínica ya fijada en el Word y puede requerir revisión de coincidencias legítimas |
| Contraseña de al menos 10 caracteres y límites de intentos de acceso/cambio | Parámetros técnicos con efecto visible | Documentar la política concreta; RNF06 no fija esas cantidades |
| Revocación de sesiones ante cambio de contraseña, rol o vínculo | Mecanismo de seguridad y comportamiento complementario | Vincularlo a RNF08 y describir que opera en el siguiente acceso; la sesión que cambia su propia contraseña se conserva |
| Firma HMAC de sesión, CSRF, hash de códigos y transacciones | Detalles técnicos de requisitos existentes | Reflejarlos en diseño/implementación y pruebas; no requieren inventar casos de uso por cada mecanismo |
| Eventos automáticos y trigger de inmutabilidad de auditoría | Implementación técnica de RF22 y RN59–RN62/RNF20 | Mantener trazabilidad con CU22; no confundir infraestructura existente con pantalla de consulta terminada |
| Una sola franja por médico y día; duración entre 5 y 240 minutos | Limitación y parámetros de la versión básica de CU07 | No presentarlos como restricciones definitivas de la clínica. El detalle de CU07 debe fijar franjas y duraciones admitidas |
| Texto y versión de condiciones de la cuenta | Contenido concreto añadido para una aceptación ya exigida en RF28 | Revisar el texto aplicable y el cambio de versiones al detallar CU23 |

El registro de paciente, la aceptación de términos y los cuatro roles ya tienen respaldo explícito en la nueva versión. Lo que sigue pendiente de definición son sus detalles y las ampliaciones concretas de la tabla, no la existencia general de esas funciones.

## Apartados del Word que todavía requieren sincronización

1. En **4.3**, CU01 y CU02 figuran En revisión, mientras CU03–CU06 y CU16 figuran Definido. Esto no refleja la indicación actual del equipo de que están estructurados hasta CU02. Conviene actualizar la tabla para evitar confundir texto heredado con aprobación vigente.
2. En **4.6.1.6 CU06**, permanece la afirmación de que los pacientes no tienen cuentas propias ni gestionan citas directamente. Es incompatible con RF01, RF18, RF28, CU18 y CU23. CU06 puede seguir siendo la gestión por recepción sin negar la existencia del portal del paciente.
3. En **4.6.1.6 CU06**, algunos pasos piden CI como obligatorio, mientras RN01 lo trata como único cuando se proporciona. Definir el comportamiento sin documento y los criterios para evitar duplicados.
4. En **4.6**, CU07–CU22 conservan detalle que debe conciliarse con los RF/RN ampliados. CU08 y CU15, por ejemplo, deben incorporar el alcance del Paciente establecido en RF08 y RF15. No se toman como especificaciones cerradas.
5. **CU23** aparece en identificación, priorización, ciclos y RF28, pero todavía falta su detalle en 4.6. Se debe completar cuando el equipo avance a ese caso.
6. En **5.1**, partes de la organización por paquetes todavía hablan de 17 casos y tres actores internos. Sincronizar paquetes y modelos con los 23 casos identificados y el cuarto actor, cuando corresponda a la revisión de análisis.

## Criterio de trabajo a partir de esta revisión

Se usa CU01–CU02 como base estructurada por indicación del equipo. Los RF, RNF y RN ampliados sirven para orientar la arquitectura y advertir dependencias, pero no convierten el detalle pendiente de CU03–CU23 en una especificación final.

Las funciones ya implementadas de esos módulos se conservan como avances sujetos a ajuste. Las decisiones no documentadas se identifican en este informe y no se trasladan al Word como si ya hubieran sido aprobadas. Reservas, pagos y sus dependencias permanecen pendientes de implementación integrada.

El siguiente ajuste de programación debe centrarse en las diferencias concretas de CU01–CU02 y sus pruebas de excepción. No se programaron ni se habilitaron nuevas funciones como consecuencia de esta revisión.
