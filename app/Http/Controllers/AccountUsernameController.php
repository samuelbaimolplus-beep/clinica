<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\SessionSecurity;
use App\Support\Username;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountUsernameController extends Controller
{
    public function __invoke(Request $request, SessionSecurity $sessions)
    {
        if (is_string($request->input('username'))) {
            $request->merge(['username' => mb_strtolower(trim($request->input('username')))]);
        }
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:'.Username::PATTERN, Rule::unique('users', 'username')->ignore($request->user()->id)],
            'current_password' => ['required', 'string', 'max:255', 'current_password'],
        ], ['current_password.current_password' => 'La contraseña actual no es correcta.',
            'username.unique' => 'Ese usuario de acceso ya está ocupado. Elige otro.',
            'username.regex' => 'Usa letras sin acentos, números, punto, guion o guion bajo; comienza con una letra o número.']);

        $user = DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $user->username = $data['username'];
            $user->save();

            return $user;
        });
        // La sesión que confirmó su contraseña continúa; las otras firmas antiguas se revocan.
        $request->session()->regenerate(true);
        $sessions->remember($request, $user);

        return response()->json(['user' => $user, 'csrf' => csrf_token(), 'message' => 'Tu usuario de acceso se actualizó correctamente.'])
            ->header('Cache-Control', 'no-store');
    }
}
