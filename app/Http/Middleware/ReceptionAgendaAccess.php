<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;

/** CU24 FA05 y CU25 FA04: denegar y auditar sin copiar datos del formulario. */
class ReceptionAgendaAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()->hasStaffRole('Recepcionista')) {
            app(AuditLogger::class)->record('schedules', 'access_denied', result: 'denied');
            abort(403);
        }

        return $next($request);
    }
}
