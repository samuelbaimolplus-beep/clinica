<?php

/** Entrada HTTP de ControladorImpactoCatalogos. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Compartido\Catalogos\Http;

use App\Compartido\Catalogos\Servicios\ImpactoCatalogos;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ControladorImpactoCatalogos extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $solicitud, ImpactoCatalogos $impacto)
    {
        abort_unless($solicitud->user()->tieneRolPersonal('Administrador', 'Recepcionista'), 403);

        return $impacto->citasAfectadas();
    }
}
