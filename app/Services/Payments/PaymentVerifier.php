<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** CU19 verifica un cobro; nunca crea una cita ni emite ficha. Montos en centavos de BOB. */
class PaymentVerifier
{
    public const INPUT = ['payment_method' => 'nullable|in:cash,qr,transfer', 'payment_confirmed' => 'nullable|boolean',
        'payment_amount_minor' => 'nullable|integer|min:1', 'bank_reference' => 'nullable|string|max:180'];

    public function tariff(int $specialtyId): int
    {
        $amount = Specialty::findOrFail($specialtyId)->fee_minor;
        if ($amount === null || $amount < 1) {
            throw ValidationException::withMessages(['payment' => 'Administración debe definir la tarifa de esta especialidad antes de emitir fichas.']);
        }

        return $amount;
    }

    public function reception(array $data, int $amount, User $actor): Payment
    {
        abort_unless($actor->role === 'Recepcionista', 403);
        validator($data, ['payment_method' => 'required|in:cash,qr,transfer', 'payment_confirmed' => 'required|accepted',
            'payment_amount_minor' => 'required|integer|in:'.$amount,
            'bank_reference' => 'nullable|required_unless:payment_method,cash|string|max:180|unique:payments,bank_reference'])->validate();

        return Payment::create(['method' => $data['payment_method'], 'amount_minor' => $amount, 'status' => 'confirmed',
            'bank_reference' => $data['payment_method'] === 'cash' ? null : trim($data['bank_reference']),
            'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
    }
}
