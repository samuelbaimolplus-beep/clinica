<?php

/** Adaptador exclusivo de PayPal Sandbox. Verifica identidad, moneda, importe y captura de cada orden. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Integraciones;

use App\Models\Pago;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PasarelaPayPal
{
    private const BASE = 'https://api-m.sandbox.paypal.com';

    private ?string $token = null;

    /** Comprueba que Sandbox y su tasa de demostración estén configurados. */
    public function estaConfigurada(): bool
    {
        return config('paypal.enabled') && filled(config('paypal.client_id')) && filled(config('paypal.secret')) && $this->tasaEnMillonesimas() > 0;
    }

    /** Representa la tasa de cambio como entero para convertir importes sin errores de coma flotante. */
    private function tasaEnMillonesimas(): int
    {
        $tasa = (string) config('paypal.bob_per_usd');
        if (! preg_match('/^\d{1,10}(?:\.\d{1,6})?$/D', $tasa)) {
            return 0;
        }
        [$entero, $fraccion] = array_pad(explode('.', $tasa, 2), 2, '');

        return ((int) $entero) * 1000000 + (int) str_pad($fraccion, 6, '0');
    }

    /** Fija importe, moneda y tasa que se utilizarán al verificar esta operación. */
    public function instantaneaImporte(int $bobCentavos): array
    {
        if (! $this->estaConfigurada()) {
            throw ValidationException::withMessages(['payment' => 'PayPal Sandbox no está configurado o la tasa de demostración no es válida.']);
        }
        $tasa = $this->tasaEnMillonesimas();
        $usdCentavos = intdiv($bobCentavos * 1000000 + intdiv($tasa, 2), $tasa);
        if ($usdCentavos < 1) {
            throw ValidationException::withMessages(['payment' => 'El importe convertido debe ser de al menos un centavo USD.']);
        }

        return ['provider_amount_minor' => $usdCentavos, 'provider_currency' => 'USD', 'exchange_rate' => (string) config('paypal.bob_per_usd')];
    }

    /** Prepara el cliente autorizado de la integración externa. */
    private function clienteHttp(): PendingRequest
    {
        if (! $this->token) {
            $resultado = Http::acceptJson()->asForm()->withBasicAuth(config('paypal.client_id'), config('paypal.secret'))
                ->connectTimeout(5)->timeout(20)->post(self::BASE.'/v1/oauth2/token', ['grant_type' => 'client_credentials'])->throw()->json();
            if (! is_string($resultado['access_token'] ?? null) || $resultado['access_token'] === '') {
                throw new \RuntimeException('PayPal no devolvió una autorización válida.');
            }
            $this->token = $resultado['access_token'];
        }

        return Http::acceptJson()->asJson()->withToken($this->token)->connectTimeout(5)->timeout(20);
    }

    /** Construye la referencia que vincula inequívocamente pago, reserva e intento. */
    public function referencia(Pago $pago): string
    {
        return 'CVS-'.$pago->booking_request_id.'-'.$pago->attempt;
    }

    /** Convierte los centavos a la representación decimal que exige PayPal. */
    public function importeUsd(Pago $pago): string
    {
        return intdiv($pago->provider_amount_minor, 100).'.'.str_pad((string) ($pago->provider_amount_minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Crea la orden de Sandbox vinculada al pago y comprueba la respuesta recibida. */
    public function crearOrden(Pago $pago): array
    {
        $referenciaDato = $this->referencia($pago);
        $orden = $this->clienteHttp()->withHeaders(['PayPal-Request-Id' => 'CVS-ORDER-'.$pago->id, 'Prefer' => 'return=representation'])
            ->post(self::BASE.'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [['reference_id' => $referenciaDato, 'custom_id' => $referenciaDato, 'invoice_id' => $referenciaDato,
                    'description' => 'Reserva Clínica Vida Salud - Sandbox',
                    'amount' => ['currency_code' => 'USD', 'value' => $this->importeUsd($pago)]]],
                'payment_source' => ['paypal' => ['experience_context' => ['brand_name' => 'Clínica Vida Salud', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW',
                    'return_url' => rtrim(config('app.url'), '/').'/pagos/paypal/retorno?operacion='.$pago->booking_request_id,
                    'cancel_url' => rtrim(config('app.url'), '/').'/pagos/paypal/cancelado?operacion='.$pago->booking_request_id]]],
            ])->throw()->json();
        if (! $this->coincideConPago($orden, $pago)) {
            throw new \RuntimeException('La orden de PayPal no corresponde al importe y operación solicitados.');
        }

        return ['reference' => $orden['id'], 'approval_url' => $this->urlAprobacion($orden), 'order' => $orden];
    }

    /** Acepta únicamente el enlace HTTPS de aprobación de la misma orden en PayPal Sandbox. */
    public function urlAprobacion(array $orden): ?string
    {
        foreach ($orden['links'] ?? [] as $vinculo) {
            if (! in_array($vinculo['rel'] ?? '', ['approve', 'payer-action'], true)) {
                continue;
            }
            $url = $vinculo['href'] ?? '';
            $partes = parse_url($url);
            parse_str($partes['query'] ?? '', $consultaDatos);
            if (($partes['scheme'] ?? '') === 'https' && ($partes['host'] ?? '') === 'www.sandbox.paypal.com'
                && ($partes['path'] ?? '') === '/checkoutnow' && ! isset($partes['user']) && ! isset($partes['pass'])
                && ! isset($partes['port']) && ($consultaDatos['token'] ?? null) === ($orden['id'] ?? null)) {
                return $url;
            }
        }

        return null;
    }

    /** Consulta la orden existente; no crea un cobro nuevo. */
    public function consultarOrden(string $id): array
    {
        if (! preg_match('/^[a-zA-Z0-9-]{1,64}$/D', $id)) {
            throw new \RuntimeException('Identificador PayPal no válido.');
        }

        return $this->clienteHttp()->get(self::BASE.'/v2/checkout/orders/'.rawurlencode($id))->throw()->json();
    }

    /** Contrasta identidad, importe, moneda y referencias de la orden con el pago esperado. */
    public function coincideConPago(array $orden, Pago $pago): bool
    {
        $unidades = $orden['purchase_units'] ?? [];
        $unidad = $unidades[0] ?? [];

        return $pago->method === 'paypal' && $pago->provider_amount_minor > 0
            && is_string($orden['id'] ?? null) && ($pago->reference === null || $orden['id'] === $pago->reference)
            && ($orden['intent'] ?? '') === 'CAPTURE' && count($unidades) === 1
            && ($unidad['reference_id'] ?? '') === $this->referencia($pago)
            && ($unidad['custom_id'] ?? '') === $this->referencia($pago)
            && ($unidad['invoice_id'] ?? '') === $this->referencia($pago)
            && ($unidad['amount']['currency_code'] ?? '') === 'USD'
            && ($unidad['amount']['value'] ?? '') === $this->importeUsd($pago);
    }

    /** Solo una captura COMPLETED íntegra permite emitir la ficha. */
    public function consultarEstado(Pago $pago, bool $descartarNoAprobado = false): array
    {
        $creados = null;
        if (! $pago->reference) {
            // PayPal conserva la clave de idempotencia seis horas; no se recrea fuera de esa ventana.
            if (! $pago->created_at || $pago->created_at->lt(now()->subHours(5))) {
                return ['status' => 'unknown'];
            }
            $creados = $this->crearOrden($pago);
            $orden = $creados['order'];
        } else {
            $orden = $this->consultarOrden($pago->reference);
        }
        if (! $this->coincideConPago($orden, $pago)) {
            return ['status' => 'unknown'];
        }
        $resultado = ['reference' => $orden['id'], 'approval_url' => $this->urlAprobacion($orden) ?? $creados['approval_url'] ?? $pago->approval_url];
        if (($orden['status'] ?? '') === 'APPROVED') {
            $respuesta = $this->clienteHttp()->withHeaders(['PayPal-Request-Id' => 'CVS-CAPTURE-'.$pago->id, 'Prefer' => 'return=representation'])
                ->withBody('{}', 'application/json')->post(self::BASE.'/v2/checkout/orders/'.rawurlencode($orden['id']).'/capture');
            // Un timeout o una respuesta ambigua se resuelven consultando la misma orden.
            if (! $respuesta->successful()) {
                $orden = $this->consultarOrden($orden['id']);
            } else {
                $orden = $respuesta->json();
            }
            if (! $this->coincideConPago($orden, $pago)) {
                return $resultado + ['status' => 'unknown'];
            }
        }
        $capturas = $orden['purchase_units'][0]['payments']['captures'] ?? [];
        if (count($capturas) === 1) {
            $captura = $capturas[0];
            if (($captura['amount']['currency_code'] ?? '') !== 'USD' || ($captura['amount']['value'] ?? '') !== $this->importeUsd($pago)) {
                return $resultado + ['status' => 'unknown'];
            }
            if (($orden['status'] ?? '') === 'COMPLETED' && ($captura['status'] ?? '') === 'COMPLETED'
                && ($captura['final_capture'] ?? false) === true && is_string($captura['id'] ?? null) && $captura['id'] !== '') {
                return $resultado + ['status' => 'confirmed', 'capture_id' => $captura['id']];
            }

            return $resultado + ['status' => match ($captura['status'] ?? '') {
                'PENDING' => 'pending', 'DECLINED', 'FAILED' => 'failed', default => 'unknown',
            }];
        }
        if ($capturas !== []) {
            return $resultado + ['status' => 'unknown'];
        }

        return $resultado + ['status' => match ($orden['status'] ?? '') {
            'CREATED', 'PAYER_ACTION_REQUIRED' => $descartarNoAprobado ? 'failed' : 'pending',
            'APPROVED' => 'pending', 'VOIDED' => 'failed', default => 'unknown',
        }];
    }

    /** Verifica la firma de la notificación con la app Sandbox antes de procesarla. */
    public function verificarNotificacion(Request $solicitud): bool
    {
        if (! filled(config('paypal.webhook_id'))) {
            return false;
        }
        $contenido = ['webhook_id' => config('paypal.webhook_id'), 'webhook_event' => $solicitud->json()->all()];
        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $campo) {
            $valor = $solicitud->header('paypal-'.str_replace('_', '-', $campo));
            if (! is_string($valor) || $valor === '') {
                return false;
            }
            $contenido[$campo] = $valor;
        }
        $resultado = $this->clienteHttp()->post(self::BASE.'/v1/notifications/verify-webhook-signature', $contenido)->throw()->json();

        return ($resultado['verification_status'] ?? '') === 'SUCCESS';
    }
}
