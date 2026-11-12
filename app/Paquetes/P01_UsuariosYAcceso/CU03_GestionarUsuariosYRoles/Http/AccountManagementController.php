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
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'Superadmin', 403);

        return response()->json(User::orderBy('name')->get(['id', 'name', 'username', 'email', 'role', 'status', 'email_verified_at']))
            ->header('Cache-Control', 'no-store');
    }

    /** Cambia una contraseña autorizada sin exponer ni recuperar la anterior. */
    public function password(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'Superadmin', 403);
        $data = $request->validate(['password' => 'required|string|min:6|max:128|confirmed']);
        DB::transaction(function () use ($request, $id, $data) {
            $account = User::whereKey($id)->lockForUpdate()->firstOrFail();
            $account->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            app(AuditLogger::class)->record('users', 'password_reset_by_superadmin', $account, actorId: $request->user()->id);
        });

        return response()->json(['message' => 'Contraseña cambiada. La contraseña anterior deja de funcionar y las sesiones previas deberán autenticarse nuevamente.'])
            ->header('Cache-Control', 'no-store');
    }
}
