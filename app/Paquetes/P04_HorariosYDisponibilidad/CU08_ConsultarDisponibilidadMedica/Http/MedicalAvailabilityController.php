<?php

/** Entrada HTTP de MedicalAvailabilityController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU08_ConsultarDisponibilidadMedica\Http;

use App\Http\Controllers\Controller;
use App\Models\AgendaAdjustment;
use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Integraciones\PayPalGateway;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\DoctorQuotaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CU08 consulta disponibilidad; los coordinadores revalidan antes de formalizar. */
class MedicalAvailabilityController extends Controller
{
    /** Comprueba el rol requerido antes de leer o modificar datos del módulo. */
    private function authorizeRole(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['Superadmin', 'Recepcionista', 'Paciente'], true), 403);
    }

    /** Devuelve opciones activas y configuración pública necesaria para seleccionar una atención. */
    public function options(Request $request)
    {
        $this->authorizeRole($request);

        return ['online_payment_available' => app(PayPalGateway::class)->configured(),
            'payment_provider' => 'paypal', 'payment_currency' => 'USD', 'bob_per_usd' => config('paypal.bob_per_usd'),
            'specialties' => Specialty::where('status', 'Activo')->orderBy('name')->get(['id', 'name', 'fee_minor']),
            'doctors' => Doctor::where('status', 'Activo')->whereHas('specialties', fn ($q) => $q->where('status', 'Activo'))
                ->with(['specialties' => fn ($q) => $q->where('status', 'Activo')->select('specialties.id', 'name')])
                ->orderBy('last_name')->get(['id', 'first_name', 'last_name'])];
    }

    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function index(Request $request, DoctorQuotaService $service)
    {
        $this->authorizeRole($request);
        $data = $request->validate([
            'doctor_id' => 'nullable|integer|exists:doctors,id',
            'include_demand' => 'sometimes|boolean',
            'specialty_id' => 'required|integer|exists:specialties,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'modality' => ['sometimes', Rule::in($request->user()->role === 'Superadmin' ? ['online', 'presential'] : [$request->user()->role === 'Paciente' ? 'online' : 'presential'])],
        ]);
        $modality = $request->user()->role === 'Superadmin' ? ($data['modality'] ?? 'presential') : ($request->user()->role === 'Paciente' ? 'online' : 'presential');
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

        $message = null;
        if (! $blocks) {
            $message = $doctors->isEmpty() ? 'No hay médicos activos habilitados para la especialidad seleccionada.'
                : (! Schedule::whereIn('doctor_id', $doctors->pluck('id'))->where('weekday', CarbonImmutable::parse($data['date'])->dayOfWeek)->exists()
                    ? 'Los médicos seleccionados no tienen atención programada para esa fecha.'
                    : 'No hay cupos libres para esta modalidad. Consulte otra fecha o profesional.');
        }

        return ['modality' => $modality, 'blocks' => $blocks, 'message' => $message];
    }
}
