<?php

namespace App\Services\Patients;

use App\Models\Patient;
use App\Models\PatientAccountInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU23 propuesto: el cliente nunca decide rol, estado ni paciente vinculado. */
class RegisterPatient
{
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $patientData = array_intersect_key($data, array_flip([
                'identity', 'first_name', 'last_name', 'birth_date', 'sex', 'phone', 'email', 'address',
            ]));
            $patientData['status'] = 'Activo';
            if (! empty($data['activation_code'])) {
                // El mismo orden de bloqueo que recepción: paciente, luego invitación.
                $invitation = PatientAccountInvitation::where('token_hash', hash('sha256', $data['activation_code']))->first();
                $patient = $invitation ? Patient::whereKey($invitation->patient_id)->lockForUpdate()->first() : null;
                $invitation = $invitation ? PatientAccountInvitation::whereKey($invitation->id)->lockForUpdate()->first() : null;
                if (! $invitation || $invitation->consumed_at || $invitation->expires_at->isPast() || $patient?->status !== 'Activo' || User::where('patient_id', $patient?->id)->exists()) {
                    throw ValidationException::withMessages(['activation_code' => 'El código de vinculación no es válido o ha vencido. Solicite uno nuevo en recepción.']);
                }
                foreach (['first_name', 'last_name', 'birth_date', 'identity'] as $field) {
                    if (mb_strtolower(trim((string) $patient->$field)) !== mb_strtolower(trim((string) ($patientData[$field] ?? '')))) {
                        throw ValidationException::withMessages(['activation_code' => 'Los datos no coinciden con el registro verificado por recepción.']);
                    }
                }
                $invitation->update(['consumed_at' => now()]);
            } else {
                // Nunca apropia un paciente existente basándose solamente en CI o fecha de nacimiento.
                $patient = app(CatalogService::class)->save(new Patient, $patientData);
            }
            $user = User::create([
                'name' => $data['first_name'].' '.$data['last_name'], 'email' => $data['email'],
                'username' => $data['username'] ?? null,
                'password' => $data['password'], 'role' => 'Paciente', 'status' => 'Activo',
                'patient_id' => $patient->id, 'terms_accepted_at' => now(),
                'terms_version' => config('clinic.terms_version'),
            ]);
            app(AuditLogger::class)->record('patients', 'registered_online', $patient,
                details: ['terms_version' => config('clinic.terms_version')], actorId: $user->id);

            return $user;
        });
    }
}
