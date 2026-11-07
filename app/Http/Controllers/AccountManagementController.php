<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Superadmin consulta las cuentas y restablece claves; nunca obtiene las claves ni sus hashes. */
class AccountManagementController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'Superadmin', 403);

        return response()->json(User::orderBy('name')->get(['id', 'name', 'username', 'email', 'role', 'status']))
            ->header('Cache-Control', 'no-store');
    }

    public function password(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'Superadmin', 403);
        $data = $request->validate(['password' => 'required|string|min:10|max:128|confirmed']);
        DB::transaction(function () use ($request, $id, $data) {
            $account = User::whereKey($id)->lockForUpdate()->firstOrFail();
            $account->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            app(AuditLogger::class)->record('users', 'password_reset_by_superadmin', $account, actorId: $request->user()->id);
        });

        return response()->json(['message' => 'Contraseña cambiada. La contraseña anterior deja de funcionar y las sesiones previas deberán autenticarse nuevamente.'])
            ->header('Cache-Control', 'no-store');
    }
}
