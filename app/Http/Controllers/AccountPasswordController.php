<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountPasswordController extends Controller
{
    public function __invoke(Request $request, SessionSecurity $sessions)
    {
        $data = $request->validate([
            'current_password' => 'required|string|max:255',
            'password' => 'required|string|min:10|max:128|confirmed|different:current_password',
        ]);
        $user = DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
            }
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            app(AuditLogger::class)->record('auth', 'password_changed', $user, actorId: $user->id);

            return $user;
        });
        $request->session()->regenerate(true);
        $sessions->remember($request, $user);

        return response()->json(['user' => $user, 'csrf' => csrf_token(), 'message' => 'Contraseña actualizada. Las demás sesiones deberán iniciar sesión nuevamente.'])
            ->header('Cache-Control', 'no-store');
    }
}
