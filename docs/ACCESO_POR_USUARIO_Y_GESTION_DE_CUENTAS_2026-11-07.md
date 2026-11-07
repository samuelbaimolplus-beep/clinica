# Acceso por usuario y gestión de contraseñas desde Superadmin

Actualización solicitada por el propietario el 7 de noviembre de 2026, posterior a la revisión funcional V6.

## Resultado para el usuario

Se puede iniciar sesión escribiendo el nombre de usuario o el correo electrónico en el mismo campo. La contraseña y los permisos de la cuenta se conservan. En la instalación local, la cuenta Superadmin existente puede acceder con `superadmin` y su contraseña habitual.

En **Usuarios y roles** se muestra el nombre de usuario del personal y se permite definirlo al registrar o modificar la cuenta. El registro público de pacientes permite elegirlo. El correo continúa siendo obligatorio para verificación y recuperación; no es obligatorio escribirlo al iniciar sesión.

El Superadmin dispone de **Usuarios y roles → Consultar todas las cuentas → Cambiar contraseña**. Esta consulta incluye personal y pacientes. La operación solicita una contraseña nueva y su confirmación. No revela la contraseña anterior ni su hash. El ojito permite mostrar únicamente la contraseña nueva que el operador está escribiendo.

## Reglas aplicadas

- Nombre de usuario de 3 a 50 caracteres: letras latinas sin acentos, números, punto, guion o guion bajo; comienza por letra o número. Se normaliza a minúsculas y se recortan espacios exteriores.
- Los nombres son únicos, sin distinguir mayúsculas. Se comprueba en el formulario y en PostgreSQL. No admiten `@`, por lo que un nombre no puede suplantar el correo de otra cuenta.
- Las cuentas existentes reciben un nombre derivado de la parte anterior a `@`; las coincidencias reciben un sufijo. Se conservan identificadores de cuenta, contraseña, relaciones y estados.
- Para compatibilidad, las altas anteriores que no envían el campo reciben un nombre válido con sufijo aleatorio. Los comandos iniciales incluyen el nombre asignado en su archivo privado de acceso.
- Los controles de contraseña, cuenta activa, perfil vinculado, rol y verificación de correo del Paciente permanecen vigentes para ambas formas de acceso.
- Cambiar el nombre de usuario o la contraseña invalida las sesiones anteriores. Cambiar el correo no modifica automáticamente el nombre de usuario elegido.
- Solo Superadmin puede consultar la lista global de cuentas y cambiar sus contraseñas mediante esta nueva función. Administrador conserva sus permisos anteriores sobre personal, sin poder administrar Superadmin.
- La contraseña nueva exige un mínimo de 10 caracteres y confirmación. Se almacena mediante hash, no se devuelve en la respuesta y no se registra en auditoría. Se auditan responsable, cuenta afectada y acción.
- Las respuestas de gestión de cuentas indican `Cache-Control: no-store`. Las claves y los hashes permanecen excluidos de las respuestas de cuentas y de esta interfaz.

## Relación con el Word

CU01 ya contempla correo o identificador: ahora el nombre de usuario constituye un identificador explícito y único. CU03 y CU23 incorporan su campo. No se cambia la numeración de los 26 CU.

La gestión global y el cambio de contraseñas son ampliaciones del Superadmin autorizadas expresamente por el propietario. Conviene incorporar ese alcance en la matriz de permisos. La aclaración posterior del propietario exige conservar la protección de contraseñas; no se implementó una interfaz de consulta de hashes o de recuperación de la contraseña original.

## Implementación

| Responsabilidad | Archivos |
| --- | --- |
| Integridad y asignación a cuentas existentes | `2026_11_07_000022_add_usernames_to_users.php` |
| Formato, generación y normalización | `app/Support/Username.php`, `User`, FormRequests y `PersonRules` |
| Selección de usuario/correo y revocación de sesión | `AuthController`, `SessionSecurity` |
| Registro y mantenimiento del nombre | `RegisterPatient`, formularios de personal/pacientes y comandos iniciales |
| Consulta global y cambio de contraseña | `AccountManagementController`, rutas `supervision/accounts`, `resources/js/modules/accounts.js` |

El contrato de login conserva la clave de entrada `email` por compatibilidad con clientes anteriores; su valor puede ser el correo o el nombre. El servidor decide la búsqueda por la presencia de `@`, sin construir nombres de columnas desde la entrada del cliente.

## Verificación

- 145 pruebas del servidor correctas, con 1.660 assertions, utilizando PostgreSQL aislado y transacciones revertidas.
- Las ocho pruebas nuevas de `UsernameLoginTest` cubren ambos identificadores, normalización, unicidad, cambios, sesiones, estado, registro de paciente y restricciones de PostgreSQL.
- Las cinco pruebas nuevas de `AccountManagementTest` cubren todos los roles, consulta sin secretos, cambio de claves de personal/pacientes, validación, auditoría y revocación de sesión.
- Compilación Vite y Laravel Pint correctos.
- Acceso local real comprobado con `superadmin`, sin escribir el correo, y apertura de sus 25 vistas sin errores de API o JavaScript.
- 18 pruebas de navegador correctas, con datos y API simulados para comprobar formularios. Incluyen login por usuario, consulta global y cambio de contraseña de paciente desde Superadmin. No cambian contraseñas reales ni realizan cobros o envíos de correo.

La migración se aplicó en la base local y en la base de pruebas, sin vaciar tablas ni cambiar contraseñas existentes. Las credenciales y los archivos privados de acceso permanecen fuera de Git.
