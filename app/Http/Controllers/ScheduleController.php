<?php

namespace App\Http\Controllers;

use App\Http\Requests\Schedules\SaveDoctorQuotaRequest;
use App\Http\Requests\Schedules\SaveScheduleRequest;
use App\Http\Requests\Schedules\SaveUnavailabilityRequest;
use App\Models\DoctorQuota;
use App\Models\Schedule;
use App\Models\Unavailability;
use App\Services\AppointmentService;
use App\Services\Schedules\DoctorQuotaService;
use App\Services\Schedules\ScheduleService;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function availability(Request $r, AppointmentService $service)
    {
        abort_unless($r->user()->role === 'Recepcionista', 403);
        $d = $r->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d', 'exclude' => 'nullable|integer|exists:appointments,id', 'modality' => 'nullable|in:presential,online']);

        return $service->slots($d['doctor_id'], $d['date'], $d['exclude'] ?? null, $d['modality'] ?? 'presential');
    }

    public function index(Request $r)
    {
        abort_unless($r->user()->role === 'Administrador', 403);
        $d = $r->validate(['doctor_id' => 'required|integer|exists:doctors,id']);

        return ['schedules' => Schedule::where('doctor_id', $d['doctor_id'])->orderBy('weekday')->orderBy('start_time')->get(),
            'unavailabilities' => Unavailability::where('doctor_id', $d['doctor_id'])->orderByDesc('starts_at')->get(),
            'quota' => DoctorQuota::where('doctor_id', $d['doctor_id'])->first() ?? ['presential_percent' => 50, 'online_percent' => 50]];
    }

    public function save(SaveScheduleRequest $request, ScheduleService $service, ?int $id = null)
    {
        $schedule = $service->save($request->validated(), $id);

        return response()->json($schedule, $schedule->wasRecentlyCreated ? 201 : 200);
    }

    public function unavailable(SaveUnavailabilityRequest $request, ScheduleService $service)
    {
        return response()->json($service->unavailable($request->validated()), 201);
    }

    public function preview(SaveUnavailabilityRequest $request, ScheduleService $service)
    {
        return response()->json($service->unavailable($request->validated(), true));
    }

    public function quota(SaveDoctorQuotaRequest $request, DoctorQuotaService $service)
    {
        return response()->json($service->save($request->validated()));
    }

    public function capacity(Request $request, DoctorQuotaService $service)
    {
        $data = $request->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d']);

        return $service->capacity($data['doctor_id'], $data['date']);
    }
}
