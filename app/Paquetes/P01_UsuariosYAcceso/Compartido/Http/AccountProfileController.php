<?php

/** Entrada HTTP de AccountProfileController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\User;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\EmailActivation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountProfileController extends Controller
{
    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function show(Request $request)
    {
        return response()->json(['user' => $request->user(), 'patient' => $request->user()->patient])
            ->header('Cache-Control', 'no-store');
    }

    /** Actualiza los datos permitidos del titular o registro seleccionado. */
    public function update(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $isPatient = $request->user()->role === 'Paciente';
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($request->user()->id)],
            'phone' => [$isPatient ? 'required' : 'prohibited', 'string', 'max:30'],
            'address' => [$isPatient ? 'nullable' : 'prohibited', 'string', 'max:1000'],
            'current_password' => 'required|string|max:255|current_password',
        ]);
        [$user, $emailChanged] = DB::transaction(function () use ($request, $data, $isPatient) {
            // Mismo orden que el alta de cuenta: paciente, luego usuario.
            $patient = $isPatient ? Patient::whereKey($request->user()->patient_id)->lockForUpdate()->firstOrFail() : null;
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
            }
            $emailChanged = $user->email !== $data['email'];
            $user->fill(['email' => $data['email']])->save();
            if ($patient) {
                $patient->fill(['email' => $data['email'], 'phone' => $data['phone'], 'address' => $data['address'] ?? null])->save();
            }

            return [$user, $emailChanged];
        });
        if ($emailChanged) {
            $sent = app(EmailActivation::class)->send($user);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['user' => null, 'activation_required' => true, 'activation_email_sent' => $sent,
                'email' => $user->email, 'csrf' => csrf_token(), 'message' => 'Datos actualizados. Confirme el código enviado al nuevo correo para volver a acceder.'])
                ->header('Cache-Control', 'no-store');
        }

        return response()->json(['user' => $user, 'message' => 'Datos de contacto actualizados.'])->header('Cache-Control', 'no-store');
    }
}
