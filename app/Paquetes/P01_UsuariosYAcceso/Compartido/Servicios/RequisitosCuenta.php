<?php

/** Reglas de elegibilidad de cuentas. Una ficha clínica puede existir sin cuenta y a cualquier edad. */

namespace App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios;

use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** La ficha asistencial no exige cuenta. El acceso personal exige mayoría de edad. */
class RequisitosCuenta
{
    /** Obtiene la fecha autoritativa: la ficha del paciente cuando existe, o la fecha del personal. */
    public function fechaNacimiento(Usuario $usuario): ?string
    {
        $fecha = $usuario->role === 'Paciente' ? $usuario->patient()->value('birth_date') : $usuario->birth_date;

        return $fecha ? substr((string) $fecha, 0, 10) : null;
    }

    /** Comprueba 18 años cumplidos con fecha completa; rechaza fechas ausentes, futuras o inválidas. */
    public function esMayorDeEdad(?string $fecha): bool
    {
        if (! $fecha) {
            return false;
        }
        try {
            $fechaInterpretada = CarbonImmutable::createFromFormat('!Y-m-d', $fecha);

            return $fechaInterpretada && $fechaInterpretada->format('Y-m-d') === $fecha && $fechaInterpretada->lte(today()->subYearsNoOverflow(18));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Detiene el alta o modificación de cuenta si no se cumple la mayoría de edad. */
    public function exigirMayoriaDeEdad(?string $fecha): void
    {
        if (! $this->esMayorDeEdad($fecha)) {
            throw ValidationException::withMessages(['birth_date' => 'La cuenta requiere una fecha de nacimiento válida y 18 años cumplidos. La ficha de paciente puede registrarse sin cuenta a cualquier edad.']);
        }
    }

    /** Comprueba si falta la aceptación del paciente o si corresponde renovar la versión de términos. */
    public function requiereConsentimiento(Usuario $usuario): bool
    {
        return $usuario->role === 'Paciente' && (! $usuario->terms_accepted_at || $usuario->terms_version !== config('clinic.terms_version'));
    }

    /** Reúne mayoría de edad y consentimiento; la verificación de correo se comprueba separadamente. */
    public function cumpleRequisitos(Usuario $usuario): bool
    {
        return $this->esMayorDeEdad($this->fechaNacimiento($usuario)) && ! $this->requiereConsentimiento($usuario);
    }
}
