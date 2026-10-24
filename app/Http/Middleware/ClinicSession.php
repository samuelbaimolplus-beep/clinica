<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClinicSession
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()) {
            return response()->json(['message' => 'Debe iniciar sesión.'], 401);
        }
        if ($request->user()->status !== 'Activo') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'La cuenta está inactiva. Contacte al administrador.'], 401);
        }

        return $next($request);
    }
}
