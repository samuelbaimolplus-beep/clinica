<?php

/** Entrada HTTP de ImpactController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Compartido\Catalogos\Http;

use App\Http\Controllers\Controller;
use App\Compartido\Catalogos\Servicios\CatalogImpact;
use Illuminate\Http\Request;

class ImpactController extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $request, CatalogImpact $impact)
    {
        abort_unless($request->user()->hasStaffRole('Administrador', 'Recepcionista'), 403);

        return $impact->affected();
    }
}
