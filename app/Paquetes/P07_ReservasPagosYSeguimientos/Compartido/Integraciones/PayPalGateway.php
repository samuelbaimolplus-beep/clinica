<?php

/** Adaptador exclusivo de PayPal Sandbox. Verifica identidad, moneda, importe y captura de cada orden. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Integraciones;

use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PayPalGateway
{
    private const BASE = 'https://api-m.sandbox.paypal.com';

    private ?string $token = null;

    /** Comprueba que Sandbox y su tasa de demostración estén configurados. */
    public function configured(): bool
    {
        return config('paypal.enabled') && filled(config('paypal.client_id')) && filled(config('paypal.secret')) && $this->rateMicros() > 0;
    }

    /** Representa la tasa de cambio como entero para convertir importes sin errores de coma flotante. */
    private function rateMicros(): int
    {
        $rate = (string) config('paypal.bob_per_usd');
        if (! preg_match('/^\d{1,10}(?:\.\d{1,6})?$/D', $rate)) {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        return ((int) $whole) * 1000000 + (int) str_pad($fraction, 6, '0');
    }

    /** Fija importe, moneda y tasa que se utilizarán al verificar esta operación. */
    public function snapshot(int $bobMinor): array
    {
        if (! $this->configured()) {
            throw ValidationException::withMessages(['payment' => 'PayPal Sandbox no está configurado o la tasa de demostración no es válida.']);
        }
        $rate = $this->rateMicros();
        $usdMinor = intdiv($bobMinor * 1000000 + intdiv($rate, 2), $rate);
        if ($usdMinor < 1) {
            throw ValidationException::withMessages(['payment' => 'El importe convertido debe ser de al menos un centavo USD.']);
        }

        return ['provider_amount_minor' => $usdMinor, 'provider_currency' => 'USD', 'exchange_rate' => (string) config('paypal.bob_per_usd')];
    }

    /** Prepara el cliente autorizado de la integración externa. */
    private function api(): PendingRequest
    {
        if (! $this->token) {
            $result = Http::acceptJson()->asForm()->withBasicAuth(config('paypal.client_id'), config('paypal.secret'))
                ->connectTimeout(5)->timeout(20)->post(self::BASE.'/v1/oauth2/token', ['grant_type' => 'client_credentials'])->throw()->json();
            if (! is_string($result['access_token'] ?? null) || $result['access_token'] === '') {
                throw new \RuntimeException('PayPal no devolvió una autorización válida.');
            }
            $this->token = $result['access_token'];
        }

        return Http::acceptJson()->asJson()->withToken($this->token)->connectTimeout(5)->timeout(20);
    }

    /** Construye la referencia que vincula inequívocamente pago, reserva e intento. */
    public function reference(Payment $payment): string
    {
        return 'CVS-'.$payment->booking_request_id.'-'.$payment->attempt;
    }

    /** Convierte los centavos a la representación decimal que exige PayPal. */
    public function usdValue(Payment $payment): string
    {
        return intdiv($payment->provider_amount_minor, 100).'.'.str_pad((string) ($payment->provider_amount_minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Crea la orden de Sandbox vinculada al pago y comprueba la respuesta recibida. */
    public function create(Payment $payment): array
    {
        $ref = $this->reference($payment);
        $order = $this->api()->withHeaders(['PayPal-Request-Id' => 'CVS-ORDER-'.$payment->id, 'Prefer' => 'return=representation'])
            ->post(self::BASE.'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [['reference_id' => $ref, 'custom_id' => $ref, 'invoice_id' => $ref,
                    'description' => 'Reserva Clínica Vida Salud - Sandbox',
                    'amount' => ['currency_code' => 'USD', 'value' => $this->usdValue($payment)]]],
                'payment_source' => ['paypal' => ['experience_context' => ['brand_name' => 'Clínica Vida Salud', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW',
                    'return_url' => rtrim(config('app.url'), '/').'/pagos/paypal/retorno?operacion='.$payment->booking_request_id,
                    'cancel_url' => rtrim(config('app.url'), '/').'/pagos/paypal/cancelado?operacion='.$payment->booking_request_id]]],
            ])->throw()->json();
        if (! $this->matches($order, $payment)) {
            throw new \RuntimeException('La orden de PayPal no corresponde al importe y operación solicitados.');
        }

        return ['reference' => $order['id'], 'approval_url' => $this->approvalUrl($order), 'order' => $order];
    }

    /** Acepta únicamente el enlace HTTPS de aprobación de la misma orden en PayPal Sandbox. */
    public function approvalUrl(array $order): ?string
    {
        foreach ($order['links'] ?? [] as $link) {
            if (! in_array($link['rel'] ?? '', ['approve', 'payer-action'], true)) {
                continue;
            }
            $url = $link['href'] ?? '';
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            if (($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'www.sandbox.paypal.com'
                && ($parts['path'] ?? '') === '/checkoutnow' && ! isset($parts['user']) && ! isset($parts['pass'])
                && ! isset($parts['port']) && ($query['token'] ?? null) === ($order['id'] ?? null)) {
                return $url;
            }
        }

        return null;
    }

    /** Consulta la orden existente; no crea un cobro nuevo. */
    public function fetch(string $id): array
    {
        if (! preg_match('/^[a-zA-Z0-9-]{1,64}$/D', $id)) {
            throw new \RuntimeException('Identificador PayPal no válido.');
        }

        return $this->api()->get(self::BASE.'/v2/checkout/orders/'.rawurlencode($id))->throw()->json();
    }

    /** Contrasta identidad, importe, moneda y referencias de la orden con el pago esperado. */
    public function matches(array $order, Payment $payment): bool
    {
        $units = $order['purchase_units'] ?? [];
        $unit = $units[0] ?? [];

        return $payment->method === 'paypal' && $payment->provider_amount_minor > 0
            && is_string($order['id'] ?? null) && ($payment->reference === null || $order['id'] === $payment->reference)
            && ($order['intent'] ?? '') === 'CAPTURE' && count($units) === 1
            && ($unit['reference_id'] ?? '') === $this->reference($payment)
            && ($unit['custom_id'] ?? '') === $this->reference($payment)
            && ($unit['invoice_id'] ?? '') === $this->reference($payment)
            && ($unit['amount']['currency_code'] ?? '') === 'USD'
            && ($unit['amount']['value'] ?? '') === $this->usdValue($payment);
    }

    /** Solo una captura COMPLETED íntegra permite emitir la ficha. */
    public function status(Payment $payment, bool $cancelUnapproved = false): array
    {
        $created = null;
        if (! $payment->reference) {
            // PayPal conserva la clave de idempotencia seis horas; no se recrea fuera de esa ventana.
            if (! $payment->created_at || $payment->created_at->lt(now()->subHours(5))) {
                return ['status' => 'unknown'];
            }
            $created = $this->create($payment);
            $order = $created['order'];
        } else {
            $order = $this->fetch($payment->reference);
        }
        if (! $this->matches($order, $payment)) {
            return ['status' => 'unknown'];
        }
        $result = ['reference' => $order['id'], 'approval_url' => $this->approvalUrl($order) ?? $created['approval_url'] ?? $payment->approval_url];
        if (($order['status'] ?? '') === 'APPROVED') {
            $response = $this->api()->withHeaders(['PayPal-Request-Id' => 'CVS-CAPTURE-'.$payment->id, 'Prefer' => 'return=representation'])
                ->withBody('{}', 'application/json')->post(self::BASE.'/v2/checkout/orders/'.rawurlencode($order['id']).'/capture');
            // Un timeout o una respuesta ambigua se resuelven consultando la misma orden.
            if (! $response->successful()) {
                $order = $this->fetch($order['id']);
            } else {
                $order = $response->json();
            }
            if (! $this->matches($order, $payment)) {
                return $result + ['status' => 'unknown'];
            }
        }
        $captures = $order['purchase_units'][0]['payments']['captures'] ?? [];
        if (count($captures) === 1) {
            $capture = $captures[0];
            if (($capture['amount']['currency_code'] ?? '') !== 'USD' || ($capture['amount']['value'] ?? '') !== $this->usdValue($payment)) {
                return $result + ['status' => 'unknown'];
            }
            if (($order['status'] ?? '') === 'COMPLETED' && ($capture['status'] ?? '') === 'COMPLETED'
                && ($capture['final_capture'] ?? false) === true && is_string($capture['id'] ?? null) && $capture['id'] !== '') {
                return $result + ['status' => 'confirmed', 'capture_id' => $capture['id']];
            }

            return $result + ['status' => match ($capture['status'] ?? '') {
                'PENDING' => 'pending', 'DECLINED', 'FAILED' => 'failed', default => 'unknown',
            }];
        }
        if ($captures !== []) {
            return $result + ['status' => 'unknown'];
        }

        return $result + ['status' => match ($order['status'] ?? '') {
            'CREATED', 'PAYER_ACTION_REQUIRED' => $cancelUnapproved ? 'failed' : 'pending',
            'APPROVED' => 'pending', 'VOIDED' => 'failed', default => 'unknown',
        }];
    }

    /** Verifica la firma de la notificación con la app Sandbox antes de procesarla. */
    public function verifyWebhook(Request $request): bool
    {
        if (! filled(config('paypal.webhook_id'))) {
            return false;
        }
        $payload = ['webhook_id' => config('paypal.webhook_id'), 'webhook_event' => $request->json()->all()];
        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $field) {
            $value = $request->header('paypal-'.str_replace('_', '-', $field));
            if (! is_string($value) || $value === '') {
                return false;
            }
            $payload[$field] = $value;
        }
        $result = $this->api()->post(self::BASE.'/v1/notifications/verify-webhook-signature', $payload)->throw()->json();

        return ($result['verification_status'] ?? '') === 'SUCCESS';
    }
}
