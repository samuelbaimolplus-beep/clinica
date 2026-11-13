<?php

/** Entrada HTTP de AccountActivationController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\EmailActivation;
use Illuminate\Http\Request;

class AccountActivationController extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $solicitud, EmailActivation $activacion)
    {
        abort_if($solicitud->user(), 409, 'Cierre su sesión antes de activar una cuenta.');
        $datos = $solicitud->validate(['email' => 'required|email|max:255', 'code' => 'required|string|regex:/^[0-9]{8}$/D']);
        $activacion->confirmar($datos['email'], $datos['code']);

        return response()->json(['message' => 'Correo confirmado. Su cuenta está activada; ya puede iniciar sesión.'])
            ->header('Cache-Control', 'no-store');
    }
}
