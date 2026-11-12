<?php

/** Entrada HTTP de ScheduleImpactController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P05_CitasMedicas\CU17_ConsultarCitasAfectadas\Http;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Compartido\Catalogos\Servicios\CatalogImpact;
use App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios\ScheduleImpact;
use Illuminate\Http\Request;

class ScheduleImpactController extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $request, ScheduleImpact $schedules, CatalogImpact $catalogs)
    {
        abort_unless($request->user()->hasStaffRole('Recepcionista'), 403);
        $data = $request->validate(['doctor_id' => 'nullable|integer|exists:doctors,id']);
        $doctorId = $data['doctor_id'] ?? null;

        $affected = $schedules->affected($doctorId)->concat($catalogs->affected($doctorId))->groupBy('id')
            ->map(fn ($items) => array_replace($items->first(), ['reasons' => $items->pluck('reasons')->flatten()->unique()->values()->all(),
                'origins' => $items->pluck('origins')->flatten(1)->unique(fn ($origin) => json_encode($origin))->values()->all()]))
            ->sortBy(fn ($item) => $item['date'].' '.$item['start_time'])->values();
        if ($request->user()->hasStaffRole('Recepcionista')) {
            $appointments = Appointment::whereIn('id', $affected->pluck('id'))->with(['patient:id,first_name,last_name,phone', 'specialty:id,name'])->get()->keyBy('id');
            $affected = $affected->map(fn ($item) => $item + ['patient' => $appointments[$item['id']]->patient, 'specialty' => $appointments[$item['id']]->specialty]);
        }

        return $affected;
    }
}
