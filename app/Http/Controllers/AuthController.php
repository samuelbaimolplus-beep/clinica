<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionSecurity;
use App\Support\ClinicAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        abort_if($request->user(), 409, 'Ya tiene una sesión activa. Ciérrela antes de iniciar otra.');
        $data = $request->validate(['email' => 'required|string|max:255', 'password' => 'required|string|max:255']);
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            app(AuditLogger::class)->record('auth', 'login', result: 'failure', details: ['reason' => 'invalid_credentials']);
            throw ValidationException::withMessages(['email' => 'FA02: Credenciales incorrectas. Verifique sus datos.']);
        }
        if (! ClinicAccess::isActive($user)) {
            app(AuditLogger::class)->record('auth', 'login', $user, 'failure', ['reason' => 'inactive_account']);
            throw ValidationException::withMessages(['email' => 'FA03: La cuenta está inactiva. Contacte al administrador.']);
        }
        if ($user->role === 'Paciente' && ! $user->email_verified_at) {
            throw ValidationException::withMessages(['email' => 'Verifique su correo electrónico antes de iniciar sesión. Puede solicitar el enlace desde Recuperar acceso.']);
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

        return response()->json(['user' => $user, 'csrf' => csrf_token()]);
    }

    public function session(Request $request)
    {
        $message = app(SessionSecurity::class)->validate($request);
        $user = $request->user();

        return response()->json(['user' => $user, 'csrf' => csrf_token(), 'today' => now()->toDateString(),
            'access' => collect(array_keys(ClinicAccess::VIEWS))->mapWithKeys(fn ($role) => [$role => ClinicAccess::views($role)]),
            'terms_version' => config('clinic.terms_version'), 'message' => $message]);
    }

    public function logout(Request $request)
    {
        if (! $request->user()) {
            return response()->json(['message' => 'La sesión ya finalizó. Debe iniciar sesión nuevamente.', 'csrf' => csrf_token()], 401);
        }
        try {
            app(AuditLogger::class)->record('auth', 'logout', $request->user());
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'No se pudo completar el cierre de sesión. Intente nuevamente.'], 503);
        }

        return response()->json(['csrf' => csrf_token()]);
    }
}
