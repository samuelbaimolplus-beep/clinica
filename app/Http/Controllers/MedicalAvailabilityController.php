<?php

namespace App\Http\Controllers;

use App\Models\AgendaAdjustment;
use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\Specialty;
use App\Services\Schedules\DoctorQuotaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CU08 consulta disponibilidad; los coordinadores revalidan antes de formalizar. */
class MedicalAvailabilityController extends Controller
{
    private function authorizeRole(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['Recepcionista', 'Paciente'], true), 403);
    }

    public function options(Request $request)
    {
        $this->authorizeRole($request);

        return ['specialties' => Specialty::where('status', 'Activo')->orderBy('name')->get(['id', 'name', 'fee_minor']),
            'doctors' => Doctor::where('status', 'Activo')->whereHas('specialties', fn ($q) => $q->where('status', 'Activo'))
                ->with(['specialties' => fn ($q) => $q->where('status', 'Activo')->select('specialties.id', 'name')])
                ->orderBy('last_name')->get(['id', 'first_name', 'last_name'])];
    }

    public function index(Request $request, DoctorQuotaService $service)
    {
        $this->authorizeRole($request);
        $data = $request->validate([
            'doctor_id' => 'nullable|integer|exists:doctors,id',
            'include_demand' => 'sometimes|boolean',
            'specialty_id' => 'required|integer|exists:specialties,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'modality' => ['sometimes', Rule::in([$request->user()->role === 'Paciente' ? 'online' : 'presential'])],
        ]);
        $modality = $request->user()->role === 'Paciente' ? 'online' : 'presential';
        $doctors = Doctor::where('status', 'Activo')->when($data['doctor_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->whereHas('specialties', fn ($q) => $q->whereKey($data['specialty_id'])->where('status', 'Activo'))->get();
        $ranked = [];
        foreach ($doctors as $doctor) {
            $appointments = Appointment::where('doctor_id', $doctor->id)->where('date', $data['date'])->whereIn('status', Appointment::ACTIVE)->get(['start_time', 'end_time']);
            $capacity = $service->capacity($doctor->id, $data['date']);
            $holds = BookingRequest::where('doctor_id', $doctor->id)->where('date', $data['date'])->whereIn('status', ['held', 'processing'])->get();
            $demands = AgendaAdjustment::where('doctor_id', $doctor->id)->where('date', $data['date'])->where('kind', 'presential_block')->where('status', 'active')->get();
            $blocks = array_values(array_filter($capacity['blocks'], fn ($block) => $block['modality'] === $modality
                && (! $block['demand_blocked'] || (! empty($data['include_demand']) && $modality === 'presential' && $demands->contains(fn ($d) => $d->specialty_id === (int) $data['specialty_id'] && substr($d->start_time, 0, 5) === $block['start_time'])))
                && ! $holds->contains(fn ($hold) => $hold->start_time < $block['end_time'].':00' && $hold->end_time > $block['start_time'].':00')
                && CarbonImmutable::parse($data['date'].' '.$block['start_time']) >= now()
                && ! $appointments->contains(fn ($appointment) => $appointment->start_time < $block['end_time'].':00' && $appointment->end_time > $block['start_time'].':00')));
            if ($blocks) {
                $ranked[] = ['doctor_id' => $doctor->id, 'doctor_name' => $doctor->first_name.' '.$doctor->last_name,
                    'load' => $appointments->count(), 'blocks' => $blocks];
            }
        }
        usort($ranked, fn ($a, $b) => [$a['load'], -count($a['blocks']), $a['doctor_id']] <=> [$b['load'], -count($b['blocks']), $b['doctor_id']]);
        $blocks = [];
        foreach ($ranked as $doctor) {
            foreach ($doctor['blocks'] as $block) {
                $blocks[] = $block + ['doctor_id' => $doctor['doctor_id'], 'doctor_name' => $doctor['doctor_name']];
            }
        }

        return ['modality' => $modality, 'blocks' => $blocks];
    }
}
