# Incidencias de las pruebas de caja negra

No se corrigieron funcionalidades ni se modificaron requisitos. Los errores del ejecutor se revisaron y se repitieron antes de consolidar los escenarios finales.

## INC-01 — CU10 — Reprogramación acordada
Gravedad: **ALTA**. Estado: abierto.

Precondiciones: Base QA exclusiva; datos ficticios; reloj controlado. Sesión del rol indicado y registros de preparación disponibles.
Entradas: {"date": "2026-11-11", "start_time": "08:30", "cause": "clinic", "change_reason": "Cambio acordado QA", "patient_accepted": true}
Pasos:
1. Iniciar sesión como Recepcionista y abrir Reprogramaciones con los filtros principales de médico y fecha vacíos.
2. Abrir la cita CIT-0004, del médico 1, y cambiar la fecha del formulario modal a 2026-11-11.
3. Intentar consultar o seleccionar un horario y comprobar la solicitud de disponibilidad.

Esperado: HTTP 200; cambia horario, conserva el pago y registra evento.
Obtenido: HTTP 422 al consultar horarios: /api/availability?doctor_id=&date=&exclude=4&modality=presential. El modal tiene médico y fecha válidos, pero la solicitud toma el médico y la fecha vacíos del filtro principal. No se ofrecen horarios y no se completa la reprogramación.
Evidencia: capturas/Ciclo_II/CU10/CU10_incidencia.png

La reprogramación con médico 1 y fecha 2026-11-11 preestablecidos en el filtro principal, sin ejecutar la búsqueda, se documenta como alternativa, no como corrección del defecto.

## INC-02 — CU23 — Autorregistro público descrito por V6
Gravedad: **MEDIA**. Estado: abierto.

Precondiciones: Base QA exclusiva; datos ficticios; reloj controlado. Visitante o cuenta pendiente.
Entradas: {"actor": "Paciente", "accion": "buscar formulario de registro público"}
Pasos:
1. Abrir el portal sin sesión.
2. Buscar registro de cuenta de paciente.
3. Comprobar el flujo disponible.

Esperado: V6 permite registrar cuenta al paciente desde el portal.
Obtenido: La pantalla de acceso no ofrece autorregistro. Indica acudir a la clínica para que Recepción cree la cuenta. El flujo V6 no puede completarse; existe un alta asistida posterior a V6.
Evidencia: capturas/Ciclo_IV/CU23/CU23_validacion.png

Clasificación: divergencia entre la especificación V6 y el comportamiento posterior acordado de alta presencial. Se conserva el requisito V6 como oráculo solicitado; se requiere decisión de trazabilidad del proyecto, no habilitar autorregistro durante esta evaluación.
