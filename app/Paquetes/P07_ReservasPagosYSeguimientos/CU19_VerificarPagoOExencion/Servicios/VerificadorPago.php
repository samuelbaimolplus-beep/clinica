<?php

/** Verifica tarifa y evidencia del cobro presencial antes de permitir emitir una ficha o completar el ingreso. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Servicios;

use App\Models\Payment;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** CU19 verifica un cobro; nunca crea una cita ni emite ficha. Montos en centavos de BOB. */
class VerificadorPago
{
    public const INPUT = ['payment_method' => 'nullable|in:cash,qr,transfer', 'payment_confirmed' => 'nullable|boolean',
        'payment_amount_minor' => 'nullable|integer|min:1', 'bank_reference' => 'nullable|string|max:180'];

    /** Obtiene la tarifa vigente desde la especialidad, sin confiar en un importe enviado por el navegador. */
    public function tarifa(int $especialidadId): int
    {
        $importe = Specialty::findOrFail($especialidadId)->fee_minor;
        if ($importe === null || $importe < 1) {
            throw ValidationException::withMessages(['payment' => 'Administración debe definir la tarifa de esta especialidad antes de emitir fichas.']);
        }

        return $importe;
    }

    /** Valida la confirmación y referencia del cobro presencial antes de registrar el pago. */
    public function verificarPagoPresencial(array $datos, int $importe, User $responsable): Payment
    {
        abort_unless($responsable->hasStaffRole('Recepcionista'), 403);
        validator($datos, ['payment_method' => 'required|in:cash,qr,transfer', 'payment_confirmed' => 'required|accepted',
            'payment_amount_minor' => 'required|integer|in:'.$importe,
            'bank_reference' => 'nullable|required_unless:payment_method,cash|string|max:180|unique:payments,bank_reference'])->validate();

        return Payment::create(['method' => $datos['payment_method'], 'amount_minor' => $importe, 'status' => 'confirmed',
            'bank_reference' => $datos['payment_method'] === 'cash' ? null : trim($datos['bank_reference']),
            'confirmed_by' => $responsable->id, 'confirmed_at' => now()]);
    }
}
