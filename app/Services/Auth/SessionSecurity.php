<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ClinicAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** CU01–CU02: una sesión deja de ser válida si cambian sus credenciales o permisos. */
class SessionSecurity
{
    public function remember(Request $request, User $user): void
    {
        $request->session()->put('clinic.auth_user_id', $user->id);
        $request->session()->put('clinic.auth_fingerprint', $this->fingerprint($user));
    }

    public function fingerprint(User $user): string
    {
        // La sesión guarda una firma; nunca una copia de la contraseña o su hash.
        return hash_hmac('sha256', json_encode([
            $user->id, $user->username, $user->password, $user->role, $user->doctor_id, $user->patient_id,
        ], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function validate(Request $request): ?string
    {
        if (! $request->user()) {
            return null;
        }
        $user = $request->user()->fresh();
        $reason = null;
        if (! $user || ! ClinicAccess::isActive($user)) {
            $reason = 'inactive_account';
        } elseif ($user->role === 'Paciente' && ! $user->email_verified_at) {
            $reason = 'email_unverified';
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
