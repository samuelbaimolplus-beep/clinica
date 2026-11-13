<?php

/** Entrada HTTP de AgendaAdjustmentController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU24_GestionarBloqueosProvisionales\Http;

use App\Http\Controllers\Controller;
use App\Models\AgendaAdjustment;
use App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios\AgendaAdjustments;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgendaAdjustmentController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function listar(Request $solicitud)
    {
        $datos = $solicitud->validate(['date' => 'required|date_format:Y-m-d', 'specialty_id' => 'nullable|integer|exists:specialties,id']);

        return AgendaAdjustment::where('date', $datos['date'])->when($datos['specialty_id'] ?? null, fn ($consulta, $id) => $consulta->where('specialty_id', $id))->orderBy('start_time')->get();
    }

    /** Calcula una propuesta de franjas; todavía no confirma bloqueos ni crea citas. */
    public function proponer(Request $solicitud, AgendaAdjustments $servicio)
    {
        $datos = $solicitud->validate(['specialty_id' => 'required|integer|exists:specialties,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'quantity' => 'required|integer|between:1,100']);

        return $servicio->proponer($datos['specialty_id'], $datos['date'], $datos['quantity']);
    }

    /** Registra la operación solicitada después de validar entradas y permisos. */
    public function registrar(Request $solicitud, AgendaAdjustments $servicio)
    {
        $datos = $solicitud->validate([
            'kind' => ['required', Rule::in(['presential_block', 'online_opening'])],
            'specialty_id' => 'required_if:kind,presential_block|prohibited_if:kind,online_opening|nullable|integer|exists:specialties,id',
            'blocks' => 'required|array|min:1|max:100', 'blocks.*' => 'array:doctor_id,date,start_time,end_time',
            'blocks.*.doctor_id' => 'required|integer|exists:doctors,id',
            'blocks.*.date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'blocks.*.start_time' => 'required|date_format:H:i', 'blocks.*.end_time' => 'required|date_format:H:i',
            'quantity' => 'nullable|integer|min:1',
        ]);

        return response()->json($servicio->crear($datos['kind'], $datos['specialty_id'] ?? null, $datos['blocks'], $solicitud->user(), $datos['quantity'] ?? null), 201);
    }

    /** Libera un bloqueo autorizado conservando el registro de la operación. */
    public function liberar(Request $solicitud, AgendaAdjustments $servicio, int $id)
    {
        return $servicio->liberar($id, $solicitud->user());
    }

    /** Libera los bloqueos seleccionados después de validar sus estados y permisos. */
    public function liberarSeleccionados(Request $solicitud, AgendaAdjustments $servicio)
    {
        $datos = $solicitud->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct|exists:agenda_adjustments,id']);

        return $servicio->liberarSeleccionados($datos['ids'], $solicitud->user());
    }
}
