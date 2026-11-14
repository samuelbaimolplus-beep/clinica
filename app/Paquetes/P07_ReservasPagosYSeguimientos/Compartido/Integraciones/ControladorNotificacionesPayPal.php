<?php

/** Receptor de notificaciones PayPal: valida autenticidad y concilia antes de modificar pagos. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Integraciones;

use App\Http\Controllers\Controller;
use App\Jobs\ConciliarPagoPayPal;
use App\Models\Pago;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ControladorNotificacionesPayPal extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $solicitud, PasarelaPayPal $pasarela)
    {
        abort_unless($pasarela->estaConfigurada() && filled(config('paypal.webhook_id')), 503, 'Webhook Sandbox no configurado.');
        $solicitud->validate(['id' => 'required|string|max:100', 'event_type' => 'required|string|max:100', 'resource' => 'required|array']);
        abort_unless($pasarela->verificarNotificacion($solicitud), 401);
        $evento = $solicitud->input('event_type');
        if (! in_array($evento, ['CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.COMPLETED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED'])) {
            return ['received' => true];
        }
        $ordenId = str_starts_with($evento, 'CHECKOUT.ORDER.') ? $solicitud->input('resource.id') : $solicitud->input('resource.supplementary_data.related_ids.order_id');
        abort_unless(is_string($ordenId), 422);
        try {
            $orden = $pasarela->consultarOrden($ordenId);
        } catch (RequestException $excepcion) {
            // Los eventos simulados pueden tener una firma válida y una orden ficticia.
            // Un recurso inexistente no se confirma ni necesita reintentos de entrega.
            if ($excepcion->response->status() === 404) {
                return ['received' => true, 'processed' => false, 'reason' => 'order_not_found'];
            }
            throw $excepcion;
        }
        $referenciaDato = $orden['purchase_units'][0]['custom_id'] ?? '';
        abort_unless(preg_match('/^CVS-(\d+)-(\d+)$/D', $referenciaDato, $coincide), 422);
        $pago = Pago::where('method', 'paypal')->where('booking_request_id', $coincide[1])->where('attempt', $coincide[2])->first();
        // Una notificación antigua/ajena no puede asignarse a otra cuenta o importe.
        abort_unless($pago && $pasarela->coincideConPago($orden, $pago), 422);
        DB::transaction(function () use ($pago, $ordenId, $pasarela, $orden) {
            $elementoActual = Pago::whereKey($pago->id)->lockForUpdate()->firstOrFail();
            abort_unless($elementoActual->reference === null || $elementoActual->reference === $ordenId, 422);
            if ($elementoActual->reference === null) {
                $elementoActual->update(['reference' => $ordenId, 'approval_url' => $pasarela->urlAprobacion($orden)]);
            }
            ConciliarPagoPayPal::dispatch($elementoActual->booking_request_id)->afterCommit();
        });

        return ['received' => true];
    }
}
