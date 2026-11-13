<?php

/** Entrada HTTP de ReceptionInboxController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P05_CitasMedicas\CU10_ReprogramarCitaMedica\Http;

use App\Http\Controllers\Controller;
use App\Models\RescheduleRequest;
use Illuminate\Http\Request;

class ReceptionInboxController extends Controller
{
    /** Devuelve el aviso de solicitudes pendientes para el trabajo de Recepción. */
    public function bandejaRecepcion(Request $solicitud)
    {
        abort_unless($solicitud->user()->hasStaffRole('Recepcionista'), 403);
        $consultaDatos = RescheduleRequest::where('status', 'pending');

        return response()->json(['pending' => (clone $consultaDatos)->count(), 'latest_id' => $consultaDatos->max('id')])->header('Cache-Control', 'no-store');
    }
}
