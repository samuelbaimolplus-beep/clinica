# Clínica Vida Salud

El funcionamiento vigente se describe en [Recorridos de atención y cuentas opcionales](docs/REVISION_RECORRIDOS_Y_ACCESO_2026-11-08.md). La clínica trabaja con fichas de pacientes de cualquier edad, sin exigir correo ni cuenta. El portal es opcional para pacientes con 18 años cumplidos que lo solicitan y aceptan los términos; todas las cuentas de personal también requieren mayoría de edad.

La estructura física de PostgreSQL está en español: tablas, columnas, restricciones, índices y secuencias. El [script de creación](../Clinica_BD.sql) y el [diagrama UML](../Clinica_UML.svg) corresponden al esquema actualizado. La migración de nombres conserva los datos; la conexión de Laravel adapta sus convenciones internas al esquema en español.

La migración `2026_11_07_000040_add_clinic_triggers_and_procedures` incorpora seis triggers: normalización de correos en tres entidades, validación de franjas en citas y reservas y protección de franjas ya utilizadas. Conserva el disparador de auditoría inmutable. La aplicación utiliza los procedimientos `registrar_auditoria` y `registrar_evento_cita`; `configurar_cupos_medico` permite configurar porcentajes enteros por médico con validación de actor administrativo, bloqueo de reservas retenidas y auditoría atómica. El SQL de entrega contiene las definiciones y ejemplos de `CALL`. Son rutinas con permisos del invocador; el parámetro de actor no sustituye la autorización de la aplicación. Las 26 entidades y 40 relaciones del UML no cambian.

Sistema académico en Laravel 13, PostgreSQL, Blade y JavaScript. La referencia funcional disponible y revisada actualmente es `../Clinica_Vida_Salud_V6_Integrada_Limpia.docx`, del 7 de noviembre de 2026, con 26 casos de uso. La [revisión final de código y Word](docs/REVISION_FINAL_CODIGO_Y_WORD_2026-11-07.md) identifica correcciones, correspondencia por CU, actualizaciones del documento y verificaciones externas pendientes.

El [informe de implementación integrada V6](docs/IMPLEMENTACION_INTEGRADA_V6_2026-11-07.md) documenta los flujos programados, su trazabilidad y la configuración externa necesaria. El [registro previo de adecuación](docs/ADECUACION_WORD_V6_2026-11-07.md), los informes V3 y V5 y los demás documentos anteriores conservan valor histórico; sus preguntas y propuestas no sustituyen los acuerdos finales de V6.

## Ejecutar el proyecto

La entrega más reciente está descrita en el [informe de revisión funcional y Superadmin](docs/REVISION_FUNCIONAL_Y_SUPERADMIN_2026-11-07.md), con una [matriz de operaciones internas de los 26 CU](docs/MATRIZ_FUNCIONAL_V6_2026-11-07.md). Incluye navegación por rol, supervisión global y las diferencias corregidas después de la auditoría.

Con PostgreSQL iniciado y `.env` configurado, desde esta carpeta:

```powershell
php artisan migrate
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

Abrir <http://127.0.0.1:8000>. Las migraciones agregan o ajustan estructuras sin vaciar las tablas. No ejecutar `migrate:fresh` sobre la base del proyecto.

Se necesitan PHP 8.3 o superior con `pdo_pgsql`, Composer, Node.js y PostgreSQL. En una instalación nueva, ejecutar `composer install` y `npm.cmd ci`, copiar `.env.example` a `.env`, configurar una base existente y ejecutar `php artisan key:generate`. Después aplicar migraciones, compilar y crear el administrador con `php artisan clinica:crear-admin correo@ejemplo.bo --nacimiento=AAAA-MM-DD`, indicando su fecha real de nacimiento.

Los accesos iniciales y de demostración permanecen en archivos privados locales de `storage/app/private`, excluidos de Git. La aplicación no publica contraseñas.

## Funciones disponibles

La interfaz utiliza nombres de tareas y direcciones legibles, sin mostrar los códigos de casos de uso. El menú filtra las opciones por rol, incluye búsqueda sin distinción de acentos y permite volver con los botones del navegador. Los enlaces antiguos siguen funcionando. El acceso a «Mi cuenta y seguridad» está disponible desde el perfil.

En móviles y tabletas el menú se abre sobre el contenido; se cierra al elegir una opción, tocar el fondo o pulsar Escape. Los formularios pasan a una columna y los listados se presentan como tarjetas en pantallas pequeñas. Se conserva la navegación por teclado, el enfoque visible, los estados de carga y la señalización de errores. La selección de un horario muestra un resumen antes de continuar al pago.

- Superadmin: operaciones administrativas, de Recepción y gestión clínica, incluida finalización de consultas, con su identidad registrada en auditoría y conservando el médico responsable de la cita. La reserva personal del portal corresponde al titular; Superadmin programa citas de cualquier paciente por el flujo administrativo. Solo Superadmin puede gestionar otras cuentas Superadmin.
- Administrador: personal, pacientes y creación de sus cuentas, médicos, especialidades y tarifas, horarios y porcentajes, historial, siete reportes, auditoría y resolución de reembolsos.
- Recepción: pacientes y creación presencial de cuentas, disponibilidad, cobro presencial y ficha, reprogramación acordada, cancelación, asistencia, solicitudes de cambio y devolución, seguimiento, citas afectadas y operación de bloques CU24/CU25.
- Médico: agenda propia, ausencia al llamado, finalización de consulta y recomendación de seguimiento.
- Paciente: activación de la cuenta creada en la clínica, gestión de sus credenciales y datos de contacto, recuperación de acceso, disponibilidad, reserva y pago online de pruebas, historial propio, solicitud de reprogramación y solicitud de reembolso de citas canceladas por Recepción.
- Acceso: correo activado obligatorio para los cinco roles, sesión por rol, estado activo y revocación ante cambios de credenciales o permisos.

Los turnos protegen los primeros 120 minutos para presencial. El resto usa porcentajes enteros por médico, 50/50 inicial, mayor resto y desempate presencial, alternando modalidades mientras ambas conservan cupos. Los minutos sobrantes al final del turno quedan sin consulta; no se generan citas más cortas.

Los bloqueos de demanda se crean y liberan únicamente hoy, mediante selección y confirmación. Se conserva la cantidad de demanda registrada. No hay caducidad automática ni temporizador de advertencia. La habilitación online no admite retirada. En «Mi sesión» cada cuenta puede cambiar su usuario y contraseña y actualizar su correo; el paciente también puede actualizar su teléfono y dirección. El cambio de correo exige un código nuevo antes de volver a acceder. Los datos de identidad y los permisos de otras personas se gestionan por personal autorizado.

## Superadmin

Para crear la primera cuenta en una instalación nueva: `php artisan clinica:crear-superadmin correo@ejemplo.bo --nacimiento=AAAA-MM-DD`, indicando su fecha real de nacimiento. El comando no reemplaza cuentas existentes y guarda el acceso aleatorio solo en `storage/app/private/acceso-superadmin.txt`, excluido de Git. Administrador no puede crear ni editar Superadmin.

Se puede iniciar sesión con el nombre de usuario o el correo. Recepción, Administrador y Superadmin pueden registrar fichas sin cuenta ni correo. La opción de crear una cuenta está desmarcada inicialmente: solo se habilita para adultos que la solicitan, con identidad verificada y aceptación expresa de la versión vigente de términos. También se puede habilitar el portal desde una ficha adulta existente. «Usuarios y roles» incluye un acceso al directorio de pacientes. Superadmin puede abrir «Consultar todas las cuentas» para cambiar contraseñas del personal y de los pacientes, sin consultar las anteriores. Los documentos previos conservan valor histórico; el alta presencial y la activación por código se aplican a las cuentas opcionales, no a la atención clínica.

Cada cuenta puede elegir o cambiar su propio usuario en **Mi sesión → Elegir mi usuario de acceso**. Debe confirmar su contraseña actual y elegir un usuario disponible; conserva su contraseña, correo y permisos.

## Correo

No existe registro público ni generación de códigos de vinculación. Las cuentas se crean por personal autorizado en la clínica; quedan pendientes hasta confirmar un código de ocho dígitos enviado a su correo. Esta regla se aplica a Superadmin, Administrador, Recepcionista, Médico y Paciente, tanto al iniciar sesión como al acceder a cualquier API protegida. Las cuentas iniciales creadas por consola también deben activarse. La aceptación de condiciones del paciente no se inventa al crear su cuenta.

El código vence en 15 minutos, admite como máximo cinco intentos y es de un solo uso. Los reenvíos tienen una espera mínima de 60 segundos y un máximo de tres envíos por cuenta cada 15 minutos; el nuevo código invalida al anterior. Se guarda únicamente una huella HMAC vinculada a la cuenta y al correo en la caché persistente configurada. La activación no inicia sesión automáticamente. La recuperación de contraseña conserva su enlace temporal de un solo uso. Cambiar contraseña o usuario revoca las otras sesiones y conserva la sesión que confirmó la contraseña actual.

El entorno local está configurado con SMTP de Gmail y STARTTLS en el puerto 587, con el remitente Clínica Vida Salud. Se comprobó la conexión y autenticación SMTP; esta comprobación no envió un mensaje ni acredita su recepción. Las credenciales permanecen únicamente en `.env`, excluido de Git. La plantilla `.env.example` conserva `MAIL_MAILER=log` para instalaciones nuevas sin credenciales. Las pruebas automatizadas simulan las notificaciones y no envían correos externos. No copiar enlaces de recuperación ni credenciales a los informes o al repositorio.

## Pagos online y conciliación

Los módulos clínicos se habilitan con `CLINIC_APPOINTMENTS_ENABLED=true` (valor inicial de esta entrega). Antes de emitir fichas con cobro, Administración registra la tarifa en la especialidad. No se asignan tarifas ni pagos ficticios a registros preexistentes.

PayPal utiliza exclusivamente Sandbox y dinero de prueba. Configurar `PAYPAL_ENABLED`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET` y `PAYPAL_BOB_PER_USD` en `.env`. La tasa local es 10 Bs por USD de prueba; cada intento conserva su importe USD y tasa aunque cambie la tasa configurada. El paciente aprueba en PayPal y el servidor captura la orden: solo una captura `COMPLETED` con importe, moneda y referencia coincidentes emite la ficha.

El webhook se registra en la misma app Sandbox: configurar `APP_URL` con la URL HTTPS pública y guardar su `PAYPAL_WEBHOOK_ID`. El receptor es `/pagos/paypal/webhook` y verifica la firma mediante la API de PayPal. La configuración local ya registra el túnel ngrok; debe mantenerse activo para recibir eventos. Los retornos de PayPal usan la URL pública configurada, incluso detrás del túnel. El retorno, la consulta de estado y el programador permiten conciliar también sin webhook. Mantener en ejecución:

```powershell
php artisan schedule:work
```

El webhook encola la conciliación. Como respaldo, el programador revisa operaciones pendientes con espera creciente; también puede ejecutarse `php artisan clinica:conciliar-pagos`. Una respuesta del navegador nunca confirma un pago. Una transacción pendiente o desconocida conserva su retención hasta ser conciliada, incluso si vence el reloj local. La aprobación de un reembolso no marca la devolución como completada: se registra después la ejecución externa declarada conforme a CU26.

Las credenciales locales fueron aceptadas mediante OAuth en Sandbox. Las pruebas automatizadas simulan las órdenes, capturas y webhooks; queda pendiente probar la compra completa con una cuenta compradora Sandbox. Las credenciales permanecen solo en `.env`, excluido de Git.

Se comprobó la entrega de un evento generado por la API del simulador de PayPal al receptor HTTPS, con firma válida y respuesta HTTP 200. Una orden inexistente en un evento firmado se acusa como recibida e ignorada; no genera pagos ni citas. Los errores temporales del proveedor conservan una respuesta de error para permitir reintentos. Esta prueba de entrega no equivale a completar una compra Sandbox.

La cola local usa `QUEUE_CONNECTION=sync`: el job se ejecuta en el proceso que lo despacha. Si se cambia a `database`, mantener también `php artisan queue:work`, además del programador.

## Organización del código

La estructura del backend sigue P01–P08 de `../ofical.docx`. Comience por [la guía de defensa del código](docs/GUIA_DEFENSA_CODIGO.md) y el [índice de paquetes](app/Paquetes/README.md). El [mapa de los 26 CU](docs/MAPA_CU_PAQUETES.csv) indica archivos, métodos y rutas.

- `app/Paquetes`: entradas HTTP, validaciones y servicios organizados por paquete y responsabilidad de CU.
- `app/Compartido`: operaciones de catálogo y referencias usadas por varios paquetes.
- `app/Models`: entidades Eloquent y relaciones compartidas.
- `app/Http/Middleware`, `app/Providers`, `app/Support`: sesión, permisos y utilidades transversales.
- `app/Database`, `database/migrations`: esquema en español, restricciones y rutinas.
- `app/Observers`, `app/Jobs`, `app/Notifications`, `app/Console`: infraestructura de Laravel.
- `resources`: interfaz, con sus ubicaciones actuales.

Las consultas de agenda/historial y las transiciones de citas se comparten. La organización evita crear un archivo de reenvío por CU.

## Verificación

Las pruebas usan exclusivamente `clinica_vida_salud_test`, mediante transacciones revertidas. `clinica:preparar-pruebas` aplica las migraciones pendientes de esa base aislada sin vaciar datos.

```powershell
php artisan clinica:preparar-pruebas
php artisan test --compact
node --test tests/Frontend/api.test.mjs
npm.cmd run build
php vendor/bin/pint app/Paquetes app/Compartido routes/web.php --test
```

El resultado de la entrega figura en el [registro de adecuación](docs/ADECUACION_WORD_V6_2026-11-07.md). La verificación visual y las pruebas reales de correo y pasarela no se presentan como realizadas.

Las pruebas de navegador se ejecutan tras compilar mediante `npm.cmd run test:browser`. Usan Edge instalado en Windows y API simulada; no hacen pagos ni alteran pacientes. En otros sistemas se necesita instalar Chromium de Playwright. Las pruebas Feature comprueban el servidor y PostgreSQL.
