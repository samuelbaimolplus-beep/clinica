<?php

/** Entrada HTTP de ControladorCitasAfectadas. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P05_CitasMedicas\CU17_ConsultarCitasAfectadas\Http;

use App\Compartido\Catalogos\Servicios\ImpactoCatalogos;
use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios\ImpactoHorario;
use Illuminate\Http\Request;

class ControladorCitasAfectadas extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $solicitud, ImpactoHorario $horarios, ImpactoCatalogos $catalogos)
    {
        abort_unless($solicitud->user()->tieneRolPersonal('Recepcionista'), 403);
        $datos = $solicitud->validate(['doctor_id' => 'nullable|integer|exists:doctors,id']);
        $medicoId = $datos['doctor_id'] ?? null;

        $afectadas = $horarios->citasAfectadas($medicoId)->concat($catalogos->citasAfectadas($medicoId))->groupBy('id')
            ->map(fn ($elementos) => array_replace($elementos->first(), ['reasons' => $elementos->pluck('reasons')->flatten()->unique()->values()->all(),
                'origins' => $elementos->pluck('origins')->flatten(1)->unique(fn ($origenRegistro) => json_encode($origenRegistro))->values()->all()]))
            ->sortBy(fn ($elementoLista) => $elementoLista['date'].' '.$elementoLista['start_time'])->values();
        if ($solicitud->user()->tieneRolPersonal('Recepcionista')) {
            $citas = Cita::whereIn('id', $afectadas->pluck('id'))->with(['patient:id,first_name,last_name,phone', 'specialty:id,name'])->get()->keyBy('id');
            $afectadas = $afectadas->map(fn ($elementoLista) => $elementoLista + ['patient' => $citas[$elementoLista['id']]->patient, 'specialty' => $citas[$elementoLista['id']]->specialty]);
        }

        return $afectadas;
    }
}
