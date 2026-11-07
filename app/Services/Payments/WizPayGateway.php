<?php

namespace App\Services\Payments;

use App\Models\BookingRequest;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Adaptador sandbox. Ningún estado enviado por el navegador confirma un pago. */
class WizPayGateway
{
    public function configured(): bool
    {
        return filled(config('wizpay.key'));
    }

    public function generate(BookingRequest $request): array
    {
        if (! $this->configured()) {
            throw ValidationException::withMessages(['payment' => 'Falta configurar WIZPAY_TEST_API_KEY en el servidor.']);
        }
        $result = Http::acceptJson()->withHeaders(['x-wpay-key' => config('wizpay.key')])->connectTimeout(5)->timeout(20)
            ->post(config('wizpay.base_url').'/qr/generate', ['amount' => $request->amount_minor / 100,
                'order_id' => 'CVS-'.$request->id.'-'.$request->attempt, 'name' => 'Consulta Clínica Vida Salud', 'id_currency' => 1, 'moneda' => 'BOB']);
        $result->throw();
        $data = $result->json();
        if (! is_string($data['reference'] ?? null) || ! isset($data['qr']['numero_referencia'], $data['qr']['imagen']) || ! is_numeric($data['qr']['monto'] ?? null)
            || round(((float) ($data['qr']['monto'] ?? -1)) * 100) !== (float) $request->amount_minor || ($data['qr']['moneda'] ?? '') !== 'BOB') {
            throw new \RuntimeException('Respuesta de pasarela no verificable.');
        }

        return $data;
    }

    public function status(Payment $payment): string
    {
        if (! $payment->reference) {
            return 'unknown';
        }
        $response = Http::acceptJson()->connectTimeout(5)->timeout(15)->get(config('wizpay.base_url').'/status/'.rawurlencode($payment->reference))->throw()->json();
        if (! is_numeric($response['amount'] ?? null) || ($response['reference'] ?? '') !== $payment->reference
            || ($response['order_id'] ?? '') !== 'CVS-'.$payment->booking_request_id.'-'.$payment->attempt
            || (int) round(((float) ($response['amount'] ?? -1)) * 100) !== $payment->amount_minor) {
            return 'unknown';
        }

        return match ($response['status'] ?? '') {
            'APROBADO' => 'confirmed', 'RECHAZADO', 'FALLIDO', 'CANCELADO' => 'failed',
            'PENDIENTE', 'PENDING_3DS' => 'pending', default => 'unknown',
        };
    }
}
