# Resumen de pruebas de caja negra

Escenarios consolidados: **64**. Casos de uso con ejecución: **26/26**.

- APROBADO: 62
- FALLIDO: 2
- BLOQUEADO: 0
- NO EJECUTADO: 0

Base exclusiva: `clinica_qa_caja_negra_20261107_02`. La base principal no se migró, reinició, borró ni modificó.
Reloj de aplicación y navegador: 09/11/2026 desde las 07:00, America/La_Paz. Para conciliación se avanzó a las 07:01. PostgreSQL conserva su reloj real.
Sesiones, caché, correo y registros del ejecutor aislados. Correos interceptados; no se acredita entrega SMTP.
Las sesiones QA se ampliaron a siete días para el reloj controlado; no se evaluó su expiración por inactividad. Los campos sensibles de la respuesta HTTP 401 se ocultaron antes de capturar el navegador.
Pagos PayPal: doble HTTP exclusivamente del servidor QA. No se utilizaron credenciales del comercio ni se realizaron solicitudes o cobros externos. Las devoluciones son registros ficticios declarados, no transferencias de dinero.

La conformidad del caso de uso no se obtiene sumando escenarios aprobados: un flujo principal fallido impide declarar el CU completamente conforme. Las pruebas no ejecutadas no se consideran aprobadas.

Los modelos de examen no estaban presentes como archivos identificables. Se siguieron la estructura pedida, los nombres V6 y su presentación académica en papel Carta.

Archivos: informe DOCX, matriz CSV para Excel, capturas por ciclo y CU, resultados JSON y manifiesto SHA-256 de imágenes.
La carpeta de capturas puede incluir evidencias adicionales de duplicados, autorizaciones y restricciones. Los nombres “exitoso” no sustituyen el estado del escenario. CU10 distingue el fallo principal del flujo alternativo; CU23 identifica el alta asistida.
