<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcilePayPalPayment;
use App\Models\Payment;
use App\Services\Payments\PayPalGateway;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayPalWebhookController extends Controller
{
    public function __invoke(Request $request, PayPalGateway $gateway)
    {
        abort_unless($gateway->configured() && filled(config('paypal.webhook_id')), 503, 'Webhook Sandbox no configurado.');
        $request->validate(['id' => 'required|string|max:100', 'event_type' => 'required|string|max:100', 'resource' => 'required|array']);
        abort_unless($gateway->verifyWebhook($request), 401);
        $event = $request->input('event_type');
        if (! in_array($event, ['CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.COMPLETED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED'])) {
            return ['received' => true];
        }
        $orderId = str_starts_with($event, 'CHECKOUT.ORDER.') ? $request->input('resource.id') : $request->input('resource.supplementary_data.related_ids.order_id');
        abort_unless(is_string($orderId), 422);
        try {
            $order = $gateway->fetch($orderId);
        } catch (RequestException $exception) {
            // Los eventos simulados pueden tener una firma válida y una orden ficticia.
            // Un recurso inexistente no se confirma ni necesita reintentos de entrega.
            if ($exception->response->status() === 404) {
                return ['received' => true, 'processed' => false, 'reason' => 'order_not_found'];
            }
            throw $exception;
        }
        $ref = $order['purchase_units'][0]['custom_id'] ?? '';
        abort_unless(preg_match('/^CVS-(\d+)-(\d+)$/D', $ref, $matches), 422);
        $payment = Payment::where('method', 'paypal')->where('booking_request_id', $matches[1])->where('attempt', $matches[2])->first();
        // Una notificación antigua/ajena no puede asignarse a otra cuenta o importe.
        abort_unless($payment && $gateway->matches($order, $payment), 422);
        DB::transaction(function () use ($payment, $orderId, $gateway, $order) {
            $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->reference === null || $p->reference === $orderId, 422);
            if ($p->reference === null) {
                $p->update(['reference' => $orderId, 'approval_url' => $gateway->approvalUrl($order)]);
            }
            ReconcilePayPalPayment::dispatch($p->booking_request_id)->afterCommit();
        });

        return ['received' => true];
    }
}
