<?php

/** Determina si un seguimiento conserva su gratuidad según origen, fecha sugerida y causa del cambio. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\CU21_RegistrarCitaDeSeguimiento\Servicios;

use App\Models\Appointment;
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** CU21: se ejecuta dentro de la transacción de programación de P05. */
class FollowupPolicy
{
    /** Evalúa la política aplicable a la operación sin sustituir la autorización del actor. */
    public function evaluate(array $data, ?int $id, Patient $patient, int $specialtyId): array
    {
        $parent = null;
        $free = false;
        if (! $id && ! empty($data['parent_appointment_id'])) {
            $parent = Appointment::whereKey($data['parent_appointment_id'])->lockForUpdate()->firstOrFail();
            if ($parent->status !== 'Atendida' || $parent->patient_id !== $patient->id || $parent->specialty_id !== $specialtyId || ! $parent->followup_suggested_at) {
                throw ValidationException::withMessages(['appointment' => 'El seguimiento requiere una consulta atendida y una recomendación médica de la misma especialidad.']);
            }
            $suggested = CarbonImmutable::parse($parent->followup_suggested_at);
            $free = $suggested->format('Y-m-d H:i') === $data['date'].' '.$data['start_time'];
            if (! $free && ($data['followup_change_cause'] ?? 'patient') === 'clinic') {
                validator($data, ['followup_change_reason' => 'required|string|max:3000'])->validate();
                $free = true;
            }
        }

        return [$parent, $free];
    }
}
