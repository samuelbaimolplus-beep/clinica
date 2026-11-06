<?php

namespace App\Services\Patients;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdatePatientContact
{
    public function handle(User $actor, array $data): Patient
    {
        abort_unless($actor->role === 'Paciente' && $actor->status === 'Activo', 403);

        return DB::transaction(function () use ($actor, $data) {
            $patient = Patient::whereKey($actor->patient_id)->lockForUpdate()->firstOrFail();
            abort_unless($patient->status === 'Activo', 403);
            // Solo estas columnas cambian: no se reescriben datos de identidad leídos antes del bloqueo.
            $patient->fill(array_intersect_key($data, array_flip(['phone', 'address'])))->save();

            return $patient;
        });
    }
}
