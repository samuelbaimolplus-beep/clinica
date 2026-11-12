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
    public function refunds(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['Superadmin', 'Administrador', 'Recepcionista', 'Paciente']), 403);

        return RefundRequest::query()->select([
            'id', 'appointment_id', 'status', 'amount_minor', 'reason', 'decision_reason',
            'executor', 'method', 'reference', 'completed_at',
        ])
            ->when($r->user()->role === 'Paciente', fn ($q) => $q->whereHas('appointment', fn ($q) => $q->where('patient_id', $r->user()->patient_id)))->latest()->get();
    }

    /** Recibe el motivo y solicita la evaluación del reembolso de una cita cancelada. */
    public function requestRefund(Request $r, int $id, RefundService $service)
    {
        $data = $r->validate(['reason' => 'required|string|max:3000']);

        return response()->json($service->request($id, $data['reason'], $r->user()), 201);
    }
}
