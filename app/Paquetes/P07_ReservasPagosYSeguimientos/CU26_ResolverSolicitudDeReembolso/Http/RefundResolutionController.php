<?php

/** Entrada HTTP de RefundResolutionController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\CU26_ResolverSolicitudDeReembolso\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Servicios\RefundService;
use Illuminate\Http\Request;

class RefundResolutionController extends Controller
{
    /** Valida los datos de decisión antes de delegar la resolución del reembolso. */
    public function decideRefund(Request $r, int $id, RefundService $service)
    {
        abort_unless($r->user()->hasStaffRole('Administrador'), 403);
        $data = $r->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required_if:decision,reject|nullable|string|max:3000']);

        return $service->decide($id, $data, $r->user());
    }

    /** Valida fecha, medio, responsable e importe de la devolución ya realizada. */
    public function completeRefund(Request $r, int $id, RefundService $service)
    {
        abort_unless($r->user()->hasStaffRole('Administrador'), 403);
        $data = $r->validate(['executor' => 'required|string|max:255', 'method' => 'required|string|max:80', 'reference' => 'nullable|string|max:255',
            'amount_minor' => 'required|integer|min:1', 'completed_at' => 'required|date|before_or_equal:now']);

        return $service->complete($id, $data, $r->user());
    }
}
