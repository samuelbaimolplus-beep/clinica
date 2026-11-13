<?php

/** Entrada HTTP de AccountManagementController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU03_GestionarUsuariosYRoles\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Superadmin consulta las cuentas y restablece claves; nunca obtiene las claves ni sus hashes. */
class AccountManagementController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function listar(Request $solicitud)
    {
        abort_unless($solicitud->user()->role === 'Superadmin', 403);

        return response()->json(User::orderBy('name')->get(['id', 'name', 'username', 'email', 'role', 'status', 'email_verified_at']))
            ->header('Cache-Control', 'no-store');
    }

    /** Cambia una contraseña autorizada sin exponer ni recuperar la anterior. */
    public function cambiarContrasena(Request $solicitud, int $id)
    {
        abort_unless($solicitud->user()->role === 'Superadmin', 403);
        $datos = $solicitud->validate(['password' => 'required|string|min:6|max:128|confirmed']);
        DB::transaction(function () use ($solicitud, $id, $datos) {
            $cuenta = User::whereKey($id)->lockForUpdate()->firstOrFail();
            $cuenta->forceFill(['password' => $datos['password'], 'remember_token' => Str::random(60)])->save();
            app(AuditLogger::class)->registrar('users', 'password_reset_by_superadmin', $cuenta, responsableId: $solicitud->user()->id);
        });

        return response()->json(['message' => 'Contraseña cambiada. La contraseña anterior deja de funcionar y las sesiones previas deberán autenticarse nuevamente.'])
            ->header('Cache-Control', 'no-store');
    }
}
