# Uso de la guía ZIP y mejoras de cuentas

Actualización del 4 de noviembre de 2026: la [revisión del Word vigente](REVISION_WORD_2026-11-04.md) aclara qué funciones ya están respaldadas por los RF/RNF/RN ampliados y cuáles siguen siendo decisiones adicionales. CU23 ya está identificado con RF28; el cambio de contraseña propio y el flujo de vinculación por código siguen sin detalle explícito en el Word. Este informe describe su implementación y no constituye su aprobación funcional.

El archivo `CodigoAppWeb_2026-11-01_02-00-12_Bolivia.zip` se consultó como referencia de otra aplicación, La Petdiatría. No reemplaza los casos de uso, las reglas de negocio ni la arquitectura de Clínica Vida Salud. Se leyeron archivos concretos dentro del ZIP sin extraer ni ejecutar su aplicación.

## Qué se adaptó

| Idea de la referencia | Implementación propia | Justificación en el proyecto |
| --- | --- | --- |
| Perfil con cambio de contraseña | `PasswordController`, `ChangePasswordRequest`, `Services/Auth/ChangePassword` | Completa la administración segura de la cuenta para los cuatro roles dentro de CU01–CU03 y RN12 |
| Invalidar sesiones ante cambios de contraseña | `Services/Auth/SessionSecurity` | Una sesión anterior no debe seguir autorizando operaciones tras un cambio de credenciales o permisos |
| Registro de operaciones de seguridad | Eventos `password_changed` y `session_revoked` con `AuditLogger` existente | RN59–RN62; mantiene la bitácora sin incorporar datos secretos |
| Separar operaciones de perfil | `UpdateContactRequest`, `Services/Patients/UpdatePatientContact` | El paciente modifica únicamente contacto propio sin reescribir la identidad administrada por recepción |

El resto de funcionalidades de la guía —inventario, caja, veterinaria, pagos PayPal, OTP, 2FA y reportes propios de ese dominio— no se incorporó. No se copiaron sus tablas, usuarios, contraseñas, configuración ni dependencias.

## Comportamiento disponible

Todos los roles tienen **Mi perfil** en el menú y en el botón de su avatar. Para cambiar la contraseña deben introducir la actual, una nueva de al menos 10 caracteres y su confirmación. La contraseña nueva debe ser diferente. La operación se valida y se ejecuta con bloqueo sobre la cuenta y dentro de una transacción, junto con su auditoría.

La sesión actual se regenera y recibe un nuevo token CSRF. Las demás sesiones se rechazan en su siguiente solicitud autenticada; no se trata de una desconexión mediante notificaciones en tiempo real. También se comprueba la firma de sesión en `GET /api/session`, por lo que una página recién cargada no presenta como válida una sesión revocada.

`SessionSecurity` conserva una firma HMAC que depende de la cuenta, el hash de contraseña, el rol y los vínculos a médico o paciente. La sesión no almacena una copia del hash de contraseña. Un cambio de contraseña desde CU03, un cambio de rol o una modificación del vínculo invalida la firma anterior y exige nuevo inicio de sesión. Las sesiones creadas antes de esta mejora establecen su firma en su primer acceso válido; no es posible detectar retrospectivamente un cambio anterior a ese primer acceso.

La recuperación por olvido de contraseña y la verificación de correo siguen fuera de este avance; no se enviaron correos ni se añadieron servicios externos. El requisito de contraseña se aplica a través del código y sus pruebas sin establecer contraseñas nuevas para las cuentas existentes.

## Actualización de contacto y concurrencia

El servicio de contacto bloquea el paciente vinculado a la cuenta y modifica exclusivamente `phone` y `address`. No vuelve a guardar los nombres, CI, nacimiento o estado que la pantalla hubiera leído antes. Así, una corrección de recepción realizada entre la consulta y el guardado del formulario se conserva.

## Sesión caducada en la interfaz

El cliente HTTP está separado en `resources/js/lib/api.js`. Actualiza el token CSRF devuelto por el servidor, limpia los datos locales cuando recibe 401 y muestra el motivo del cierre. Ante 419 consulta la sesión para obtener un token vigente y solicita al usuario que vuelva a iniciar sesión o revise y repita el intento. Nunca reenvía automáticamente una operación de escritura.

Este cliente utiliza `cache: no-store` al consultar datos de sesión y registros. No sustituye las comprobaciones de permisos, estado y firma realizadas por el servidor.

## Verificación

`AccountSecurityTest` comprueba cambio de contraseña en los cuatro roles, errores de validación, conservación de la sesión actual, rechazo de la firma anterior, cambios administrativos de contraseña, cambios de rol, ausencia de secretos en auditoría y conservación de correcciones de recepción. `tests/Frontend/api.test.mjs` prueba el transporte de CSRF, respuestas 401/419, errores de validación y la ausencia de reenvíos automáticos.

Las pruebas del cliente se ejecutan con `npm.cmd test` y están añadidas al flujo de integración continua. No requieren un navegador ni nuevas dependencias. Resultado del avance: **39 pruebas de Laravel aprobadas, 429 aserciones y 6 pruebas JavaScript aprobadas**. La compilación Vite también fue correcta. La inspección visual manual de las pantallas sigue pendiente.
