<?php

/** Entrada HTTP de ControladorPerfilCuenta. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\User;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\ActivacionPorCorreo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ControladorPerfilCuenta extends Controller
{
    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function consultarDetalle(Request $solicitud)
    {
        return response()->json(['user' => $solicitud->user(), 'patient' => $solicitud->user()->patient])
            ->header('Cache-Control', 'no-store');
    }

    /** Actualiza los datos permitidos del titular o registro seleccionado. */
    public function actualizar(Request $solicitud)
    {
        if (is_string($solicitud->input('email'))) {
            $solicitud->merge(['email' => mb_strtolower(trim($solicitud->input('email')))]);
        }
        $esPaciente = $solicitud->user()->role === 'Paciente';
        $datos = $solicitud->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($solicitud->user()->id)],
            'phone' => [$esPaciente ? 'required' : 'prohibited', 'string', 'max:30'],
            'address' => [$esPaciente ? 'nullable' : 'prohibited', 'string', 'max:1000'],
            'current_password' => 'required|string|max:255|current_password',
        ]);
        [$usuario, $cambioCorreo] = DB::transaction(function () use ($solicitud, $datos, $esPaciente) {
            // Mismo orden que el alta de cuenta: paciente, luego usuario.
            $paciente = $esPaciente ? Patient::whereKey($solicitud->user()->patient_id)->lockForUpdate()->firstOrFail() : null;
            $usuario = User::whereKey($solicitud->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($datos['current_password'], $usuario->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
            }
            $cambioCorreo = $usuario->email !== $datos['email'];
            $usuario->fill(['email' => $datos['email']])->save();
            if ($paciente) {
                $paciente->fill(['email' => $datos['email'], 'phone' => $datos['phone'], 'address' => $datos['address'] ?? null])->save();
            }

            return [$usuario, $cambioCorreo];
        });
        if ($cambioCorreo) {
            $enviado = app(ActivacionPorCorreo::class)->enviar($usuario);
            Auth::logout();
            $solicitud->session()->invalidate();
            $solicitud->session()->regenerateToken();

            return response()->json(['user' => null, 'activation_required' => true, 'activation_email_sent' => $enviado,
                'email' => $usuario->email, 'csrf' => csrf_token(), 'message' => 'Datos actualizados. Confirme el código enviado al nuevo correo para volver a acceder.'])
                ->header('Cache-Control', 'no-store');
        }

        return response()->json(['user' => $usuario, 'message' => 'Datos de contacto actualizados.'])->header('Cache-Control', 'no-store');
    }
}
