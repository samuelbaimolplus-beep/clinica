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
    private function clave(User $usuario): string
    {
        return 'clinic:activation:'.$usuario->id;
    }

    /** Genera el HMAC de un código vinculado al usuario y correo correspondientes. */
    private function calcularHuella(User $usuario, string $codigo): string
    {
        return hash_hmac('sha256', $usuario->id.'|'.$usuario->email.'|'.$codigo, config('app.key'));
    }

    /** Envía las instrucciones o el desafío de correo respetando los límites del servicio. */
    public function enviar(User $usuario): bool
    {
        // El bloqueo serializa reenvíos concurrentes; nunca se almacena el código en claro.
        $desafio = DB::transaction(function () use ($usuario) {
            $usuario = User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();
            if ($usuario->email_verified_at || ! ClinicAccess::isActive($usuario) || ! app(AccountEligibility::class)->cumpleRequisitos($usuario)) {
                return null;
            }
            $estadoPago = Cache::get($this->clave($usuario));
            if ($estadoPago && $estadoPago['email'] === $usuario->email && $estadoPago['sent_at'] > now()->timestamp - 60) {
                return null;
            }
            $limite = 'clinic:activation-send:'.$usuario->id;
            if (RateLimiter::tooManyAttempts($limite, 3)) {
                return null;
            }
            RateLimiter::hit($limite, 900);
            do {
                $codigo = (string) random_int(10000000, 99999999);
            } while ($estadoPago && hash_equals($estadoPago['hash'], $this->calcularHuella($usuario, $codigo)));
            Cache::put($this->clave($usuario), [
                'hash' => $this->calcularHuella($usuario, $codigo), 'email' => $usuario->email,
                'sent_at' => now()->timestamp, 'expires_at' => now()->addMinutes(15)->timestamp, 'attempts' => 0,
            ], now()->addMinutes(15));

            return [$usuario, $codigo];
        });
        if ($desafio === null) {
            return false;
        }
        [$usuario, $codigo] = $desafio;
        try {
            $usuario->notify(new VerifyClinicEmail($codigo));
        } catch (\Throwable $excepcion) {
            // No registrar la excepción SMTP: podría contener el cuerpo con el código.
            Log::warning('No se pudo enviar el correo de activación.', ['user_id' => $usuario->id]);

            return false;
        }

        return true;
    }

    /** Valida el código de activación, su vencimiento e intentos; consume el desafío al activarse. */
    public function confirmar(string $correo, string $codigo): void
    {
        $esValido = DB::transaction(function () use ($correo, $codigo) {
            $usuario = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($correo))])->lockForUpdate()->first();
            if (! $usuario || $usuario->email_verified_at || ! ClinicAccess::isActive($usuario) || ! app(AccountEligibility::class)->cumpleRequisitos($usuario)) {
                return false;
            }
            $estadoPago = Cache::get($this->clave($usuario));
            if (! $estadoPago || $estadoPago['email'] !== $usuario->email || $estadoPago['expires_at'] <= now()->timestamp || $estadoPago['attempts'] >= 5) {
                return false;
            }
            if (! hash_equals($estadoPago['hash'], $this->calcularHuella($usuario, $codigo))) {
                $estadoPago['attempts']++;
                Cache::put($this->clave($usuario), $estadoPago, now()->addSeconds(max(1, $estadoPago['expires_at'] - now()->timestamp)));

                return false;
            }
            $usuario->forceFill(['email_verified_at' => now()])->save();
            app(AuditLogger::class)->registrar('auth', 'email_verified', $usuario, responsableId: $usuario->id);
            Cache::forget($this->clave($usuario));

            return true;
        });
        if (! $esValido) {
            throw ValidationException::withMessages(['code' => 'El código no es válido, venció o superó los intentos permitidos. Solicite uno nuevo.']);
        }
    }
}
