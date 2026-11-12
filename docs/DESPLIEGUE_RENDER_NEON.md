# Despliegue de Clínica Vida Salud en Render y Neon

El repositorio de este despliegue es exclusivamente **https://github.com/samuelbaimolplus-beep/clinica**, rama `main`. El servicio nuevo se llama `clinica-vida-salud`; el usuario proporcionó una conexión nueva a la base `neondb`. El proyecto `vetproy` se conserva intacto: no se reutiliza su servicio, su base ni su APP_KEY.

## 1. Crear la base en Neon

La conexión proporcionada apunta a `neondb`, que se verificó vacía antes de restaurar. Para una instalación nueva, usar una base independiente con un rol propietario exclusivo y una región cercana a Render. Copiar desde **Connect** la cadena de conexión **directa** (sin `-pooler`), conservando `sslmode=require&channel_binding=require`. Guardarla como secreto `DATABASE_URL` en Render y definir `CLINIC_DEPLOY_DATABASE=neondb` y `PGCHANNELBINDING=require`. Nunca poner la contraseña en este repositorio, el Dockerfile ni en una variable `VITE_*`.

Las migraciones crean las tablas, índices, procedimientos y triggers del proyecto, incluidos los nombres en español. No es necesario importar el SQL de entrega para una instalación vacía. El arranque comprueba que la base de la URL coincida con `CLINIC_DEPLOY_DATABASE=neondb` antes de migrar. No usar la base de vetproy, `migrate:fresh` ni `db:seed` sobre datos existentes.

**En esta entrega se conservarán los datos actuales.** Restaurar el respaldo local en la nueva base antes del primer despliegue. Para crear o actualizar el respaldo, sin modificar el `.env` local:

```powershell
$env:PG_DUMP_BINARY='C:/Program Files/PostgreSQL/17/bin/pg_dump.exe'
php deploy/backup.php
```

El archivo `.dump` y su manifiesto `.json` quedan en `storage/app/private/despliegue`, excluidos de Git y de la imagen Docker. El respaldo usa un snapshot consistente de solo lectura y conserva el esquema, las cuentas con sus contraseñas cifradas mediante hash, los pacientes, las citas, los pagos y la auditoría. Las sesiones, la caché, las colas técnicas y los tokens temporales de recuperación se dejan vacíos para comenzar con sesiones nuevas. No se cambian las contraseñas existentes ni la verificación de correo.

En una terminal privada, definir `NEON_DATABASE_URL` con la conexión directa de la nueva base `neondb` (no enviarla por chat ni guardarla en un archivo del repositorio). Después:

```powershell
$env:PG_RESTORE_BINARY='C:/Program Files/PostgreSQL/17/bin/pg_restore.exe'
$env:CLINIC_DEPLOY_DATABASE='neondb'
php deploy/restore.php storage/app/private/despliegue/NOMBRE_MANIFIESTO.json
Remove-Item Env:NEON_DATABASE_URL
```

El restaurador verifica el SHA-256, exige el host directo de Neon con TLS y la base seleccionada explícitamente en `CLINIC_DEPLOY_DATABASE`, rechaza un destino que ya tenga tablas, restaura en una sola transacción sin propietarios ni privilegios locales y compara las cantidades de filas con el manifiesto. El campo `target_database=clinica` del respaldo identifica el proyecto de origen; el destino real se selecciona con la variable anterior. No usa `--clean` ni elimina tablas existentes. El despliegue posterior aplica solo las migraciones pendientes. No importar a la base de vetproy. Si hubo cambios locales después del respaldo, generar uno nuevo antes de la restauración definitiva.

## 2. Crear el servicio nuevo en Render

Crear **New → Blueprint** y seleccionar el repositorio `samuelbaimolplus-beep/clinica`. Render usa `render.yaml` y construye el Dockerfile desde la raíz; no definir un Root Directory adicional. Alternativamente, crear un Web Service con runtime Docker, rama `main`, Dockerfile `./Dockerfile`, contexto `.` y health check `/up`.

El Blueprint declara un servicio Free para demostración y despliegues manuales. Si el nombre ya está ocupado, elegir otro nombre nuevo en Render. La URL efectiva se obtiene de `RENDER_EXTERNAL_URL`; no se supone que basta con cambiar `vetproy` en una URL. Para un dominio propio, definir `APP_URL=https://dominio-nuevo` y redeplegar. No configurar APP_URL con localhost.

Introducir los secretos solicitados:

| Variable | Valor |
| --- | --- |
| `APP_KEY` | Generar una clave nueva con `php artisan key:generate --show`. Copiar el resultado a Render, sin usar `key:generate` sin `--show` en local. Mantener la misma clave en futuros despliegues. |
| `DATABASE_URL` | Conexión directa de Neon a la base nueva `neondb`, con TLS. |
| `MAIL_HOST` | Host del proveedor de correo que admita SMTP en el puerto configurado. |
| `MAIL_USERNAME` y `MAIL_PASSWORD` | Credenciales del proveedor de correo. |
| `MAIL_FROM_ADDRESS` | Remitente autorizado por el proveedor. |

El Blueprint usa SMTP con STARTTLS en el puerto 2525. **Render Free bloquea los puertos 25, 465 y 587**, por lo que el SMTP de Gmail en 587 usado en local no funciona allí. Se necesita un proveedor que admita 2525, o un servicio de Render de pago con SMTP permitido en 465/587, ajustando `MAIL_PORT` y `MAIL_SCHEME`. Configurar el correo antes de activar cuentas; `MAIL_MAILER=log` no entrega los códigos a las personas. No se copian automáticamente las credenciales locales ni las de vetproy.

El resto de las variables se declara en `render.yaml`: entorno de producción sin depuración, logs a stderr, sesiones cifradas y cookies HTTPS, caché y cola en PostgreSQL. Estos registros persisten en Neon aunque Render reinicie el contenedor.

## 3. Arranque y primer acceso

La imagen instala PHP 8.4, Apache, extensiones de PostgreSQL, dependencias Composer y assets compilados con Node 22. Al arrancar, valida la configuración, aplica `migrate --force` y cachea configuración, rutas y vistas. Después comprueba la conexión PostgreSQL como `www-data`, el usuario real de los procesos de cola y programación. Supervisor mantiene Apache en `0.0.0.0:$PORT`, el worker de la cola y el programador de conciliación de pagos. Si falla una validación, migración o conexión del usuario de la aplicación, el arranque falla y el log muestra el motivo; no se vacían tablas ni se generan claves nuevas.

La imagen y el arranque fijan `HOME=/var/www`; Supervisor también declara HOME, USER y LOGNAME para los procesos `www-data`. Esto evita heredar `/root` y que libpq falle al buscar el certificado opcional `/root/.postgresql/postgresql.crt` por falta de permisos. Se mantiene TLS y channel binding. El fallo se reprodujo con HOME=/root y la conexión a Neon se comprobó con HOME=/var/www, ejecutando PHP como www-data.

Una instalación vacía no contiene usuarios. Desde la consola del servicio ejecutar:

```sh
php artisan clinica:crear-superadmin TU_CORREO --nombre="Tu nombre" --nacimiento=AAAA-MM-DD
```

Indicar una fecha real de una persona adulta. El comando deja el acceso inicial en `storage/app/private/acceso-superadmin.txt` y envía el código de activación. Recuperar ese archivo en una consola privada, conservarlo de forma segura y cambiar la contraseña al entrar. El disco del contenedor es efímero; no usarlo como respaldo. No repetir el comando si ya se restauraron las cuentas locales. Render Free no ofrece consola SSH/Shell ni trabajos de una sola ejecución; para este alta usar temporalmente un plan con consola, o ejecutar el comando desde una máquina de confianza con un entorno separado que apunte exclusivamente a la nueva base de Neon. No cambiar el `.env` local de la clínica para esto.

Abrir `/up`, la portada y `/api/session`. Verificar acceso, activación de correo, cambio de sección, cierre y reapertura de sesión y persistencia después de un redeploy. No considerar validado el envío de correo hasta recibir un código real.

## 4. Pagos, proxies y límites

PayPal empieza deshabilitado. Para habilitar el sandbox, añadir a Render `PAYPAL_ENABLED=true`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_BOB_PER_USD` y `PAYPAL_WEBHOOK_ID`, y registrar el webhook del servicio nuevo en `https://NUEVO_HOST/pagos/paypal/webhook`. Los retornos son `/pagos/paypal/retorno` y `/pagos/paypal/cancelado`. No cambiar el webhook de vetproy. Las rutas internas de la clínica permanecen iguales.

Para registrar la IP del visitante detrás del balanceador, configurar `TRUSTED_PROXIES` con las IP o redes verificadas de ese despliegue. Se conserva la restricción existente contra `*`: no se confía en cabeceras de cualquier cliente. HTTPS se fuerza para las URLs generadas en producción sin depender de confiar indiscriminadamente en proxies.

Render Free se suspende tras 15 minutos sin tráfico, puede tardar alrededor de un minuto en despertar y pierde archivos locales en reinicios. Mientras está suspendido no ejecuta el worker ni la conciliación periódica. Para operación continua, elegir una instancia que permanezca activa. El Blueprint está pensado para una sola instancia; al escalar, separar el scheduler para evitar ejecuciones repetidas. No hay cargas clínicas persistentes en disco en el flujo actual; cualquier función futura de archivos debe usar almacenamiento persistente.

## Referencias oficiales

Validación realizada: imagen Docker construida con dependencias de producción y assets; cachés de configuración, rutas y vistas generadas; configuración Apache válida; `/up`, `/` y `/api/session` respondieron HTTP 200 en un contenedor temporal con sesión de pruebas. Las seis pruebas de despliegue pasan (20 aserciones), incluidas sesiones, caché y cola sobre PostgreSQL con el esquema en español. El respaldo se restauró primero en una base temporal local y después en la base autorizada `neondb` de Neon: se verificaron las cantidades de las 19 tablas conservadas, dejando vacíos los datos técnicos temporales. La conexión cliente se comprobó con TLS 1.3 y channel binding obligatorio. Laravel leyó 14 cuentas, 13 pacientes, 6 médicos, 28 citas, 13 pagos y 160 eventos de auditoría; no hay migraciones pendientes. La conexión local permanece intacta. Falta confirmar que las variables anteriores estén guardadas en Render y verificar el servicio desplegado.

- [Laravel y Docker en Render](https://render.com/docs/deploy-php-laravel-docker)
- [Blueprints de Render](https://render.com/docs/blueprint-spec)
- [Limitaciones de Render Free](https://render.com/docs/free)
- [Laravel con Neon](https://neon.com/docs/guides/laravel)
- [Conexiones directas y pooling en Neon](https://neon.com/docs/connect/connection-pooling)
- [Importar con pg_dump y pg_restore](https://neon.com/docs/import/import-from-postgres)
- [Entorno de los procesos de Supervisor](https://supervisord.org/subprocess.html#subprocess-environment)
- [Certificados cliente de PostgreSQL](https://www.postgresql.org/docs/17/libpq-ssl.html#LIBPQ-SSL-CLIENTCERT)
