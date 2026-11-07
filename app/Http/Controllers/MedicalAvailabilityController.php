<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Specialty;
use App\Services\Schedules\DoctorQuotaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CU08 provisional: consulta sin reservar ni formalizar citas. */
class MedicalAvailabilityController extends Controller
{
    private function authorizeRole(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['Recepcionista', 'Paciente'], true), 403);
    }

    public function options(Request $request)
    {
        $this->authorizeRole($request);

        return ['specialties' => Specialty::where('status', 'Activo')->orderBy('name')->get(['id', 'name']),
            'doctors' => Doctor::where('status', 'Activo')->whereHas('specialties', fn ($q) => $q->where('status', 'Activo'))
                ->with(['specialties' => fn ($q) => $q->where('status', 'Activo')->select('specialties.id', 'name')])
                ->orderBy('last_name')->get(['id', 'first_name', 'last_name'])];
    }

    public function index(Request $request, DoctorQuotaService $service)
    {
        $this->authorizeRole($request);
        $data = $request->validate([
            'doctor_id' => 'required|integer|exists:doctors,id',
            'specialty_id' => 'required|integer|exists:specialties,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'modality' => ['sometimes', Rule::in([$request->user()->role === 'Paciente' ? 'online' : 'presential'])],
        ]);
        $modality = $request->user()->role === 'Paciente' ? 'online' : 'presential';
        $doctor = Doctor::findOrFail($data['doctor_id']);
        if ($doctor->status !== 'Activo' || ! $doctor->specialties()->whereKey($data['specialty_id'])->where('status', 'Activo')->exists()) {
            return ['modality' => $modality, 'blocks' => []];
        }
        $appointments = Appointment::where('doctor_id', $doctor->id)->where('date', $data['date'])->whereIn('status', Appointment::ACTIVE)->get(['start_time', 'end_time']);
        $capacity = $service->capacity($doctor->id, $data['date']);
        $blocks = array_values(array_filter($capacity['blocks'], fn ($block) => $block['modality'] === $modality
            && ! $block['demand_blocked']
            && CarbonImmutable::parse($data['date'].' '.$block['start_time']) >= now()
            && ! $appointments->contains(fn ($appointment) => $appointment->start_time < $block['end_time'].':00' && $appointment->end_time > $block['start_time'].':00')));

        return ['modality' => $modality, 'blocks' => $blocks];
    }
}
