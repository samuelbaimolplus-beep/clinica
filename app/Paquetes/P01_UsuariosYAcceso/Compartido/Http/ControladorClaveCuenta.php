<?php

/** Entrada HTTP de ControladorClaveCuenta. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\SeguridadSesion;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\RegistroAuditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ControladorClaveCuenta extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $solicitud, SeguridadSesion $sesiones)
    {
        $datos = $solicitud->validate([
            'current_password' => 'required|string|max:255',
            'password' => 'required|string|min:10|max:128|confirmed|different:current_password',
        ]);
        $usuario = DB::transaction(function () use ($solicitud, $datos) {
            $usuario = Usuario::whereKey($solicitud->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($datos['current_password'], $usuario->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
            }
            $usuario->forceFill(['password' => $datos['password'], 'remember_token' => Str::random(60)])->save();
            app(RegistroAuditoria::class)->registrar('auth', 'password_changed', $usuario, responsableId: $usuario->id);

            return $usuario;
        });
        $solicitud->session()->regenerate(true);
        $sesiones->registrarFirmaSesion($solicitud, $usuario);

        return response()->json(['user' => $usuario, 'csrf' => csrf_token(), 'message' => 'Contraseña actualizada. Las demás sesiones deberán iniciar sesión nuevamente.'])
            ->header('Cache-Control', 'no-store');
    }
}
