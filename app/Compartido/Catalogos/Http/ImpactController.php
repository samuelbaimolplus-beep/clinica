<?php

/** Entrada HTTP de ImpactController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Compartido\Catalogos\Http;

use App\Compartido\Catalogos\Servicios\CatalogImpact;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ImpactController extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $solicitud, CatalogImpact $impacto)
    {
        abort_unless($solicitud->user()->hasStaffRole('Administrador', 'Recepcionista'), 403);

        return $impacto->citasAfectadas();
    }
}
