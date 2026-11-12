<?php

/** Reglas de elegibilidad de cuentas. Una ficha clínica puede existir sin cuenta y a cualquier edad. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** La ficha asistencial no exige cuenta. El acceso personal exige mayoría de edad. */
class AccountEligibility
{
    /** Obtiene la fecha autoritativa: la ficha del paciente cuando existe, o la fecha del personal. */
    public function birthDate(User $user): ?string
    {
        $date = $user->role === 'Paciente' ? $user->patient()->value('birth_date') : $user->birth_date;

        return $date ? substr((string) $date, 0, 10) : null;
    }

    /** Comprueba 18 años cumplidos con fecha completa; rechaza fechas ausentes, futuras o inválidas. */
    public function adult(?string $date): bool
    {
        if (! $date) {
            return false;
        }
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

            return $parsed && $parsed->format('Y-m-d') === $date && $parsed->lte(today()->subYearsNoOverflow(18));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Detiene el alta o modificación de cuenta si no se cumple la mayoría de edad. */
    public function assertAdult(?string $date): void
    {
        if (! $this->adult($date)) {
            throw ValidationException::withMessages(['birth_date' => 'La cuenta requiere una fecha de nacimiento válida y 18 años cumplidos. La ficha de paciente puede registrarse sin cuenta a cualquier edad.']);
        }
    }

    /** Comprueba si falta la aceptación del paciente o si corresponde renovar la versión de términos. */
    public function needsConsent(User $user): bool
    {
        return $user->role === 'Paciente' && (! $user->terms_accepted_at || $user->terms_version !== config('clinic.terms_version'));
    }

    /** Reúne mayoría de edad y consentimiento; la verificación de correo se comprueba separadamente. */
    public function ready(User $user): bool
    {
        return $this->adult($this->birthDate($user)) && ! $this->needsConsent($user);
    }
}
