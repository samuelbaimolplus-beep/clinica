<?php

/** Entrada HTTP de ControladorNombreUsuario. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\SeguridadSesion;
use App\Support\Username;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ControladorNombreUsuario extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $solicitud, SeguridadSesion $sesiones)
    {
        if (is_string($solicitud->input('username'))) {
            $solicitud->merge(['username' => mb_strtolower(trim($solicitud->input('username')))]);
        }
        $datos = $solicitud->validate([
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:'.Username::PATTERN, Rule::unique('users', 'username')->ignore($solicitud->user()->id)],
            'current_password' => ['required', 'string', 'max:255', 'current_password'],
        ], ['current_password.current_password' => 'La contraseña actual no es correcta.',
            'username.unique' => 'Ese usuario de acceso ya está ocupado. Elige otro.',
            'username.regex' => 'Usa letras sin acentos, números, punto, guion o guion bajo; comienza con una letra o número.']);

        $usuario = DB::transaction(function () use ($solicitud, $datos) {
            $usuario = User::whereKey($solicitud->user()->id)->lockForUpdate()->firstOrFail();
            $usuario->username = $datos['username'];
            $usuario->save();

            return $usuario;
        });
        // La sesión que confirmó su contraseña continúa; las otras firmas antiguas se revocan.
        $solicitud->session()->regenerate(true);
        $sesiones->registrarFirmaSesion($solicitud, $usuario);

        return response()->json(['user' => $usuario, 'csrf' => csrf_token(), 'message' => 'Tu usuario de acceso se actualizó correctamente.'])
            ->header('Cache-Control', 'no-store');
    }
}
