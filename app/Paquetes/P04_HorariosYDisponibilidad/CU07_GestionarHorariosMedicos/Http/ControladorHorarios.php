<?php

/** Entrada HTTP de ControladorHorarios. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Http;

use App\Http\Controllers\Controller;
use App\Models\DoctorQuota;
use App\Models\Schedule;
use App\Models\Unavailability;
use App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios\ImpactoHorario;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\ServicioCuposMedico;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\ServicioHorarios;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Validacion\SolicitudGuardarAusencia;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Validacion\SolicitudGuardarCuposMedico;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Validacion\SolicitudGuardarHorario;
use Illuminate\Http\Request;

class ControladorHorarios extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function listar(Request $peticion)
    {
        abort_unless($peticion->user()->hasStaffRole('Administrador'), 403);
        $criterios = $peticion->validate(['doctor_id' => 'required|integer|exists:doctors,id']);

        return ['schedules' => Schedule::where('doctor_id', $criterios['doctor_id'])->orderBy('weekday')->orderBy('start_time')->get(),
            'unavailabilities' => Unavailability::where('doctor_id', $criterios['doctor_id'])->orderByDesc('starts_at')->get(),
            'quota' => DoctorQuota::where('doctor_id', $criterios['doctor_id'])->first() ?? ['presential_percent' => 50, 'online_percent' => 50]];
    }

    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function guardar(SolicitudGuardarHorario $solicitud, ServicioHorarios $servicio, ?int $id = null)
    {
        $horario = $servicio->guardar($solicitud->validated(), $id);

        return response()->json($horario, $horario->wasRecentlyCreated ? 201 : 200);
    }

    /** Registra una ausencia médica y devuelve las citas que requieren gestión. */
    public function registrarAusencia(SolicitudGuardarAusencia $solicitud, ServicioHorarios $servicio)
    {
        return response()->json($servicio->registrarAusencia($solicitud->validated()), 201);
    }

    /** Muestra el impacto previsto antes de que el usuario confirme el cambio. */
    public function previsualizarAusencia(SolicitudGuardarAusencia $solicitud, ServicioHorarios $servicio)
    {
        return response()->json($servicio->registrarAusencia($solicitud->validated(), true));
    }

    public function consultarImpactoAusencia(Request $solicitud, ImpactoHorario $servicio, int $id)
    {
        abort_unless($solicitud->user()->can('manage-schedules'), 403);
        $ausencia = Unavailability::findOrFail($id);

        return ['unavailability' => $ausencia, 'affected' => $ausencia->cancelled_at ? []
            : $servicio->citasSuperpuestas($ausencia->doctor_id, $ausencia->starts_at, $ausencia->ends_at)];
    }

    public function retirarAusencia(Request $solicitud, ServicioHorarios $servicio, int $id)
    {
        abort_unless($solicitud->user()->can('manage-schedules'), 403);
        $datos = $solicitud->validate(['reason' => 'required|string|max:300']);

        return $servicio->retirarAusencia($id, $datos['reason'], $solicitud->user());
    }

    /** Recibe porcentajes validados y delega su persistencia en el servicio de cupos. */
    public function guardarDistribucion(SolicitudGuardarCuposMedico $solicitud, ServicioCuposMedico $servicio)
    {
        return response()->json($servicio->guardar($solicitud->validated()));
    }

    /** Calcula cupos y franjas utilizables según turnos, modalidades, ausencias y ajustes. */
    public function calcularCapacidad(Request $solicitud, ServicioCuposMedico $servicio)
    {
        $datos = $solicitud->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d']);

        return $servicio->calcularCapacidad($datos['doctor_id'], $datos['date']);
    }
}
