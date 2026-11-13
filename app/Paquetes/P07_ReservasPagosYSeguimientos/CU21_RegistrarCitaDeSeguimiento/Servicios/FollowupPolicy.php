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
    public function evaluar(array $datos, ?int $id, Patient $paciente, int $especialidadId): array
    {
        $citaOrigen = null;
        $esGratuita = false;
        if (! $id && ! empty($datos['parent_appointment_id'])) {
            $citaOrigen = Appointment::whereKey($datos['parent_appointment_id'])->lockForUpdate()->firstOrFail();
            if ($citaOrigen->status !== 'Atendida' || $citaOrigen->patient_id !== $paciente->id || $citaOrigen->specialty_id !== $especialidadId || ! $citaOrigen->followup_suggested_at) {
                throw ValidationException::withMessages(['appointment' => 'El seguimiento requiere una consulta atendida y una recomendación médica de la misma especialidad.']);
            }
            $fechaSugerida = CarbonImmutable::parse($citaOrigen->followup_suggested_at);
            $esGratuita = $fechaSugerida->format('Y-m-d H:i') === $datos['date'].' '.$datos['start_time'];
            if (! $esGratuita && ($datos['followup_change_cause'] ?? 'patient') === 'clinic') {
                validator($datos, ['followup_change_reason' => 'required|string|max:3000'])->validate();
                $esGratuita = true;
            }
        }

        return [$citaOrigen, $esGratuita];
    }
}
