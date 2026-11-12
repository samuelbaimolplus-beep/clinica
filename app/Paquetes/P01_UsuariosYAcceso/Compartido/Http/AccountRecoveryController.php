<?php

/** Entrada HTTP de AccountRecoveryController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\EmailActivation;
use App\Support\ClinicAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** CU23 y acuerdos V6: verificación y recuperación por correo, sin inicio automático. */
class AccountRecoveryController extends Controller
{
    /** Envía las instrucciones o el desafío de correo respetando los límites del servicio. */
    public function send(Request $request)
    {
        abort_if($request->user(), 409, 'Cierre la sesión antes de recuperar su cuenta.');
        $data = $request->validate(['email' => 'required|email|max:255', 'purpose' => 'required|in:verify,recover']);
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->first();
        if ($user && ClinicAccess::isActive($user)) {
            if (! $user->email_verified_at) {
                app(EmailActivation::class)->send($user);
            } elseif ($data['purpose'] === 'recover') {
                Password::sendResetLink(['email' => $user->email]);
            }
        }

        return ['message' => 'Si la cuenta está habilitada, recibirá las instrucciones en su correo.'];
    }

    /** Restablece la contraseña después de verificar el token de recuperación. */
    public function reset(Request $request)
    {
        abort_if($request->user(), 409, 'Cierre la sesión antes de recuperar su cuenta.');
        $data = $request->validate(['email' => 'required|email|max:255', 'token' => 'required|string|max:255',
            'password' => 'required|string|min:10|max:128|confirmed']);
        $data['email'] = mb_strtolower(trim($data['email']));
        $status = DB::transaction(function () use ($data) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($data, function (User $user, string $password) {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (! ClinicAccess::isActive($user) || ! $user->email_verified_at) {
                    throw ValidationException::withMessages(['email' => 'No se puede recuperar esta cuenta. Verifique el correo o contacte a Recepción.']);
                }
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                app(AuditLogger::class)->record('auth', 'password_recovered', $user, actorId: $user->id);
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'El enlace no es válido o ha vencido. Solicite la recuperación nuevamente.']);
        }

        return ['message' => 'Contraseña restablecida. Inicie sesión con su nueva contraseña.'];
    }
}
