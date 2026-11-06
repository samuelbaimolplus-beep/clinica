<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Services\Auth\ChangePassword;
use App\Services\Auth\SessionSecurity;
use Illuminate\Support\Facades\Auth;

class PasswordController extends Controller
{
    public function __invoke(ChangePasswordRequest $request, ChangePassword $service, SessionSecurity $security)
    {
        $user = $service->handle($request->user(), $request->validated());
        Auth::setUser($user);
        $request->session()->regenerate(true);
        $security->remember($request, $user);

        return response()->json(['message' => 'Contraseña actualizada. Las otras sesiones deberán iniciar sesión nuevamente.',
            'csrf' => csrf_token()]);
    }
}
