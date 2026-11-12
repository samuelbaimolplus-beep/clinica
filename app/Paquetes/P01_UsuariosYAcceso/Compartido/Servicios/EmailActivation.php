<?php

/** Códigos de correo de un solo uso: guarda HMAC, vencimiento e intentos, nunca el código en claro. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios;

use App\Models\User;
use App\Notifications\VerifyClinicEmail;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use App\Support\ClinicAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class EmailActivation
{
    /** Construye la clave interna del estado de verificación, separada por cuenta. */
    private function key(User $user): string
    {
        return 'clinic:activation:'.$user->id;
    }

    /** Genera el HMAC de un código vinculado al usuario y correo correspondientes. */
    private function digest(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.$user->email.'|'.$code, config('app.key'));
    }

    /** Envía las instrucciones o el desafío de correo respetando los límites del servicio. */
    public function send(User $user): bool
    {
        // El bloqueo serializa reenvíos concurrentes; nunca se almacena el código en claro.
        $challenge = DB::transaction(function () use ($user) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->email_verified_at || ! ClinicAccess::isActive($user) || ! app(AccountEligibility::class)->ready($user)) {
                return null;
            }
            $state = Cache::get($this->key($user));
            if ($state && $state['email'] === $user->email && $state['sent_at'] > now()->timestamp - 60) {
                return null;
            }
            $limit = 'clinic:activation-send:'.$user->id;
            if (RateLimiter::tooManyAttempts($limit, 3)) {
                return null;
            }
            RateLimiter::hit($limit, 900);
            do {
                $code = (string) random_int(10000000, 99999999);
            } while ($state && hash_equals($state['hash'], $this->digest($user, $code)));
            Cache::put($this->key($user), [
                'hash' => $this->digest($user, $code), 'email' => $user->email,
                'sent_at' => now()->timestamp, 'expires_at' => now()->addMinutes(15)->timestamp, 'attempts' => 0,
            ], now()->addMinutes(15));

            return [$user, $code];
        });
        if ($challenge === null) {
            return false;
        }
        [$user, $code] = $challenge;
        try {
            $user->notify(new VerifyClinicEmail($code));
        } catch (\Throwable $exception) {
            // No registrar la excepción SMTP: podría contener el cuerpo con el código.
            Log::warning('No se pudo enviar el correo de activación.', ['user_id' => $user->id]);

            return false;
        }

        return true;
    }

    /** Valida el código de activación, su vencimiento e intentos; consume el desafío al activarse. */
    public function confirm(string $email, string $code): void
    {
        $valid = DB::transaction(function () use ($email, $code) {
            $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->lockForUpdate()->first();
            if (! $user || $user->email_verified_at || ! ClinicAccess::isActive($user) || ! app(AccountEligibility::class)->ready($user)) {
                return false;
            }
            $state = Cache::get($this->key($user));
            if (! $state || $state['email'] !== $user->email || $state['expires_at'] <= now()->timestamp || $state['attempts'] >= 5) {
                return false;
            }
            if (! hash_equals($state['hash'], $this->digest($user, $code))) {
                $state['attempts']++;
                Cache::put($this->key($user), $state, now()->addSeconds(max(1, $state['expires_at'] - now()->timestamp)));

                return false;
            }
            $user->forceFill(['email_verified_at' => now()])->save();
            app(AuditLogger::class)->record('auth', 'email_verified', $user, actorId: $user->id);
            Cache::forget($this->key($user));

            return true;
        });
        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'El código no es válido, venció o superó los intentos permitidos. Solicite uno nuevo.']);
        }
    }
}
