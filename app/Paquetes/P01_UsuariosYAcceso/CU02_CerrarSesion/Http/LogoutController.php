<?php

/** Entrada HTTP de LogoutController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU02_CerrarSesion\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    /** Invalida la sesión actual y renueva la protección contra peticiones falsificadas. */
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
