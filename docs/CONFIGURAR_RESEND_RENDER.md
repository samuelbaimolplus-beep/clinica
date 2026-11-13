# Correo de la clínica con Resend y Render

El proyecto usa el transporte Resend incluido en Laravel y el SDK oficial `resend/resend-php`. Los correos de activación y recuperación se envían por HTTPS, sin usar SMTP.

## Configuración inicial sin dominio propio

En Render → servicio `clinica-y8yy` → Environment, guardar:

| Variable | Valor |
| --- | --- |
| `MAIL_MAILER` | `resend` |
| `RESEND_API_KEY` | La clave creada en Resend → API Keys; guardarla solo en Render. |
| `MAIL_FROM_ADDRESS` | `onboarding@resend.dev` |
| `MAIL_FROM_NAME` | `Clínica Vida Salud` |

Las variables `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` y `MAIL_SCHEME` no son usadas por este transporte. No hace falta copiar la configuración local de Gmail.

Guardar los cambios y desplegar el último commit desde Manual Deploy → Deploy latest commit. El arranque vuelve a generar la caché de configuración con las variables de Render. Modificar `render.yaml` por sí solo no cambia las variables de un servicio que se creó manualmente.

Con `onboarding@resend.dev`, Resend únicamente permite enviar al correo asociado a la cuenta de Resend. En esta configuración se puede verificar el flujo con el titular; no sirve todavía para enviar códigos a pacientes con otros correos.

## Habilitar correos a pacientes

En Resend → Domains, añadir un dominio propio y completar la verificación DNS. Cuando aparezca Verified, cambiar `MAIL_FROM_ADDRESS` en Render por una dirección de ese dominio y volver a desplegar. No se puede verificar `gmail.com` ni `onrender.com` como si fueran dominios propios.

La dirección del remitente debe corresponder a un dominio autorizado por la clave. Una clave restringida a otro dominio o un remitente sin verificar puede producir un error 403.

## Comprobar entrega

Solicitar un correo de activación o recuperación de una cuenta existente con el correo autorizado. El mensaje de recuperación de la web es intencionalmente genérico: no demuestra que Resend aceptó el correo ni que llegó a la bandeja de entrada.

Revisar Resend → Emails y confirmar la recepción, incluida la carpeta de spam. Una prueba aislada con el transporte simulado comprueba el formato y la integración del SDK, pero no acredita entrega real.

Fuentes: [Laravel 13, transporte Resend](https://laravel.com/docs/13.x/mail#resend-driver), [restricción del remitente de pruebas](https://resend.com/docs/knowledge-base/403-error-resend-dev-domain).
