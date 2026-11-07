# Revisión funcional V6 y entrega del rol Superadmin

Fecha: 7 de noviembre de 2026.

## Alcance y resultado

La referencia funcional es `Clinica_Vida_Salud_V6_26CU_Revision_Final.docx`, situado en la carpeta superior. La V6 contiene 26 CU: conserva CU01–CU23 y separa CU24, CU25 y CU26. La revisión considera operaciones internas, alternativas, permisos y reglas; no equipara el número de pantallas con el cumplimiento completo.

Se mantuvieron los flujos clínicos existentes, se corrigieron las diferencias enumeradas abajo y se incorporó el Superadmin por solicitud expresa del propietario. El Word no se modificó. No se añadieron reservas ficticias, confirmaciones manuales de pagos online, bypass de verificación del paciente ni funcionalidades clínicas fuera del alcance.

Hay implementación local y pruebas automatizadas de los flujos descritos en la matriz adjunta. Esto no acredita un cobro real con WizPay, entrega real de correo, una prueba exhaustiva de todos los fallos posibles ni una puesta en producción.

## Cambios realizados en esta entrega

| Cambio | Motivo y comportamiento final |
| --- | --- |
| Rol Superadmin | Ampliación solicitada después de la auditoría. Puede gestionar Administración y Recepción y supervisar globalmente las áreas médica y del paciente. Tiene acceso a todos los módulos navegables. |
| Protección de las cuentas Superadmin | Administrador no puede crear, editar, desactivar ni obtener el detalle de estas cuentas. Solo Superadmin puede gestionar otras cuentas Superadmin. No puede desactivar su propia cuenta ni cambiar su propio rol. |
| Alta inicial local | Comando `clinica:crear-superadmin`, contraseña aleatoria con hash y acceso guardado únicamente en archivo privado. No reemplaza ni asciende cuentas existentes. La migración conserva los datos anteriores. |
| Navegación por rol | Menús agrupados por función y panel identificado por rol. El servidor conserva la autoridad sobre los permisos; ocultar botones no sustituye la autorización. Médico entra a su agenda; Paciente entra directamente a reservar online. |
| Acceso visible a CU18 | Paciente dispone de «Reservar cita online» como inicio y de un acceso adicional desde CU08. Superadmin ve la pantalla y las operaciones globales en modo de supervisión. |
| Disponibilidad real del pago | Si falta la clave sandbox, la pantalla informa la indisponibilidad y permite consultar horarios, pero no iniciar el cobro. No se crea una retención ni una ficha falsa. |
| Descartar solicitud online sin pago | Se completó CU18 FA02: el Paciente puede descartar una solicitud solo después de conciliar y verificar que no existen pagos confirmados, pendientes o desconocidos. Si el pago confirmó la cita, su cancelación corresponde a Recepción. Se añadió el estado persistente `cancelled`, distinto de una cita cancelada. |
| Supervisión de CU19 | Superadmin consulta pagos paginados y seguimientos con exención. Los cobros se verifican dentro de CU09/CU18/CU21; esta consulta no confirma pagos online manualmente. |
| Supervisión de CU23 | Superadmin consulta cuentas de pacientes, vinculación, estado, verificación de correo y aceptación de términos. El visitante mantiene el registro autónomo. |
| Aviso a Recepción en CU10 | Contador de solicitudes pendientes en la cabecera, aviso al detectar solicitudes y acceso a la bandeja. Consulta inicial, al navegar y cada 45 segundos mientras la página está visible. No requiere servicios externos. |
| Separación de responsabilidad en reembolsos | Un Superadmin puede solicitar y supervisar, pero no resolver su propia solicitud. La ampliación de permisos conserva esta restricción del flujo. |
| Agenda médica ajena | Una solicitud con `doctor_id` de otro profesional se rechaza con 403; anteriormente el servidor ignoraba ese filtro y devolvía la agenda propia. Se ajustó la prueba a CU12 FA08. |
| Historial de pacientes sin CI | Se corrigió la búsqueda SQL para que un CI nulo no elimine el nombre de la expresión buscada. El detalle muestra también la fecha de registro. |
| Filtros visibles de citas | Se exponen estado y médico en las consultas que los permiten; los pacientes siguen limitados a sus propios registros y el médico a su agenda. |
| REP01 por período | Además de fecha específica, admite rango de fechas y rechaza rangos inválidos o incompletos. Se agregó el selector correspondiente. |
| Gratuidad de seguimiento | Al formalizar un seguimiento en otra fecha/hora por causa de la clínica, se conserva la exención y se exige registrar motivo/acuerdo. Un cambio voluntario del paciente exige pago. Esto complementa la conservación de gratuidad ya existente en la reprogramación. |
| CU23: términos | El botón de creación permanece deshabilitado hasta aceptar las condiciones; se conserva la validación obligatoria del servidor. |
| Mensajes de disponibilidad | Se distingue la falta de médicos habilitados, de horario configurado y de cupos libres. No se indica que el usuario deba «crear registros» cuando simplemente no hay disponibilidad. |
| Bloqueos consumidos | CU24 muestra «Consumido por una cita» y no «Liberado» para un bloqueo consumido por CU09. |

## Qué ve y qué puede hacer cada usuario

| Rol | Vistas y alcance |
| --- | --- |
| Superadmin | Gestión de personal, médicos, especialidades, pacientes, horarios, citas y operación de Recepción; historial global, reportes, auditoría, pagos/exenciones, reembolsos, reservas online y cuentas de pacientes. CU14 y la reserva personal de CU18 se supervisan; no suplanta a un Médico ni a un Paciente. |
| Administrador | Personal interno, médicos, especialidades/tarifas, horarios/porcentajes, historial, reportes, auditoría y resolución de reembolsos. Sin gestión de Superadmin ni reserva personal online. |
| Recepcionista | Pacientes, disponibilidad presencial, programación/cobro presencial, reprogramación, cancelación, agenda, asistencia, historial, citas afectadas, solicitudes de reembolso, seguimientos y CU24/CU25. |
| Médico | Agenda propia, ausente al llamado, finalización de consultas propias y recomendación de seguimiento. |
| Paciente | Disponibilidad online, reserva/pago online, historial propio, solicitud de reprogramación y solicitud de reembolso. Registro y recuperación desde el acceso público. |

CU01 es la entrada pública de autenticación; CU02 muestra la sesión y permite cerrarla. CU19 es una función incluida en los flujos que formalizan citas: no necesita un formulario independiente para cada usuario ordinario. CU23 es registro público para el visitante y consulta de supervisión para Superadmin.

## Cómo revisar la aplicación

1. Abrir <http://127.0.0.1:8000> y recargar la página si estaba abierta antes de compilar.
2. Para la cuenta nueva, el usuario es `superadmin@vidasalud.bo`. La contraseña se encuentra en `storage/app/private/acceso-superadmin.txt`; no se reproduce en este informe ni se incluye en Git.
3. Superadmin → «Portal del paciente» → «Reservar cita online»: consulta horarios y supervisa las operaciones de todos los pacientes. Para hacer una reserva personal se utiliza una cuenta de Paciente activa con correo verificado.
4. Paciente → «Reservar cita online»: seleccionar especialidad, fecha, preferencia de médico y franja; consultar no reserva. Al confirmar se inicia la retención y el pago; solo la confirmación verificable del servidor produce la ficha.
5. Recepción → contador de solicitudes o «Reprogramaciones»: revisar, coordinar externamente, registrar aceptación y reprogramar, o rechazar con respuesta.

La apertura de las 25 vistas navegables con la cuenta Superadmin se comprobó también contra el servidor y la base locales reales, sin errores de API o JavaScript. Esta comprobación navegó consultas; no modificó citas ni realizó pagos.

## Evidencias de verificación

| Verificación | Resultado | Alcance y límites |
| --- | --- | --- |
| `php artisan test --compact` | 132 pruebas, 1.555 assertions, correctas | Servidor y PostgreSQL aislado `clinica_vida_salud_test`; operaciones transaccionales revertidas. Los escenarios de WizPay usan respuestas HTTP controladas. |
| `node --test tests/Frontend/api.test.mjs tests/Frontend/navigation.test.mjs` | 9 pruebas correctas | CSRF, sesiones, manejo de errores y navegación por rol. |
| `npm run test:browser` | 15 pruebas correctas | Edge real e interfaz compilada; API simulada explícitamente, sin datos personales ni cobros. Incluye cinco roles, formularios de asignación de roles, apertura de 25 vistas, reserva/pago/ficha/descarte, servicio indisponible, aviso a Recepción, términos y ojito de contraseña. |
| Revisión local sin simulación de API | Login Superadmin y 25 vistas correctos | Servidor en puerto 8000; lectura de los datos existentes. No acredita entrega de correo ni transacción bancaria. |
| Compilación Vite | Correcta | Recursos compilados en `public/build`, excluidos de Git. |
| Laravel Pint y `git diff --check` | Correctos | Formato PHP y revisión de espacios del cambio. |
| Migración Superadmin | Aplicada en la base local y de pruebas | Ampliación de restricción de roles, sin vaciar tablas. |
| Migración de descarte de solicitud | Aplicada en la base local y de pruebas | Amplía los estados válidos de la solicitud; conserva las restricciones de importes, intentos y exclusión de superposiciones. |

La [matriz funcional](MATRIZ_FUNCIONAL_V6_2026-11-07.md) identifica operaciones internas por CU y las clases de pruebas que aportan evidencia. Los identificadores `.1`, `.2`, etc. son referencias de revisión; no son nuevos CU oficiales. Una prueba puede abarcar varias operaciones; no se calcula un porcentaje de cobertura a partir del número de pruebas.

## Configuración externa que aún debe completarse para una operación real

| Dependencia | Situación comprobada | Qué falta |
| --- | --- | --- |
| WizPay sandbox | Adaptador, retenciones, intentos, consulta al proveedor, webhook y conciliación implementados. Claves locales ausentes. | Configurar `WIZPAY_TEST_API_KEY` y `WIZPAY_TEST_WEBHOOK_KEY`, registrar el receptor público HTTPS `/webhooks/wizpay` y verificar una transacción real sandbox. No son decisiones de negocio nuevas ni se reemplazan con claves inventadas. |
| Correo | Verificación y recuperación implementadas. Posteriormente se configuró SMTP Gmail con las credenciales facilitadas por el propietario y se comprobó conexión/autenticación correctas, sin enviar mensajes. | Verificar la recepción real de verificación y recuperación. La autenticación SMTP no acredita entrega ni llegada a una bandeja. |
| Conciliación periódica | Comando y programación cada minuto implementados. | Mantener el programador ejecutándose cuando se habiliten pagos. La navegación correcta no demuestra que exista un proceso permanente del programador. |

La cola local es `sync`: un job se ejecuta en el proceso que lo despacha. Si se configura una cola `database`, se necesita además un trabajador. No se exige un trabajador independiente para el modo `sync`.

Actualización de correo del 7 de noviembre: transporte SMTP, puerto 587 y STARTTLS, remitente Clínica Vida Salud. La contraseña queda únicamente en `.env`, excluido de Git. Se conservaron las sesiones y el almacenamiento propios de este proyecto; no se importaron nombres de otra aplicación. El informe no reproduce las credenciales.

WhatsApp continúa siendo coordinación externa realizada por Recepción, como indica el Word. No se agregó una integración de mensajería automática. La devolución externa se registra según CU26; aprobar no significa que se entregó dinero. No se implementó una devolución automática de proveedor que no esté acreditada por su integración.

## Qué corresponde incorporar al documento

La única ampliación funcional solicitada después de la V6 es el **Superadmin**. Conviene incorporarlo en actores, matriz de permisos, CU01/CU02, gestión de sus cuentas en CU03 y supervisión de los módulos. Debe expresarse que no puede resolver su propia solicitud de devolución, que no suplanta la cuenta del paciente y que la finalización médica sigue siendo del profesional responsable.

Las nuevas vistas globales de pagos/exenciones y cuentas son parte de esa supervisión autorizada. El aviso periódico de CU10, los campos de causa/motivo del seguimiento y las correcciones de filtros concretan requisitos existentes; no crean nuevos objetivos de negocio ni cambian la numeración de CU.

La documentación técnica puede recoger los archivos, rutas y mecanismo de avisos reales. Las notas históricas que describen el programa provisional anterior no deben usarse para afirmar que todavía faltan todas sus retenciones, pagos o citas. Tampoco deben presentarse como actualizados los diagramas que la propia V6 identifica como heredados.

No quedan preguntas de negocio nuevas para desarrollar esta entrega conforme a las reglas utilizadas. Sí quedan las configuraciones y verificaciones externas de la tabla anterior. No se certifica «100 % operativo en producción» mientras no se hayan realizado.

## Organización de los cambios

- `app/Support/ClinicAccess.php`: permisos de navegación y activación de perfiles.
- `app/Models/User.php`: herencia explícita de permisos del personal para Superadmin.
- `app/Http/Controllers/SupervisionController.php`: consultas globales y contador de Recepción, con autorización del servidor.
- `app/Console/Commands/CreateClinicSuperadmin.php`: creación inicial protegida, sin contraseñas fijas.
- `database/migrations/2026_11_07_000020_add_superadmin_role.php`: integridad PostgreSQL del nuevo rol.
- `resources/js/lib/navigation.js`: grupos de menú e inicio por perfil.
- `resources/js/modules/reception-inbox.js`: avisos de solicitudes pendientes.
- `resources/js/modules/supervision.js`: supervisión de CU19 y CU23.
- Módulos y servicios existentes: correcciones de CU08/CU10/CU12/CU15/CU16/CU21/CU23/CU24 y permisos administrativos.
- `tests/Feature/SuperadminAccessTest.php`, pruebas clínicas, `tests/Frontend/navigation.test.mjs` y `tests/Browser/role-workflows.spec.js`: evidencia reproducible.

Los accesos privados, capturas locales, resultados de navegador, dependencias instaladas y recursos compilados permanecen fuera de Git. Al instalar esta entrega en otro entorno, se deben aplicar sus migraciones, compilar los recursos y configurar allí las credenciales y las cuentas iniciales.
