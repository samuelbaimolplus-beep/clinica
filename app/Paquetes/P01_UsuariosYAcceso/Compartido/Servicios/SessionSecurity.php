<?php

/** Revocación de sesiones cuando cambian credenciales, permisos, activación o elegibilidad del titular. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios;

use App\Models\User;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use App\Support\ClinicAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** CU01–CU02: una sesión deja de ser válida si cambian sus credenciales o permisos. */
class SessionSecurity
{
    /** Guarda la firma vigente de la cuenta autenticada para detectar cambios posteriores. */
    public function remember(Request $request, User $user): void
    {
        $request->session()->put('clinic.auth_user_id', $user->id);
        $request->session()->put('clinic.auth_fingerprint', $this->fingerprint($user));
    }

    /** Calcula una firma de credenciales y permisos sin guardar su contenido en la sesión. */
    public function fingerprint(User $user): string
    {
        // La sesión guarda una firma; nunca una copia de la contraseña o su hash.
        return hash_hmac('sha256', json_encode([
            $user->id, $user->username, $user->password, $user->role, $user->doctor_id, $user->patient_id, $user->birth_date, $user->terms_version,
        ], JSON_THROW_ON_ERROR), config('app.key'));
    }

    /** Revalida la sesión frente al usuario actual y la revoca cuando dejó de cumplir las reglas. */
    public function validate(Request $request): ?string
    {
        if (! $request->user()) {
            return null;
        }
        $user = $request->user()->fresh();
        $reason = null;
        if (! $user || ! ClinicAccess::isActive($user)) {
            $reason = 'inactive_account';
        } elseif (! $user->email_verified_at) {
            $reason = 'email_unverified';
        } elseif (! app(AccountEligibility::class)->ready($user)) {
            $reason = 'account_eligibility';
        } elseif ($request->session()->get('clinic.auth_user_id') === $user->id
            && $request->session()->has('clinic.auth_fingerprint')
            && ! hash_equals($request->session()->get('clinic.auth_fingerprint'), $this->fingerprint($user))) {
            $reason = 'credentials_or_permissions_changed';
        }

        if ($reason) {
            app(AuditLogger::class)->record('auth', 'session_revoked', $user, details: ['reason' => $reason]);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($reason === 'account_eligibility') {
                return 'Inicie sesión para verificar la edad y los términos de su cuenta. La atención en Recepción no requiere cuenta de paciente.';
            }
            return $reason === 'email_unverified' ? 'Verifique su correo antes de iniciar sesión.' : ($reason === 'inactive_account'
                ? 'La cuenta está inactiva. Contacte al administrador.'
                : 'Sus credenciales o permisos cambiaron. Inicie sesión nuevamente.');
        }

        Auth::setUser($user);
        // Las sesiones anteriores a esta mejora establecen su firma en el primer acceso válido.
        $this->remember($request, $user);

        return null;
    }
}
