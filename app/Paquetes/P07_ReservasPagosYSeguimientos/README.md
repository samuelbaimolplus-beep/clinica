# P07 — Reservas online, pagos, reembolsos y seguimientos

Referencia: `ofical.docx`, sección 5.1.2.

| CU | Responsabilidad | Implementación principal |
|---|---|---|
| CU18 | Reservar cita online | [OnlineBookingController.php](CU18_ReservarCitaOnline/Http/OnlineBookingController.php) — onlineStart / onlineStatus / onlineRetry / onlineCancel |
| CU19 | Verificar pago o exención de cita | [PaymentVerifier.php](CU19_VerificarPagoOExencion/Servicios/PaymentVerifier.php) — tariff / reception |
| CU20 | Solicitar reembolso | [RefundRequestController.php](CU20_SolicitarReembolso/Http/RefundRequestController.php) — requestRefund / refunds |
| CU21 | Registrar cita de seguimiento | [FollowupPolicy.php](CU21_RegistrarCitaDeSeguimiento/Servicios/FollowupPolicy.php) — evaluate |
| CU26 | Resolver solicitud de reembolso | [RefundResolutionController.php](CU26_ResolverSolicitudDeReembolso/Http/RefundResolutionController.php) — decideRefund / completeRefund |

## Código del paquete

- `Compartido/Integraciones/PayPalGateway.php`
- `Compartido/Integraciones/PayPalWebhookController.php`
- `Compartido/Servicios/RefundService.php`
- `CU18_ReservarCitaOnline/Http/OnlineBookingController.php`
- `CU18_ReservarCitaOnline/Servicios/OnlineBookingService.php`
- `CU19_VerificarPagoOExencion/Http/PaymentsController.php`
- `CU19_VerificarPagoOExencion/Servicios/PaymentVerifier.php`
- `CU20_SolicitarReembolso/Http/RefundRequestController.php`
- `CU21_RegistrarCitaDeSeguimiento/Servicios/FollowupPolicy.php`
- `CU26_ResolverSolicitudDeReembolso/Http/RefundResolutionController.php`

Los componentes de `Compartido` se usan desde varios CU. La matriz puede apuntar a otro paquete cuando la implementación aprovecha una consulta o transacción existente; no se duplica esa lógica.
