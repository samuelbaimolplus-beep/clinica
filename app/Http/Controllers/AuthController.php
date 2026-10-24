<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|string|max:255', 'password' => 'required|string|max:255']);
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'FA02: Credenciales incorrectas. Verifique sus datos.']);
        }
        if ($user->status !== 'Activo') {
            throw ValidationException::withMessages(['email' => 'FA03: La cuenta está inactiva. Contacte al administrador.']);
        }
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user, 'csrf' => csrf_token()]);
    }

    public function session(Request $request)
    {
        $user = $request->user();

        return response()->json(['user' => $user?->status === 'Activo' ? $user : null, 'csrf' => csrf_token(), 'today' => now()->toDateString()]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['csrf' => csrf_token()]);
    }
}
