# Distribución de cupos por mayor resto

Este avance implementa la regla propuesta por el equipo en la conversación del 4 de noviembre de 2026: asignar cupos enteros mediante el método del mayor resto y favorecer la modalidad presencial en un empate exacto de las partes decimales.

La distribución porcentual por médico ya está prevista en RF07, RN35–RN37 y CU07. La revisión textual del Word disponible al cerrar este avance no encontró una definición explícita del método de redondeo o del desempate. Esta regla procede de la conversación y queda documentada aquí para incorporarla posteriormente al Word, sin asignarle un código RN que el equipo todavía no haya definido.

## Regla acordada

1. Los porcentajes presenciales y online deben sumar exactamente 100%.
2. Se calcula la cantidad proporcional de cada modalidad sobre la capacidad del médico.
3. Inicialmente se asigna la parte entera de cada cantidad.
4. El cupo restante se asigna a la modalidad con mayor parte decimal.
5. Si los restos son iguales, el cupo se asigna a presencial.
6. La suma de cupos de ambas modalidades siempre es igual a la capacidad calculada.

Con dos modalidades que suman 100%, después de tomar las partes enteras queda como máximo un cupo por asignar. La implementación utiliza aritmética entera en centésimas de porcentaje, evitando que errores de coma flotante alteren el desempate.

| Capacidad | Presencial | Online | Cupos presenciales | Cupos online |
| --- | --- | --- | --- | --- |
| 5 | 50% | 50% | 3 | 2 |
| 5 | 60% | 40% | 3 | 2 |
| 5 | 70% | 30% | 4 | 1 |
| 5 | 35% | 65% | 2 | 3 |
| 5 | 20% | 80% | 1 | 4 |

También se comprueban un solo cupo, capacidad cero, 0%/100%, 100%/0%, porcentajes decimales y casos donde el mayor resto corresponde a online. No se aplicó un redondeo siempre hacia arriba o siempre hacia abajo a presencial: la prioridad presencial se usa únicamente cuando los restos empatan.

## Qué se programó

- Nueva pestaña **Distribución de cupos** dentro de Horarios médicos, accesible al Administrador.
- Porcentajes independientes por médico, con una sola configuración vigente por profesional.
- Validación en servidor y restricción en PostgreSQL de porcentajes entre 0 y 100 cuya suma sea 100.
- Porcentajes inicialmente sin configurar: el sistema no inventa un reparto inicial.
- Cálculo por fecha basado en los bloques completos del horario y sus duraciones, excluyendo los bloques que se crucen con una indisponibilidad.
- Auditoría de los cambios de configuración dentro de la misma transacción.
- Conservación de las citas y sus asignaciones cuando se modifican los porcentajes.

Rutas: `PUT /api/doctor-quotas` y `GET /api/schedules/capacity?doctor_id=…&date=AAAA-MM-DD`. Ambas requieren sesión administrativa. La configuración actual también se devuelve al consultar el horario del médico.

`QuotaAllocator` contiene únicamente el reparto matemático. `DoctorQuotaService` valida y guarda la configuración y calcula la capacidad de una fecha. `ScheduleBlocks` genera los bloques del horario para ese cálculo. `DoctorQuota` y su migración conservan la configuración por médico.

## Alcance y decisiones adicionales

Se mantuvo sin efecto la ampliación general hasta CU07 que el equipo pidió ignorar. Este avance autoriza específicamente la distribución de cupos y su regla de redondeo; no declara establecidos CU06 ni el resto de CU07. Las modificaciones parciales ajenas a esta distribución se retiraron. La migración temporal de ampliación de franjas se revirtió únicamente en la base aislada de pruebas; nunca se aplicó a la base de la aplicación. La migración final solo añade la configuración de cupos.

El cálculo muestra **capacidad de horario por modalidad**, no el saldo de cupos libres después de citas o reservas. La pantalla lo indica expresamente. La integración con reservas y pagos deberá contabilizar las ocupaciones y respetar sus modalidades al implementar los casos correspondientes; este avance no formaliza reservas ni habilita las rutas anteriores de citas.

Se conserva el comportamiento básico previo de horarios: una franja por médico y día. El soporte de varias franjas y los demás ajustes del CU07 actualizado permanecen pendientes de su avance autorizado. No se presenta esta entrega como cumplimiento completo de CU07.

Además del redondeo acordado, se concretaron estas decisiones técnicas que deben figurar en diseño o aclararse al completar el Word:

- Los porcentajes admiten hasta dos cifras decimales.
- La vista de cálculo utiliza una fecha concreta; no compensa redondeos entre fechas ni acumula sobrantes para otro día.
- Un bloque que se cruce parcialmente con una indisponibilidad se excluye completo, porque una consulta debe caber íntegramente en un período disponible.
- El reparto devuelve cantidades por modalidad; no inventa un orden de asignación de horas concretas a presencial u online.

Para incorporar la regla al Word, añadir a CU07/RN35 la secuencia del mayor resto y el desempate presencial, y especificar posteriormente cómo se asignarán los bloques concretos por modalidad y cómo se tratarán las reservas ya ocupadas. La regla de cantidad queda implementada sin decidir esos flujos pendientes.

## Validación

Resultado: **74 pruebas Laravel aprobadas, 1087 aserciones y 6 pruebas JavaScript aprobadas**. Hay 12 ejemplos unitarios de distribución, verificaciones de conservación de capacidad y siete pruebas de integración de configuración, permisos, auditoría, indisponibilidades y conservación de citas. Los cinco ejemplos enviados por el equipo se comprueban también a través de la API.

La migración de cupos se aplicó a la base local y a la base aislada de pruebas. No se cargaron porcentajes ficticios ni se modificaron las citas de la base de la aplicación. La interfaz compiló con Vite; la comprobación sintáctica JavaScript y Pint fueron correctos. La inspección visual manual continúa pendiente por falta de navegador conectado.

Para probarlo: entrar como Administrador, abrir **CU07 → Distribución de cupos**, seleccionar un médico, guardar porcentajes que sumen 100 y consultar una fecha con horario. Por ejemplo, con cinco bloques válidos y 50%/50%, el resultado será 3 presenciales y 2 online.
