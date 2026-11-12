<?php

/** Reparte capacidad entera sin perder cupos por redondeos de porcentajes. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios;

use InvalidArgumentException;

/** Mayor resto: cantidades enteras, suma exacta y desempate a favor de presencial. */
final class QuotaAllocator
{
    /** Reparte cantidades enteras de cupos respetando los porcentajes configurados. */
    public function allocate(int $capacity, int $presentialBasisPoints, int $onlineBasisPoints): array
    {
        if ($capacity < 0 || $presentialBasisPoints < 0 || $onlineBasisPoints < 0
            || $presentialBasisPoints + $onlineBasisPoints !== 10000) {
            throw new InvalidArgumentException('La capacidad debe ser no negativa y los porcentajes deben sumar 100%.');
        }
        $presentialNumerator = $capacity * $presentialBasisPoints;
        $onlineNumerator = $capacity * $onlineBasisPoints;
        $presential = intdiv($presentialNumerator, 10000);
        $online = intdiv($onlineNumerator, 10000);
        $remaining = $capacity - $presential - $online;
        if ($remaining > 0) {
            if ($presentialNumerator % 10000 >= $onlineNumerator % 10000) {
                $presential += $remaining;
            } else {
                $online += $remaining;
            }
        }

        return ['presential' => $presential, 'online' => $online];
    }
}
