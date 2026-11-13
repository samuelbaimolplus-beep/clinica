<?php

/** Entrada HTTP de RefundRequestController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\CU20_SolicitarReembolso\Http;

use App\Http\Controllers\Controller;
use App\Models\RefundRequest;
use App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Servicios\RefundService;
use Illuminate\Http\Request;

class RefundRequestController extends Controller
{
    /** Consulta las solicitudes de devolución visibles para el rol. */
    public function listarReembolsos(Request $peticion)
    {
        abort_unless(in_array($peticion->user()->role, ['Superadmin', 'Administrador', 'Recepcionista', 'Paciente']), 403);

        return RefundRequest::query()->select([
            'id', 'appointment_id', 'status', 'amount_minor', 'reason', 'decision_reason',
            'executor', 'method', 'reference', 'completed_at',
        ])
            ->when($peticion->user()->role === 'Paciente', fn ($consulta) => $consulta->whereHas('appointment', fn ($consulta) => $consulta->where('patient_id', $peticion->user()->patient_id)))->latest()->get();
    }

    /** Recibe el motivo y solicita la evaluación del reembolso de una cita cancelada. */
    public function solicitarReembolso(Request $peticion, int $id, RefundService $servicio)
    {
        $datos = $peticion->validate(['reason' => 'required|string|max:3000']);

        return response()->json($servicio->solicitar($id, $datos['reason'], $peticion->user()), 201);
    }
}
