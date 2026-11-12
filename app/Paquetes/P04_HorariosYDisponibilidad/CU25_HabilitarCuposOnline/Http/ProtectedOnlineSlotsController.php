<?php

/** Entrada HTTP de ProtectedOnlineSlotsController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU25_HabilitarCuposOnline\Http;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios\AgendaAdjustments;
use Illuminate\Http\Request;

class ProtectedOnlineSlotsController extends Controller
{
    /** Consulta cupos protegidos libres que Recepción puede habilitar para online. */
    public function protectedBlocks(Request $request, AgendaAdjustments $service)
    {
        $data = $request->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d|after_or_equal:today']);
        abort_unless(Doctor::whereKey($data['doctor_id'])->where('status', 'Activo')->exists(), 422);

        return array_values(array_filter($service->freeBlocks($data['doctor_id'], $data['date']), fn ($block) => $block['protected'] && $block['modality'] === 'presential'));
    }
}
