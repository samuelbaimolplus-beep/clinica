# Recorridos de atención y cuentas opcionales

Actualización local del 8 de noviembre de 2026, conforme a las instrucciones posteriores del usuario. No se modificó `ofical.docx`, no se renumeraron CU y no se ejecutaron operaciones de Git.

## Regla central

La ficha de paciente y la cuenta de usuario son registros distintos. La administración de la clínica puede completar la atención de un paciente de cualquier edad sin crearle una cuenta ni exigirle correo electrónico. Los menores son pacientes comunes a efectos de citas, cobros, ingreso, consulta, historial, reembolso y seguimiento; no tienen acceso personal al portal.

Todas las cuentas requieren 18 años cumplidos, calculados con la fecha completa, no solo el año de nacimiento. La cuenta de paciente es opcional y requiere solicitud del adulto, identidad verificada presencialmente, aceptación expresa de los términos vigentes y activación por código de correo. Registrar una ficha no equivale a aceptar términos ni a solicitar una cuenta. El consentimiento conserva fecha, versión y trazabilidad del actor que lo registró.

## Cómo se conecta el trabajo

| Momento | Operación visible | Resultado y paso siguiente |
|---|---|---|
| Preparación | Inicio: especialidades y tarifas → médicos → horarios | Resumen real y accesos a los requisitos previos de una cita. |
| Registrar persona | Directorio de pacientes; alta en diálogo | Ficha ordinaria. El portal está desmarcado inicialmente y se deshabilita para menores. |
| Programar atención | Paciente → especialidad → médico/día/hora → confirmación | Se muestran disponibilidad, tarifa y resumen antes de guardar. La selección por sí sola no reserva ni cobra. |
| Falta una ficha | «Registrar paciente» desde la programación | Vuelve a la misma cita con el paciente nuevo seleccionado, sin empezar de nuevo. |
| Cobrar y emitir | Efectivo, QR o transferencia con verificación explícita | El servidor valida pago y cupo. Se muestra la ficha y el acceso a la agenda. |
| Día de atención | Tarjeta de cita: «Registrar llegada» | Cambia a espera; indica que continúa el médico. |
| Consulta | Médico responsable: «Finalizar consulta» | Requiere llegada y aptitud de atención del servidor; permite indicar seguimiento. |
| Seguimiento | Acción desde la consulta atendida | Preselecciona la consulta de origen; conserva la política de gratuidad verificada en servidor. |
| Cambio de horario | Acción sobre una cita existente | Conserva la cita original hasta acordar el cambio. La solicitud tiene estado y respuesta visibles. |
| Cancelación y devolución | Cancelar → solicitar evaluación → decidir → registrar entrega | Aprobar no marca el dinero como devuelto. La entrega externa requiere datos y confirmación propios. |
| Reserva opcional | Portal adulto y PayPal Sandbox | La ficha solo aparece tras confirmación del servidor. El retorno de PayPal consulta estado; los parámetros de URL no prueban un pago. |

La agenda presenta las citas como tarjetas con estado, siguiente paso y acciones por rol. Incluye búsqueda y navegación por fecha. Los formularios breves aparecen al iniciar una operación concreta. Reportes comienza por la información que se necesita consultar; auditoría muestra primero actividad y deja los filtros desplegables. Los cupos presenciales y online conservan propuesta/selección y confirmación: consultar disponibilidad no modifica cupos.

## Revisión de reglas y privacidad

| Área | Control conservado o incorporado | Comprobación |
|---|---|---|
| Edad | Alta de personal por API/consola, creación de cuenta de paciente, inicio, activación y sesión protegida | Límites del cumpleaños, fechas inválidas y cambio posterior de ficha a menor. |
| Portal opcional | No se crea cuenta automáticamente; consentimiento vigente obligatorio | Alta ordinaria de menor, rechazo de cuenta solicitada con DOB adulterada, rechazo sin consentimiento o con versión antigua. |
| Compatibilidad | No se fabrican fechas de nacimiento de usuarios antiguos | Personal sin fecha la completa antes de acceder; paciente sin fecha debe acudir a Recepción. |
| Alcance de datos | Agenda/resumen por paciente o médico autenticado | Consulta de otro paciente rechazada. Médico recibe datos asistenciales necesarios, sin datos de contacto o importes de pago. |
| Navegador compartido | Limpieza de referencias, borradores, filtros y diálogo al salir o perder sesión | Logout seguido de nuevo acceso no recupera el paciente seleccionado anteriormente. |
| Caché y respuesta | `private, no-store`, `nosniff`, protección de marcos y referencia del mismo origen | Respuestas normales y errores protegidos incluyen control de caché. |
| Horarios | Turnos existentes visibles, sugerencia de hueco y conflicto identificado | Turnos consecutivos permitidos; cruce rechazado; editar no compara el turno consigo mismo. Las reglas finales siguen en servidor. |
| Capacidad | Cupos, ausencias, superposición, bloques protegidos y liberaciones manuales | Conserva la suite de reglas y transiciones existente; no se reemplazó con validación solo visual. |
| Pagos | Idempotencia, Sandbox y conciliación en servidor | Seleccionar horario no cobra; URL de retorno no confirma; no se hicieron cobros reales. |
| Devoluciones y auditoría | Solicitud, decisión y entrega separadas; historial preservado | Pruebas de políticas y trazabilidad del servidor más recorrido de navegador de aprobación y entrega. |

La fecha declarada no sustituye una comprobación documental presencial. Los controles implementan las reglas de acceso del proyecto; esta revisión funcional no constituye una certificación de seguridad ni incluye una prueba de penetración externa.

## Validación ejecutada

- Regresión Laravel/PostgreSQL: **194 pruebas aprobadas, 2150 aserciones**, sobre `clinica_vida_salud_test`; sin reiniciar ni vaciar la base principal.
- Después del ajuste final de cabeceras y de la fecha límite en años bisiestos: **8 pruebas de elegibilidad y flujo aprobadas, 85 aserciones**.
- Lógica de interfaz: **15 pruebas aprobadas**.
- Edge/Playwright: **51 pruebas aprobadas**, incluyendo los módulos por rol, vistas móviles, horarios, alta de menor sin cuenta, cita, llegada, consulta, solicitudes, devolución y limpieza al salir.
- Compilación Vite final correcta. Sin dependencias nuevas.
- Cuatro cuentas ficticias comprobadas: activadas, elegibles y con las mismas contraseñas. La excepción sin correo se limita a la demostración local solicitada.

Las pruebas Feature ejecutan los endpoints y la persistencia reales en PostgreSQL de pruebas, con transacciones. En particular, se completó cita pagada → llegada → consulta → seguimiento para un menor sin correo ni usuario. Las pruebas de navegador usan la aplicación compilada y una API simulada identificada en el código; verifican presentación e interacción, no sustituyen las comprobaciones reales de persistencia. No se ejecutó una compra externa ni un nuevo webhook público durante esta revisión.

Registros técnicos locales: `storage/app/private/revision-integral-backend-final.xml`, `revision-integral-backend-final.log`, `elegibilidad-final.log` y `recorridos-browser-final.log`. Capturas auténticas del navegador, con datos ficticios y API simulada: `vista-superadmin.png`, `recorrido-cita-confirmacion.png`, `recorrido-agenda-llegada.png`, `recorrido-cita-360.png` y `recorrido-cita-1440.png`, en esa misma carpeta privada. Se inspeccionaron visualmente inicio, agenda y confirmación móvil.

## Ubicación del código

Se mantiene `app/Paquetes/P01…P08` y la asignación física de CU de la guía de defensa. La migración `2026_11_08_000060_add_account_birth_date` agrega `usuarios.fecha_nacimiento`, sin borrar datos. El SQL de creación incluye esa columna y el historial técnico actualizado.

- Acceso: `P01/Compartido/Servicios/AccountEligibility.php`, `SessionSecurity.php`, `EmailActivation.php` y los controladores/validadores de los CU correspondientes.
- Cuenta opcional: servicio `CreatePatientAccount` y reglas `ManagedAccountRules` en `P01/CU23_RegistrarCuentaDePaciente`. La ficha permanece en P03 y no exige cuenta.
- Resumen de jornada: `P05/Compartido/Http/WorkspaceController.php`, con alcance por identidad.
- Interfaz por responsabilidad: `workspace.js`, `booking-workflow.js`, `appointment-workspace.js`, `finance-workspace.js`, `contextual-tools.js`, `catalogs.js` y `schedules.js` en `resources/js/modules`. `clinical.js` reúne acciones compartidas y diálogos; se retiraron sus antiguas vistas reemplazadas para evitar duplicación.
- Protección de respuesta: `app/Http/Middleware/PrivacyHeaders.php` y finalización de excepciones en `bootstrap/app.php`.
- Escenarios nuevos: `tests/Feature/AccountEligibilityAndClinicalFlowTest.php`, `tests/Frontend/clinical-flow.test.mjs` y recorridos ampliados en `tests/Browser/role-workflows.spec.js`.

## Referencias de diseño revisadas

Se inspeccionaron las vistas de agenda, directorio y panel del ZIP local `CodigoAppWeb_2026-11-01_02-00-12_Bolivia.zip`, sin ejecutar ese proyecto. Se adoptó la idea de mostrar primero datos, estado y acciones contextuales.

La presentación progresiva se apoya en [NN/g: Progressive Disclosure](https://www.nngroup.com/articles/progressive-disclosure/); el tratamiento de fechas conocidas se contrastó con [GOV.UK: Date input](https://design-system.service.gov.uk/components/date-input/). Para permisos y operaciones con dinero se consultaron [OWASP: Authorization](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html) y [OWASP: Transaction Authorization](https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html). Estas referencias orientan las decisiones; no acreditan conformidad ni auditoría externa.
