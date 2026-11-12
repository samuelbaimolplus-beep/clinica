<?php

/** Inicio y consulta de sesión: comprueba credenciales, edad, consentimiento y activación antes de permitir acceso. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU01_IniciarSesion\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\AccountEligibility;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\EmailActivation;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\SessionSecurity;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use App\Support\ClinicAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Verifica credenciales y requisitos de acceso antes de regenerar e iniciar la sesión. */
    public function login(Request $request)
    {
        abort_if($request->user(), 409, 'Ya tiene una sesión activa. Ciérrela antes de iniciar otra.');
        $data = $request->validate(['email' => 'required|string|max:255', 'password' => 'required|string|max:255']);
        $identifier = mb_strtolower(trim($data['email']));
        $column = str_contains($identifier, '@') ? 'email' : 'username';
        $user = User::whereRaw('lower('.$column.') = ?', [$identifier])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            app(AuditLogger::class)->record('auth', 'login', result: 'failure', details: [
                'reason' => 'invalid_credentials', 'record' => [$column => $identifier],
            ]);
            throw ValidationException::withMessages(['email' => 'FA02: Credenciales incorrectas. Verifique sus datos.']);
        }
        if (! ClinicAccess::isActive($user)) {
            app(AuditLogger::class)->record('auth', 'login', $user, 'failure', ['reason' => 'inactive_account']);
            throw ValidationException::withMessages(['email' => 'FA03: La cuenta está inactiva. Contacte al administrador.']);
        }
        $eligibility = app(AccountEligibility::class);
        $birthDate = $eligibility->birthDate($user);
        if ($birthDate && ! $eligibility->adult($birthDate)) {
            return response()->json(['message' => 'El acceso personal requiere 18 años cumplidos. Recepción puede gestionar su atención con su ficha de paciente.'], 403)->header('Cache-Control', 'no-store');
        }
        if (! $birthDate && $user->role === 'Paciente') {
            return response()->json(['message' => 'Recepción debe verificar la fecha de nacimiento de su ficha antes de habilitar el acceso. La atención presencial no requiere cuenta.'], 403)->header('Cache-Control', 'no-store');
        }
        $needsBirthDate = ! $birthDate;
        $needsConsent = $eligibility->needsConsent($user);
        if (($needsBirthDate && ! $request->filled('birth_date')) || ($needsConsent && ! $request->boolean('terms_accepted'))) {
            return response()->json(['message' => 'Complete la verificación de su cuenta para continuar.',
                'eligibility_required' => true, 'birth_date_required' => $needsBirthDate,
                'terms_required' => $needsConsent, 'terms_version' => config('clinic.terms_version')], 403)->header('Cache-Control', 'no-store');
        }
        if ($needsBirthDate) {
            $dates = $request->validate(['birth_date' => 'required|date_format:Y-m-d|before_or_equal:'.today()->subYearsNoOverflow(18)->toDateString()]);
            $user->birth_date = $dates['birth_date'];
        }
        if ($needsConsent) {
            $request->validate(['terms_accepted' => 'required|accepted', 'terms_version' => 'required|in:'.config('clinic.terms_version')]);
            $user->terms_accepted_at = now();
            $user->terms_version = config('clinic.terms_version');
        }
        if ($user->isDirty()) {
            $user->save();
        }
        if (! $user->email_verified_at) {
            app(EmailActivation::class)->send($user);

            return response()->json(['message' => 'Debe activar su cuenta con el código enviado a su correo.',
                'activation_required' => true, 'email' => $user->email], 403)->header('Cache-Control', 'no-store');
        }
        try {
            Auth::login($user);
            $request->session()->regenerate();
            app(SessionSecurity::class)->remember($request, $user);
            app(AuditLogger::class)->record('auth', 'login', $user);
        } catch (\Throwable $exception) {
            // CU01 FA04: incluso un fallo posterior al login debe dejar al visitante sin autenticar.
            $request->session()->forget([Auth::guard()->getName(), 'clinic']);
            Auth::guard()->forgetUser();
            report($exception);

            return response()->json(['message' => 'No fue posible iniciar sesión. Intente nuevamente.', 'csrf' => csrf_token()], 503);
        }

        return response()->json(['user' => $user, 'csrf' => csrf_token()])->header('Cache-Control', 'no-store');
    }

    /** Devuelve identidad, permisos y CSRF vigentes; revoca primero una sesión que dejó de ser válida. */
    public function session(Request $request)
    {
        $message = app(SessionSecurity::class)->validate($request);
        $user = $request->user();

        return response()->json(['user' => $user, 'csrf' => csrf_token(), 'today' => now()->toDateString(),
            'access' => $user ? collect(array_keys(ClinicAccess::VIEWS))->mapWithKeys(fn ($role) => [$role => ClinicAccess::views($role)]) : (object) [],
            'terms_version' => config('clinic.terms_version'), 'message' => $message])->header('Cache-Control', 'no-store');
    }
}
