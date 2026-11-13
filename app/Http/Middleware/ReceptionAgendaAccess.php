<?php

/** Entrada HTTP de ReceptionAgendaAccess. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Http\Middleware;

use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\RegistroAuditoria;
use Closure;
use Illuminate\Http\Request;

/** CU24 FA05 y CU25 FA04: denegar y auditar sin copiar datos del formulario. */
class ReceptionAgendaAccess
{
    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()->hasStaffRole('Recepcionista')) {
            app(RegistroAuditoria::class)->registrar('schedules', 'access_denied', resultado: 'denied');
            abort(403);
        }

        return $next($request);
    }
}
