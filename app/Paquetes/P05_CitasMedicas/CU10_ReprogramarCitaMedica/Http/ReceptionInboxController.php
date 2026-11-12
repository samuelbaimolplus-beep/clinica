<?php

/** Entrada HTTP de ReceptionInboxController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P05_CitasMedicas\CU10_ReprogramarCitaMedica\Http;

use App\Http\Controllers\Controller;
use App\Models\RescheduleRequest;
use Illuminate\Http\Request;

class ReceptionInboxController extends Controller
{
    /** Devuelve el aviso de solicitudes pendientes para el trabajo de Recepción. */
    public function receptionInbox(Request $request)
    {
        abort_unless($request->user()->hasStaffRole('Recepcionista'), 403);
        $query = RescheduleRequest::where('status', 'pending');

        return response()->json(['pending' => (clone $query)->count(), 'latest_id' => $query->max('id')])->header('Cache-Control', 'no-store');
    }
}
