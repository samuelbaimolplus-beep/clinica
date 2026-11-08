<?php

namespace App\Http\Controllers;

use App\Services\Auth\EmailActivation;
use Illuminate\Http\Request;

class AccountActivationController extends Controller
{
    public function __invoke(Request $request, EmailActivation $activation)
    {
        abort_if($request->user(), 409, 'Cierre su sesión antes de activar una cuenta.');
        $data = $request->validate(['email' => 'required|email|max:255', 'code' => 'required|string|regex:/^[0-9]{8}$/D']);
        $activation->confirm($data['email'], $data['code']);

        return response()->json(['message' => 'Correo confirmado. Su cuenta está activada; ya puede iniciar sesión.'])
            ->header('Cache-Control', 'no-store');
    }
}
