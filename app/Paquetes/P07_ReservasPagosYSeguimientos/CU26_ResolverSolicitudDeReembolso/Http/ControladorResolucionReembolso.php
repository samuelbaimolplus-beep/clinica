<?php

/** Entrada HTTP de ControladorResolucionReembolso. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\CU26_ResolverSolicitudDeReembolso\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Servicios\ServicioReembolsos;
use Illuminate\Http\Request;

class ControladorResolucionReembolso extends Controller
{
    /** Valida los datos de decisión antes de delegar la resolución del reembolso. */
    public function resolverReembolso(Request $peticion, int $id, ServicioReembolsos $servicio)
    {
        abort_unless($peticion->user()->hasStaffRole('Administrador'), 403);
        $datos = $peticion->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required_if:decision,reject|nullable|string|max:3000']);

        return $servicio->resolver($id, $datos, $peticion->user());
    }

    /** Valida fecha, medio, responsable e importe de la devolución ya realizada. */
    public function registrarEntregaReembolso(Request $peticion, int $id, ServicioReembolsos $servicio)
    {
        abort_unless($peticion->user()->hasStaffRole('Administrador'), 403);
        $datos = $peticion->validate(['executor' => 'required|string|max:255', 'method' => 'required|string|max:80', 'reference' => 'nullable|string|max:255',
            'amount_minor' => 'required|integer|min:1', 'completed_at' => 'required|date|before_or_equal:now']);

        return $servicio->registrarEntrega($id, $datos, $peticion->user());
    }
}
