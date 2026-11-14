<?php

/** Revocación de sesiones cuando cambian credenciales, permisos, activación o elegibilidad del titular. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios;

use App\Models\Usuario;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\RegistroAuditoria;
use App\Support\AccesoClinica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** CU01–CU02: una sesión deja de ser válida si cambian sus credenciales o permisos. */
class SeguridadSesion
{
    /** Guarda la firma vigente de la cuenta autenticada para detectar cambios posteriores. */
    public function registrarFirmaSesion(Request $solicitud, Usuario $usuario): void
    {
        $solicitud->session()->put('clinic.auth_user_id', $usuario->id);
        $solicitud->session()->put('clinic.auth_fingerprint', $this->calcularFirma($usuario));
    }

    /** Calcula una firma de credenciales y permisos sin guardar su contenido en la sesión. */
    public function calcularFirma(Usuario $usuario): string
    {
        // La sesión guarda una firma; nunca una copia de la contraseña o su hash.
        return hash_hmac('sha256', json_encode([
            $usuario->id, $usuario->username, $usuario->password, $usuario->role, $usuario->doctor_id, $usuario->patient_id, $usuario->birth_date, $usuario->terms_version,
        ], JSON_THROW_ON_ERROR), config('app.key'));
    }

    /** Revalida la sesión frente al usuario actual y la revoca cuando dejó de cumplir las reglas. */
    public function validarSesion(Request $solicitud): ?string
    {
        if (! $solicitud->user()) {
            return null;
        }
        $usuario = $solicitud->user()->fresh();
        $motivo = null;
        if (! $usuario || ! AccesoClinica::estaActivo($usuario)) {
            $motivo = 'inactive_account';
        } elseif (! $usuario->email_verified_at) {
            $motivo = 'email_unverified';
        } elseif (! app(RequisitosCuenta::class)->cumpleRequisitos($usuario)) {
            $motivo = 'account_eligibility';
        } elseif ($solicitud->session()->get('clinic.auth_user_id') === $usuario->id
            && $solicitud->session()->has('clinic.auth_fingerprint')
            && ! hash_equals($solicitud->session()->get('clinic.auth_fingerprint'), $this->calcularFirma($usuario))) {
            $motivo = 'credentials_or_permissions_changed';
        }

        if ($motivo) {
            app(RegistroAuditoria::class)->registrar('auth', 'session_revoked', $usuario, detalles: ['reason' => $motivo]);
            Auth::logout();
            $solicitud->session()->invalidate();
            $solicitud->session()->regenerateToken();

            if ($motivo === 'account_eligibility') {
                return 'Inicie sesión para verificar la edad y los términos de su cuenta. La atención en Recepción no requiere cuenta de paciente.';
            }

            return $motivo === 'email_unverified' ? 'Verifique su correo antes de iniciar sesión.' : ($motivo === 'inactive_account'
                ? 'La cuenta está inactiva. Contacte al administrador.'
                : 'Sus credenciales o permisos cambiaron. Inicie sesión nuevamente.');
        }

        Auth::setUser($usuario);
        // Las sesiones anteriores a esta mejora establecen su firma en el primer acceso válido.
        $this->registrarFirmaSesion($solicitud, $usuario);

        return null;
    }
}
