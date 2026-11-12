<?php

/** Consultas de agenda e historial con filtros de identidad y datos limitados para el médico. */

namespace App\Paquetes\P05_CitasMedicas\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Unavailability;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentQueryController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function index(Request $r)
    {
        $d = $r->validate(['mode' => ['required', Rule::in(['agenda', 'history', 'affected', 'active', 'today', 'attend'])], 'date' => 'nullable|date_format:Y-m-d', 'doctor_id' => 'nullable|integer|exists:doctors,id', 'status' => ['nullable', Rule::in(Appointment::STATES)], 'q' => 'nullable|string|max:150']);
        $role = $r->user()->role;
        $patientFilter = $r->validate(['patient_id' => 'nullable|integer|exists:patients,id']);
        $allowed = match ($role) {
            'Superadmin' => ['agenda', 'history', 'affected', 'active', 'today', 'attend'], 'Administrador' => ['history'], 'Recepcionista' => ['agenda', 'history', 'affected', 'active', 'today'], 'Médico' => ['agenda', 'attend'], 'Paciente' => ['history', 'active'], default => []
        };
        abort_unless(in_array($d['mode'], $allowed), 403);
        $relations = $role === 'Médico'
            ? ['patient:id,first_name,last_name', 'doctor:id,first_name,last_name', 'specialty:id,name', 'events']
            : ['patient', 'doctor', 'specialty', 'events', 'payment'];
        $q = Appointment::with($relations)->orderBy('date')->orderBy('start_time')->orderBy('id');
        if ($role === 'Paciente') {
            abort_if(! empty($patientFilter['patient_id']) && (int) $patientFilter['patient_id'] !== $r->user()->patient_id, 403);
            $q->where('patient_id', $r->user()->patient_id);
        }
        if (! empty($patientFilter['patient_id'])) {
            $q->where('patient_id', $patientFilter['patient_id']);
        }
        if ($role === 'Médico') {
            abort_unless($r->user()->doctor_id, 403);
            abort_if(! empty($d['doctor_id']) && (int) $d['doctor_id'] !== $r->user()->doctor_id, 403, 'Solo puede consultar su propia agenda.');
            $q->where('doctor_id', $r->user()->doctor_id);
        } elseif (! empty($d['doctor_id'])) {
            $q->where('doctor_id', $d['doctor_id']);
        }
        if (in_array($d['mode'], ['today', 'attend'])) {
            $q->where('date', today()->toDateString());
        } elseif (! empty($d['date'])) {
            $q->where('date', $d['date']);
        }
        if (in_array($d['mode'], ['active', 'affected'])) {
            $q->whereIn('status', Appointment::ACTIVE);
        }
        if (! empty($d['status'])) {
            $q->where('status', $d['status']);
        }
        if ($d['mode'] === 'affected') {
            $q->whereExists(fn ($b) => $b->selectRaw('1')->from('unavailabilities')->whereNull('unavailabilities.cancelled_at')->whereColumn('unavailabilities.doctor_id', 'appointments.doctor_id')->whereRaw('(appointments.date + appointments.start_time) < unavailabilities.ends_at AND (appointments.date + appointments.end_time) > unavailabilities.starts_at'));
        }
        if (! empty($d['q'])) {
            $text = '%'.$d['q'].'%';
            $q->where(fn ($b) => $b->whereRaw("('CIT-' || lpad(id::text, 4, '0')) ILIKE ?", [$text])->orWhereHas('patient', fn ($p) => $p->whereRaw("(first_name || ' ' || last_name || ' ' || coalesce(identity, '')) ILIKE ?", [$text]))->orWhereHas('doctor', fn ($p) => $p->whereRaw("(first_name || ' ' || last_name) ILIKE ?", [$text])));
        }
        $rows = $q->get();
        foreach ($rows as $a) {
            $a->setAttribute('care_ready', $a->status === 'Confirmada' && $a->attendance_at && ! app(AppointmentService::class)->needsPayment($a));
            if ($role !== 'Médico') {
                $a->setAttribute('requires_arrival_payment', app(AppointmentService::class)->needsPayment($a));
            }
            $a->setAttribute('arrival_late', $a->attendance_at && ($a->missed_call_at || CarbonImmutable::parse($a->attendance_at)->gt(CarbonImmutable::parse($a->date.' '.$a->start_time))));
        }
        if ($d['mode'] === 'attend') {
            // Present punctual patients retain their scheduled priority; delayed patients follow arrival order.
            $rows = $rows->sortBy(fn ($a) => [$a->attendance_at ? ($a->arrival_late ? 1 : 0) : 2,
                $a->arrival_late ? $a->attendance_at : $a->start_time, $a->id])->values();
        }
        if ($d['mode'] === 'affected') {
            $blocks = Unavailability::active()->get();
            foreach ($rows as $a) {
                $a->setAttribute('blocks', $blocks->filter(fn ($b) => $b->doctor_id === $a->doctor_id && $a->date.' '.$a->start_time < $b->ends_at && $a->date.' '.$a->end_time > $b->starts_at)->values());
            }
        }
        if ($role === 'Médico') {
            $rows->each(fn (Appointment $appointment) => $this->restrictPhysicianData($appointment));
        }

        return $rows;
    }

    /** Retira datos de contacto y de pago que no necesita la atención médica. */
    private function restrictPhysicianData(Appointment $appointment): void
    {
        $appointment->unsetRelation('payment');
        $appointment->makeHidden(['payment_id', 'booking_request_id', 'created_by', 'clinical_slot_id', 'modality']);
        $appointment->patient?->setVisible(['id', 'first_name', 'last_name']);
        $appointment->doctor?->setVisible(['id', 'first_name', 'last_name']);
        $appointment->specialty?->setVisible(['id', 'name']);
        $appointment->events?->each(fn ($event) => $event->setVisible(['id', 'appointment_id', 'action', 'details', 'created_at']));
    }
}
