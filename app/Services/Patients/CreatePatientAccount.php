<?php

namespace App\Services\Patients;

use App\Models\Patient;
use App\Models\PatientAccountInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePatientAccount
{
    public function register(array $data, User $actor): Patient
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $actor) {
            $patientData = array_intersect_key($data, array_flip([
                'identity', 'first_name', 'last_name', 'phone', 'birth_date', 'sex', 'email', 'address',
            ]));
            $patient = app(CatalogService::class)->save(new Patient, $patientData + ['status' => 'Activo'], $actor);
            $this->link($patient, $data, $actor);

            return $patient->fresh()->load('account:id,patient_id,username,email,status,email_verified_at');
        });
    }

    public function link(Patient $patient, array $data, User $actor): User
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($patient, $data, $actor) {
            $patient = Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            if ($patient->status !== 'Activo' || $patient->account()->exists()) {
                throw ValidationException::withMessages(['patient' => 'El paciente está inactivo o ya tiene una cuenta de acceso.']);
            }
            if (! filter_var($data['identity_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages(['identity_verified' => 'Confirme la identidad y el correo del paciente.']);
            }
            $email = mb_strtolower(trim($data['email']));
            $patient->fill(['email' => $email])->save();
            $user = new User([
                'name' => $patient->first_name.' '.$patient->last_name,
                'email' => $email, 'username' => mb_strtolower(trim($data['username'])),
                'password' => $data['password'], 'role' => 'Paciente', 'status' => 'Activo', 'patient_id' => $patient->id,
            ]);
            // La cuenta permanece sin verificar hasta confirmar el código recibido por correo.
            $user->save();
            PatientAccountInvitation::where('patient_id', $patient->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            app(AuditLogger::class)->record('patients', 'account_created_by_staff', $patient, actorId: $actor->id);

            return $user;
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->status === 'Activo' && $actor->hasStaffRole('Administrador', 'Recepcionista'), 403);
    }
}
