<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionSecurity;
use Closure;
use Illuminate\Http\Request;

class ClinicSession
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()) {
            return response()->json(['message' => 'Debe iniciar sesión.', 'csrf' => csrf_token()], 401);
        }
        if ($message = app(SessionSecurity::class)->validate($request)) {
            return response()->json(['message' => $message, 'csrf' => csrf_token()], 401);
        }

        return $next($request);
    }
}
