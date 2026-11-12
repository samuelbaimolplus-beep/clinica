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
    public function index(Request $request)
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'specialty_id' => 'nullable|integer|exists:specialties,id']);

        return AgendaAdjustment::where('date', $data['date'])->when($data['specialty_id'] ?? null, fn ($q, $id) => $q->where('specialty_id', $id))->orderBy('start_time')->get();
    }

    /** Calcula una propuesta de franjas; todavía no confirma bloqueos ni crea citas. */
    public function propose(Request $request, AgendaAdjustments $service)
    {
        $data = $request->validate(['specialty_id' => 'required|integer|exists:specialties,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'quantity' => 'required|integer|between:1,100']);

        return $service->propose($data['specialty_id'], $data['date'], $data['quantity']);
    }

    /** Registra la operación solicitada después de validar entradas y permisos. */
    public function store(Request $request, AgendaAdjustments $service)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['presential_block', 'online_opening'])],
            'specialty_id' => 'required_if:kind,presential_block|prohibited_if:kind,online_opening|nullable|integer|exists:specialties,id',
            'blocks' => 'required|array|min:1|max:100', 'blocks.*' => 'array:doctor_id,date,start_time,end_time',
            'blocks.*.doctor_id' => 'required|integer|exists:doctors,id',
            'blocks.*.date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'blocks.*.start_time' => 'required|date_format:H:i', 'blocks.*.end_time' => 'required|date_format:H:i',
            'quantity' => 'nullable|integer|min:1',
        ]);

        return response()->json($service->create($data['kind'], $data['specialty_id'] ?? null, $data['blocks'], $request->user(), $data['quantity'] ?? null), 201);
    }

    /** Libera un bloqueo autorizado conservando el registro de la operación. */
    public function release(Request $request, AgendaAdjustments $service, int $id)
    {
        return $service->release($id, $request->user());
    }

    /** Libera los bloqueos seleccionados después de validar sus estados y permisos. */
    public function releaseSelected(Request $request, AgendaAdjustments $service)
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct|exists:agenda_adjustments,id']);

        return $service->releaseSelected($data['ids'], $request->user());
    }
}
