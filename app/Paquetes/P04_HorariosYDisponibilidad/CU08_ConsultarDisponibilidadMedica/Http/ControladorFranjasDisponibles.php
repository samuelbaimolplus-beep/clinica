<?php

/** Entrada HTTP de ControladorFranjasDisponibles. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU08_ConsultarDisponibilidadMedica\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\ServicioCitas;
use Illuminate\Http\Request;

class ControladorFranjasDisponibles extends Controller
{
    /** Consulta disponibilidad para los criterios validados sin retener ni cobrar. */
    public function consultarDisponibilidad(Request $peticion, ServicioCitas $servicio)
    {
        abort_unless($peticion->user()->tieneRolPersonal('Recepcionista'), 403);
        $criterios = $peticion->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d', 'exclude' => 'nullable|integer|exists:appointments,id', 'modality' => 'nullable|in:presential,online']);

        return $servicio->franjasDisponibles($criterios['doctor_id'], $criterios['date'], $criterios['exclude'] ?? null, $criterios['modality'] ?? 'presential');
    }
}
