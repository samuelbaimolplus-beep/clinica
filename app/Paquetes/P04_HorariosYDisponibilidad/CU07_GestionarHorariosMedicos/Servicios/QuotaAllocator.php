<?php

/** Reparte capacidad entera sin perder cupos por redondeos de porcentajes. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios;

use InvalidArgumentException;

/** Mayor resto: cantidades enteras, suma exacta y desempate a favor de presencial. */
final class QuotaAllocator
{
    /** Reparte cantidades enteras de cupos respetando los porcentajes configurados. */
    public function distribuir(int $capacidad, int $presencialPuntosBase, int $onlinePuntosBase): array
    {
        if ($capacidad < 0 || $presencialPuntosBase < 0 || $onlinePuntosBase < 0
            || $presencialPuntosBase + $onlinePuntosBase !== 10000) {
            throw new InvalidArgumentException('La capacidad debe ser no negativa y los porcentajes deben sumar 100%.');
        }
        $numeradorPresencial = $capacidad * $presencialPuntosBase;
        $numeradorOnline = $capacidad * $onlinePuntosBase;
        $presencial = intdiv($numeradorPresencial, 10000);
        $enLinea = intdiv($numeradorOnline, 10000);
        $restantes = $capacidad - $presencial - $enLinea;
        if ($restantes > 0) {
            if ($numeradorPresencial % 10000 >= $numeradorOnline % 10000) {
                $presencial += $restantes;
            } else {
                $enLinea += $restantes;
            }
        }

        return ['presential' => $presencial, 'online' => $enLinea];
    }
}
