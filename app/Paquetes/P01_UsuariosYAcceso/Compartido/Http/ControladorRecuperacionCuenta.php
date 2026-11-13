<?php

/** Entrada HTTP de ControladorRecuperacionCuenta. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\ActivacionPorCorreo;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\RegistroAuditoria;
use App\Support\ClinicAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** CU23 y acuerdos V6: verificación y recuperación por correo, sin inicio automático. */
class ControladorRecuperacionCuenta extends Controller
{
    /** Envía las instrucciones o el desafío de correo respetando los límites del servicio. */
    public function enviar(Request $solicitud)
    {
        abort_if($solicitud->user(), 409, 'Cierre la sesión antes de recuperar su cuenta.');
        $datos = $solicitud->validate(['email' => 'required|email|max:255', 'purpose' => 'required|in:verify,recover']);
        $usuario = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($datos['email']))])->first();
        if ($usuario && ClinicAccess::isActive($usuario)) {
            if (! $usuario->email_verified_at) {
                app(ActivacionPorCorreo::class)->enviar($usuario);
            } elseif ($datos['purpose'] === 'recover') {
                Password::sendResetLink(['email' => $usuario->email]);
            }
        }

        return ['message' => 'Si la cuenta está habilitada, recibirá las instrucciones en su correo.'];
    }

    /** Restablece la contraseña después de verificar el token de recuperación. */
    public function restablecer(Request $solicitud)
    {
        abort_if($solicitud->user(), 409, 'Cierre la sesión antes de recuperar su cuenta.');
        $datos = $solicitud->validate(['email' => 'required|email|max:255', 'token' => 'required|string|max:255',
            'password' => 'required|string|min:10|max:128|confirmed']);
        $datos['email'] = mb_strtolower(trim($datos['email']));
        $estado = DB::transaction(function () use ($datos) {
            DB::table('password_reset_tokens')->where('email', $datos['email'])->lockForUpdate()->first();

            return Password::reset($datos, function (User $usuario, string $contrasena) {
                $usuario = User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();
                if (! ClinicAccess::isActive($usuario) || ! $usuario->email_verified_at) {
                    throw ValidationException::withMessages(['email' => 'No se puede recuperar esta cuenta. Verifique el correo o contacte a Recepción.']);
                }
                $usuario->forceFill(['password' => $contrasena, 'remember_token' => Str::random(60)])->save();
                app(RegistroAuditoria::class)->registrar('auth', 'password_recovered', $usuario, responsableId: $usuario->id);
            });
        });
        if ($estado !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'El enlace no es válido o ha vencido. Solicite la recuperación nuevamente.']);
        }

        return ['message' => 'Contraseña restablecida. Inicie sesión con su nueva contraseña.'];
    }
}
