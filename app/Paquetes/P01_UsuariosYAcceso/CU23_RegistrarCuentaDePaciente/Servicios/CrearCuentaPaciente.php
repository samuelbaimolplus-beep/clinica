<?php

/** Alta presencial y opcional del portal de un paciente adulto, con identidad y consentimiento verificados. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Servicios;

use App\Compartido\Catalogos\Servicios\ServicioCatalogos;
use App\Models\Patient;
use App\Models\PatientAccountInvitation;
use App\Models\User;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\RequisitosCuenta;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\RegistroAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrearCuentaPaciente
{
    /** Registra dependencias o el alta asistida según la responsabilidad de esta clase. */
    public function registrar(array $datos, User $responsable): Patient
    {
        $this->autorizarResponsable($responsable);

        return DB::transaction(function () use ($datos, $responsable) {
            $datosPaciente = array_intersect_key($datos, array_flip([
                'identity', 'first_name', 'last_name', 'phone', 'birth_date', 'sex', 'email', 'address',
            ]));
            $paciente = app(ServicioCatalogos::class)->guardar(new Patient, $datosPaciente + ['status' => 'Activo'], $responsable);
            $this->vincular($paciente, $datos, $responsable);

            return $paciente->fresh()->load('account:id,patient_id,username,email,status,email_verified_at');
        });
    }

    /** Vincula una cuenta opcional a una ficha existente después de verificar edad, identidad y consentimiento. */
    public function vincular(Patient $paciente, array $datos, User $responsable): User
    {
        $this->autorizarResponsable($responsable);

        return DB::transaction(function () use ($paciente, $datos, $responsable) {
            $paciente = Patient::whereKey($paciente->id)->lockForUpdate()->firstOrFail();
            if ($paciente->status !== 'Activo' || $paciente->account()->exists()) {
                throw ValidationException::withMessages(['patient' => 'El paciente está inactivo o ya tiene una cuenta de acceso.']);
            }
            app(RequisitosCuenta::class)->exigirMayoriaDeEdad($paciente->birth_date);
            validator($datos, ['terms_accepted' => 'required|accepted', 'terms_version' => 'required|in:'.config('clinic.terms_version')])->validate();
            if (! filter_var($datos['identity_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages(['identity_verified' => 'Confirme la identidad y el correo del paciente.']);
            }
            $correo = mb_strtolower(trim($datos['email']));
            $paciente->fill(['email' => $correo])->save();
            $usuario = new User([
                'name' => $paciente->first_name.' '.$paciente->last_name,
                'email' => $correo, 'username' => mb_strtolower(trim($datos['username'])),
                'password' => $datos['password'], 'role' => 'Paciente', 'status' => 'Activo', 'patient_id' => $paciente->id,
                'birth_date' => $paciente->birth_date, 'terms_accepted_at' => now(), 'terms_version' => config('clinic.terms_version'),
            ]);
            // La cuenta permanece sin verificar hasta confirmar el código recibido por correo.
            $usuario->save();
            PatientAccountInvitation::where('patient_id', $paciente->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            app(RegistroAuditoria::class)->registrar('patients', 'account_created_by_staff', $paciente, responsableId: $responsable->id);
            app(RegistroAuditoria::class)->registrar('patients', 'portal_consent_recorded', $paciente, detalles: ['terms_version' => config('clinic.terms_version')], responsableId: $responsable->id);

            return $usuario;
        });
    }

    /** Decide si la solicitud puede continuar; complementa los controles de las rutas. */
    private function autorizarResponsable(User $responsable): void
    {
        abort_unless($responsable->status === 'Activo' && $responsable->hasStaffRole('Administrador', 'Recepcionista'), 403);
    }
}
