<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireAppointments
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('clinic.appointments_enabled'), 404, 'El módulo de citas está pendiente de integrar pagos y cupos.');

        return $next($request);
    }
}
