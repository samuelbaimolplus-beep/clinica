# Clínica Vida Salud

Sistema académico de gestión de citas médicas construido con Laravel 13, PostgreSQL, Blade y JavaScript. La entrega actual comprende autenticación con cuatro roles, cuentas del personal, médicos, especialidades, pacientes, registro y vinculación de cuentas de pacientes, horarios básicos y auditoría automática.

La [guía de arquitectura y trazabilidad](docs/ARQUITECTURA_Y_TRAZABILIDAD.md) explica la relación entre el documento del proyecto, casos de uso, reglas de negocio, clases y pruebas. También documenta las diferencias entre apartados antiguos y nuevos del Word, las decisiones de alcance y una secuencia para la defensa.

Estado vigente comunicado por el equipo: **CU01–CU05 establecidos; CU06–CU23 en revisión**. La [revisión del Word del 4 de noviembre](docs/REVISION_WORD_2026-11-04.md) conserva el contraste realizado entonces y registra esta actualización de estado. Incluye el informe de funciones y decisiones que todavía no están descritas expresamente en el Word.

Se autorizó de forma específica la [distribución de cupos de CU07 por mayor resto](docs/REGLA_CUPOS_MAYOR_RESTO.md), con prioridad presencial en empate exacto. Está disponible en **Horarios médicos → Distribución de cupos** y no supone dar por cerrado el resto de CU07.

El [ajuste de código a CU01–CU05](docs/AJUSTE_CU01_A_CU05_2026-11-04.md) documenta las correcciones ya implementadas: errores de autenticación/cierre, consulta de solo lectura, estados, especialidades activas, nombres normalizados y citas afectadas por cambios administrativos.

El [registro de uso del ZIP de referencia](docs/USO_DE_LA_GUIA_ZIP.md) explica qué ideas se adaptaron: perfil para los cuatro roles, cambio de contraseña, revocación de sesiones y conservación de correcciones de recepción al actualizar contacto.

## Ejecutar el proyecto existente

Desde esta carpeta, con PostgreSQL iniciado y `.env` configurado:

```powershell
php artisan migrate
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

Abrir <http://127.0.0.1:8000>. Las migraciones agregan estructuras pendientes sin vaciar las tablas. No utilizar `migrate:fresh` sobre la base del proyecto.

La cuenta administrativa inicial utiliza `admin@vidasalud.bo`. Su contraseña está únicamente en `storage/app/private/acceso-inicial.txt`, excluido de Git. Para una instalación sin administrador: `php artisan clinica:crear-admin correo@ejemplo.bo`.

## Instalación nueva

Se necesitan PHP 8.3 o superior con `pdo_pgsql`, Composer, Node.js y PostgreSQL. Instalar dependencias con `composer install` y `npm.cmd ci`; copiar `.env.example` a `.env`, generar `APP_KEY` con `php artisan key:generate` y configurar `DB_CONNECTION=pgsql`, host, puerto, base, usuario y contraseña de una base existente. Después ejecutar las migraciones, compilar y crear el primer administrador. No versionar `.env` ni los archivos privados de acceso.

## Organización y uso

- `app/Http/Controllers/Catalog` y `app/Http/Requests/Catalog`: entrada y validación independientes para usuarios, médicos, especialidades y pacientes.
- `app/Services`: operaciones de catálogos, registro/vinculación, horarios y auditoría.
- `app/Services/Auth`: cambio de contraseña propio y firma de sesión para detectar cambios de credenciales, rol o vínculos.
- `app/Support/ClinicAccess.php`: vistas disponibles por rol y comprobación de cuentas/perfiles activos.
- `app/Observers`: registro automático de modificaciones relevantes.
- `resources/js/modules`: formularios y pantallas de catálogos, horarios y cuentas de pacientes.
- `database/migrations`: integridad de datos y protección de la bitácora en PostgreSQL.
- `tests/Feature`: reglas de negocio, seguridad y regresión.

El administrador gestiona el personal, médicos, especialidades y horarios. Recepción administra pacientes y puede generar códigos de vinculación después de verificar su identidad. El paciente crea su cuenta desde **Crear cuenta de paciente**, acepta los términos y consulta exclusivamente su perfil. Los pacientes ya registrados utilizan el código emitido por recepción para conservar su identificador y sus relaciones históricas.

Todos los roles pueden abrir **Mi perfil → Cambiar contraseña**. Se verifica la contraseña actual; la sesión que realiza el cambio continúa y las demás deben volver a identificarse en su siguiente acceso.

Los horarios básicos admiten una franja por médico y día con duración configurable. El Administrador puede guardar porcentajes presenciales/online por médico y calcular su capacidad diaria por mayor resto, con desempate presencial. El cálculo conserva las citas existentes. La auditoría funciona automáticamente; su pantalla de consulta queda para una entrega posterior.

## Funciones aplazadas

La asignación de bloques concretos por modalidad, reservas con pago obligatorio, pasarela, reembolsos, seguimientos, asistencia, atención e historial requieren la integración indicada en el documento. La configuración porcentual y el cálculo de cupos ya están disponibles. El código anterior de citas/reportes se conserva y queda deshabilitado por defecto mediante `CLINIC_APPOINTMENTS_ENABLED=false`. Esa opción controla tanto rutas como menú; habilitar el comportamiento anterior no implementa las reglas nuevas de pago.

`IMPLEMENTACION.md` conserva antecedentes de aquella implementación; la referencia vigente es la guía de arquitectura.

## Validación

Las pruebas requieren la base separada `clinica_vida_salud_test` y las credenciales locales de PostgreSQL. `clinica:preparar-pruebas` solo aplica sus migraciones pendientes. Los casos funcionales utilizan transacciones revertidas.

```powershell
php artisan clinica:preparar-pruebas
php artisan test
node --test tests/Frontend/*.test.mjs
node --check resources/js/app.js
npm.cmd run build
php vendor/bin/pint --dirty --test
```

Última validación: **74 pruebas de Laravel aprobadas y 1087 aserciones, más 6 pruebas JavaScript**, incluyendo distribución por mayor resto y los flujos anteriores. Compilación Vite correcta. Las pruebas JavaScript también se pueden ejecutar con `npm.cmd test`. Queda pendiente la revisión visual manual porque no hubo un navegador conectado disponible.
