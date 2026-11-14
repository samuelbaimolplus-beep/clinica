<?php

/** Inicio y consulta de sesión: comprueba credenciales, edad, consentimiento y activación antes de permitir acceso. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU01_IniciarSesion\Http;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\ActivacionPorCorreo;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\RequisitosCuenta;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\SeguridadSesion;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\RegistroAuditoria;
use App\Support\AccesoClinica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ControladorAutenticacion extends Controller
{
    /** Verifica credenciales y requisitos de acceso antes de regenerar e iniciar la sesión. */
    public function iniciarSesion(Request $solicitud)
    {
        abort_if($solicitud->user(), 409, 'Ya tiene una sesión activa. Ciérrela antes de iniciar otra.');
        $datos = $solicitud->validate(['email' => 'required|string|max:255', 'password' => 'required|string|max:255']);
        $identificador = mb_strtolower(trim($datos['email']));
        $columna = str_contains($identificador, '@') ? 'email' : 'username';
        $usuario = Usuario::whereRaw('lower('.$columna.') = ?', [$identificador])->first();
        if (! $usuario || ! Hash::check($datos['password'], $usuario->password)) {
            app(RegistroAuditoria::class)->registrar('auth', 'login', resultado: 'failure', detalles: [
                'reason' => 'invalid_credentials', 'record' => [$columna => $identificador],
            ]);
            throw ValidationException::withMessages(['email' => 'FA02: Credenciales incorrectas. Verifique sus datos.']);
        }
        if (! AccesoClinica::estaActivo($usuario)) {
            app(RegistroAuditoria::class)->registrar('auth', 'login', $usuario, 'failure', ['reason' => 'inactive_account']);
            throw ValidationException::withMessages(['email' => 'FA03: La cuenta está inactiva. Contacte al administrador.']);
        }
        $requisitosAcceso = app(RequisitosCuenta::class);
        $fechaNacimiento = $requisitosAcceso->fechaNacimiento($usuario);
        if ($fechaNacimiento && ! $requisitosAcceso->esMayorDeEdad($fechaNacimiento)) {
            return response()->json(['message' => 'El acceso personal requiere 18 años cumplidos. Recepción puede gestionar su atención con su ficha de paciente.'], 403)->header('Cache-Control', 'no-store');
        }
        if (! $fechaNacimiento && $usuario->role === 'Paciente') {
            return response()->json(['message' => 'Recepción debe verificar la fecha de nacimiento de su ficha antes de habilitar el acceso. La atención presencial no requiere cuenta.'], 403)->header('Cache-Control', 'no-store');
        }
        $requiereFechaNacimiento = ! $fechaNacimiento;
        $requiereConsentimiento = $requisitosAcceso->requiereConsentimiento($usuario);
        if (($requiereFechaNacimiento && ! $solicitud->filled('birth_date')) || ($requiereConsentimiento && ! $solicitud->boolean('terms_accepted'))) {
            return response()->json(['message' => 'Complete la verificación de su cuenta para continuar.',
                'eligibility_required' => true, 'birth_date_required' => $requiereFechaNacimiento,
                'terms_required' => $requiereConsentimiento, 'terms_version' => config('clinic.terms_version')], 403)->header('Cache-Control', 'no-store');
        }
        if ($requiereFechaNacimiento) {
            $fechas = $solicitud->validate(['birth_date' => 'required|date_format:Y-m-d|before_or_equal:'.today()->subYearsNoOverflow(18)->toDateString()]);
            $usuario->birth_date = $fechas['birth_date'];
        }
        if ($requiereConsentimiento) {
            $solicitud->validate(['terms_accepted' => 'required|accepted', 'terms_version' => 'required|in:'.config('clinic.terms_version')]);
            $usuario->terms_accepted_at = now();
            $usuario->terms_version = config('clinic.terms_version');
        }
        if ($usuario->isDirty()) {
            $usuario->save();
        }
        if (! $usuario->email_verified_at) {
            app(ActivacionPorCorreo::class)->enviar($usuario);

            return response()->json(['message' => 'Debe activar su cuenta con el código enviado a su correo.',
                'activation_required' => true, 'email' => $usuario->email], 403)->header('Cache-Control', 'no-store');
        }
        try {
            Auth::login($usuario);
            $solicitud->session()->regenerate();
            app(SeguridadSesion::class)->registrarFirmaSesion($solicitud, $usuario);
            app(RegistroAuditoria::class)->registrar('auth', 'login', $usuario);
        } catch (\Throwable $excepcion) {
            // CU01 FA04: incluso un fallo posterior al login debe dejar al visitante sin autenticar.
            $solicitud->session()->forget([Auth::guard()->getName(), 'clinic']);
            Auth::guard()->forgetUser();
            report($excepcion);

            return response()->json(['message' => 'No fue posible iniciar sesión. Intente nuevamente.', 'csrf' => csrf_token()], 503);
        }

        return response()->json(['user' => $usuario, 'csrf' => csrf_token()])->header('Cache-Control', 'no-store');
    }

    /** Devuelve identidad, permisos y CSRF vigentes; revoca primero una sesión que dejó de ser válida. */
    public function consultarSesion(Request $solicitud)
    {
        $mensaje = app(SeguridadSesion::class)->validarSesion($solicitud);
        $usuario = $solicitud->user();

        return response()->json(['user' => $usuario, 'csrf' => csrf_token(), 'today' => now()->toDateString(),
            'access' => $usuario ? collect(array_keys(AccesoClinica::VIEWS))->mapWithKeys(fn ($rol) => [$rol => AccesoClinica::vistasPermitidas($rol)]) : (object) [],
            'terms_version' => config('clinic.terms_version'), 'message' => $mensaje])->header('Cache-Control', 'no-store');
    }
}
