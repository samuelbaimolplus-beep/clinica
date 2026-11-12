<?php

/** Entrada HTTP de ScheduleController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Http;

use App\Http\Controllers\Controller;
use App\Models\DoctorQuota;
use App\Models\Schedule;
use App\Models\Unavailability;
use App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios\ScheduleImpact;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\DoctorQuotaService;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\ScheduleService;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Validacion\SaveDoctorQuotaRequest;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Validacion\SaveScheduleRequest;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Validacion\SaveUnavailabilityRequest;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function index(Request $r)
    {
        abort_unless($r->user()->hasStaffRole('Administrador'), 403);
        $d = $r->validate(['doctor_id' => 'required|integer|exists:doctors,id']);

        return ['schedules' => Schedule::where('doctor_id', $d['doctor_id'])->orderBy('weekday')->orderBy('start_time')->get(),
            'unavailabilities' => Unavailability::where('doctor_id', $d['doctor_id'])->orderByDesc('starts_at')->get(),
            'quota' => DoctorQuota::where('doctor_id', $d['doctor_id'])->first() ?? ['presential_percent' => 50, 'online_percent' => 50]];
    }

    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function save(SaveScheduleRequest $request, ScheduleService $service, ?int $id = null)
    {
        $schedule = $service->save($request->validated(), $id);

        return response()->json($schedule, $schedule->wasRecentlyCreated ? 201 : 200);
    }

    /** Registra una ausencia médica y devuelve las citas que requieren gestión. */
    public function unavailable(SaveUnavailabilityRequest $request, ScheduleService $service)
    {
        return response()->json($service->unavailable($request->validated()), 201);
    }

    /** Muestra el impacto previsto antes de que el usuario confirme el cambio. */
    public function preview(SaveUnavailabilityRequest $request, ScheduleService $service)
    {
        return response()->json($service->unavailable($request->validated(), true));
    }

    public function absenceImpacts(Request $request, ScheduleImpact $service, int $id)
    {
        abort_unless($request->user()->can('manage-schedules'), 403);
        $absence = Unavailability::findOrFail($id);

        return ['unavailability' => $absence, 'affected' => $absence->cancelled_at ? []
            : $service->overlapping($absence->doctor_id, $absence->starts_at, $absence->ends_at)];
    }

    public function cancelAbsence(Request $request, ScheduleService $service, int $id)
    {
        abort_unless($request->user()->can('manage-schedules'), 403);
        $data = $request->validate(['reason' => 'required|string|max:300']);

        return $service->cancelUnavailability($id, $data['reason'], $request->user());
    }

    /** Recibe porcentajes validados y delega su persistencia en el servicio de cupos. */
    public function quota(SaveDoctorQuotaRequest $request, DoctorQuotaService $service)
    {
        return response()->json($service->save($request->validated()));
    }

    /** Calcula cupos y franjas utilizables según turnos, modalidades, ausencias y ajustes. */
    public function capacity(Request $request, DoctorQuotaService $service)
    {
        $data = $request->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d']);

        return $service->capacity($data['doctor_id'], $data['date']);
    }
}
