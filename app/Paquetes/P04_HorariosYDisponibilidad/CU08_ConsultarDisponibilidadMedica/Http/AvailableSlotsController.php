<?php

/** Entrada HTTP de AvailableSlotsController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU08_ConsultarDisponibilidadMedica\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use Illuminate\Http\Request;

class AvailableSlotsController extends Controller
{
    /** Consulta disponibilidad para los criterios validados sin retener ni cobrar. */
    public function availability(Request $r, AppointmentService $service)
    {
        abort_unless($r->user()->hasStaffRole('Recepcionista'), 403);
        $d = $r->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d', 'exclude' => 'nullable|integer|exists:appointments,id', 'modality' => 'nullable|in:presential,online']);

        return $service->slots($d['doctor_id'], $d['date'], $d['exclude'] ?? null, $d['modality'] ?? 'presential');
    }
}
