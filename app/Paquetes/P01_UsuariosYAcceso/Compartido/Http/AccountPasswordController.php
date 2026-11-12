<?php

/** Entrada HTTP de AccountPasswordController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\SessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountPasswordController extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $request, SessionSecurity $sessions)
    {
        $data = $request->validate([
            'current_password' => 'required|string|max:255',
            'password' => 'required|string|min:10|max:128|confirmed|different:current_password',
        ]);
        $user = DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
            }
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            app(AuditLogger::class)->record('auth', 'password_changed', $user, actorId: $user->id);

            return $user;
        });
        $request->session()->regenerate(true);
        $sessions->remember($request, $user);

        return response()->json(['user' => $user, 'csrf' => csrf_token(), 'message' => 'Contraseña actualizada. Las demás sesiones deberán iniciar sesión nuevamente.'])
            ->header('Cache-Control', 'no-store');
    }
}
