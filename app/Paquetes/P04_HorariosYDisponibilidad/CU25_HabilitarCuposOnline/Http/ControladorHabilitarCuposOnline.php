<?php

/** Entrada HTTP de ControladorHabilitarCuposOnline. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU25_HabilitarCuposOnline\Http;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios\AjustesAgenda;
use Illuminate\Http\Request;

class ControladorHabilitarCuposOnline extends Controller
{
    /** Consulta cupos protegidos libres que Recepción puede habilitar para online. */
    public function franjasProtegidas(Request $solicitud, AjustesAgenda $servicio)
    {
        $datos = $solicitud->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d|after_or_equal:today']);
        abort_unless(Doctor::whereKey($datos['doctor_id'])->where('status', 'Activo')->exists(), 422);

        return array_values(array_filter($servicio->franjasLibres($datos['doctor_id'], $datos['date']), fn ($bloque) => $bloque['protected'] && $bloque['modality'] === 'presential'));
    }
}
