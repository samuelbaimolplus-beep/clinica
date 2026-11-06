<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ClinicAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ChangePassword
{
    public function handle(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data) {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(ClinicAccess::isActive($user), 403);
            // Se comprueba bajo bloqueo para evitar aceptar una contraseña recién reemplazada.
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
            }
            $user->update(['password' => $data['password']]);
            app(AuditLogger::class)->record('auth', 'password_changed', $user);

            return $user;
        });
    }
}
