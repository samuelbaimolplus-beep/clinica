<?php

/** Coordinación de citas, franjas, pagos y estados dentro de transacciones con bloqueos contra concurrencia. */

namespace App\Paquetes\P05_CitasMedicas\Compartido\Servicios;

use App\Models\AgendaAdjustment;
use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\ClinicalSlot;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\RescheduleRequest;
use App\Models\Specialty;
use App\Models\User;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Servicios\PaymentVerifier;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU21_RegistrarCitaDeSeguimiento\Servicios\FollowupPolicy;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\DoctorQuotaService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Coordinador CU09/CU10/CU21: pago o exención y ficha se confirman en una transacción. */
class AppointmentService
{
    /** Devuelve franjas con su estado, descontando ocupación, retenciones y horarios pasados. */
    public function slots(int $doctorId, string $date, ?int $exclude = null, string $modality = 'presential', ?int $hold = null): array
    {
        if (Doctor::findOrFail($doctorId)->status !== 'Activo') {
            return [];
        }
        $busy = Appointment::where('doctor_id', $doctorId)->where('date', $date)->whereIn('status', Appointment::ACTIVE)
            ->when($exclude, fn ($q) => $q->whereKeyNot($exclude))->get();
        // A pending/unknown provider transaction retains its slot even beyond the local timer (RN43).
        $holds = BookingRequest::where('doctor_id', $doctorId)->where('date', $date)->whereIn('status', ['held', 'processing'])
            ->when($hold, fn ($q) => $q->whereKeyNot($hold))->get();

        return array_map(function ($b) use ($date, $modality, $busy, $holds) {
            $occupied = $busy->contains(fn ($a) => $a->start_time < $b['end_time'].':00' && $a->end_time > $b['start_time'].':00');
            $retained = $holds->contains(fn ($a) => $a->start_time < $b['end_time'].':00' && $a->end_time > $b['start_time'].':00');

            return $b + ['start' => $b['start_time'], 'end' => $b['end_time'], 'detail' => '',
                'status' => $b['modality'] !== $modality ? 'Otra modalidad' : ($occupied || $retained ? 'Ocupado' : (CarbonImmutable::parse($date.' '.$b['start_time']) < now() ? 'Pasado' : 'Disponible'))];
        }, app(DoctorQuotaService::class)->capacity($doctorId, $date)['blocks']);
    }

    /** Crea o reprograma la cita de forma atómica: cupo, paciente, pago, identidad de franja e historial. */
    public function book(array $data, User $actor, ?int $id = null): Appointment
    {
        abort_unless($actor->hasStaffRole('Recepcionista'), 403);

        return DB::transaction(function () use ($data, $actor, $id) {
            $original = $id ? Appointment::findOrFail($id) : null;
            $patient = Patient::whereKey($original?->patient_id ?? $data['patient_id'])->lockForUpdate()->firstOrFail();
            // Stable lock order also covers transfers between doctors.
            $doctorIds = array_unique(array_filter([$original?->doctor_id, (int) $data['doctor_id']]));
            Doctor::whereIn('id', $doctorIds)->orderBy('id')->lockForUpdate()->get();
            $doctor = Doctor::findOrFail($data['doctor_id']);
            $a = $id ? Appointment::whereKey($id)->lockForUpdate()->firstOrFail() : new Appointment;
            $specialtyId = $original?->specialty_id ?? $data['specialty_id'];
            if (! $id) {
                validator($data, ['idempotency_key' => 'required|uuid'])->validate();
                $existing = BookingRequest::where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    if ($existing->created_by !== $actor->id || $existing->patient_id !== $patient->id || $existing->doctor_id !== $doctor->id
                        || $existing->specialty_id !== (int) $specialtyId || $existing->date !== $data['date'] || substr($existing->start_time, 0, 5) !== $data['start_time']) {
                        $this->fail('La clave de operación ya se utilizó con otros datos.');
                    }

                    return $existing->appointment()->with('patient', 'doctor', 'specialty', 'events', 'payment')->firstOrFail();
                }
            }
            if ($id && ! in_array($a->status, Appointment::ACTIVE)) {
                $this->fail('Solo se reprograman citas vigentes.');
            }
            if ($id) {
                validator($data, ['cause' => 'required|in:patient,clinic', 'patient_accepted' => 'required|accepted', 'change_reason' => 'required|string|max:3000'])->validate();
            }
            $slot = $this->validateSelection($patient, $doctor, $specialtyId, $data['date'], $data['start_time'], $original?->modality ?? 'presential', $id);
            $before = $id ? $a->only(['doctor_id', 'date', 'start_time', 'end_time', 'status', 'attendance_at', 'missed_call_at']) : null;
            if ($id && $a->doctor_id === $doctor->id && $a->date === $data['date'] && substr($a->start_time, 0, 5) === $data['start_time']) {
                $this->fail('Seleccione una programación diferente de la actual.');
            }
            [$parent, $free] = app(FollowupPolicy::class)->evaluate($data, $id, $patient, (int) $specialtyId);
            $losesExemption = $id && $a->complimentary && $data['cause'] === 'patient';
            $unverifiedLegacy = $id && ! $a->complimentary && $a->payment?->status !== 'confirmed';
            $payment = (! $id && ! $free) || $losesExemption || $unverifiedLegacy
                ? app(PaymentVerifier::class)->reception($data, app(PaymentVerifier::class)->tariff($specialtyId), $actor) : null;
            $a->fill(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'specialty_id' => $specialtyId,
                'date' => $data['date'], 'start_time' => $slot['start'], 'end_time' => $slot['end']]);
            $a->clinical_slot_id = ClinicalSlot::identify($doctor->id, $data['date'], $slot['start'], $slot['end']);
            if ($id) {
                $a->fill(['status' => 'Programada', 'attendance_at' => null, 'missed_call_at' => null]);
            }
            if (! $id) {
                $request = BookingRequest::create(['idempotency_key' => $data['idempotency_key'], 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
                    'specialty_id' => $specialtyId, 'created_by' => $actor->id, 'date' => $data['date'], 'start_time' => $slot['start'], 'end_time' => $slot['end'],
                    'modality' => 'presential', 'clinical_slot_id' => $a->clinical_slot_id, 'amount_minor' => $payment?->amount_minor ?? 0, 'status' => 'completed', 'expires_at' => now(), 'deadline_at' => now()]);
                if ($payment) {
                    $payment->update(['booking_request_id' => $request->id]);
                }
                $a->fill(['booking_request_id' => $request->id, 'status' => 'Programada', 'reason' => $data['reason'] ?? null,
                    'created_by' => $actor->id, 'modality' => 'presential', 'parent_appointment_id' => $parent?->id, 'complimentary' => $free]);
            }
            if ($payment) {
                $a->fill(['payment_id' => $payment->id, 'complimentary' => false]);
            }
            $a->save();
            $this->consumeDemand($a);
            if ($id) {
                RescheduleRequest::where('appointment_id', $id)->where('status', 'pending')->get()->each->update([
                    'status' => 'accepted', 'response' => $data['change_reason'], 'resolved_by' => $actor->id, 'resolved_at' => now()]);
            }
            $this->event($a, $actor, $id ? 'Reprogramación' : 'Creación', ['before' => $before, 'after' => $a->only(['doctor_id', 'date', 'start_time', 'end_time']),
                'cause' => $data['cause'] ?? ($parent ? ($data['followup_change_cause'] ?? 'patient') : null), 'patient_accepted' => $data['patient_accepted'] ?? null, 'reason' => $data['change_reason'] ?? $data['followup_change_reason'] ?? null]);

            return $a->load('patient', 'doctor', 'specialty', 'events', 'payment');
        }, 3);
    }

    /** Revalida médico, especialidad, paciente, horario y superposiciones dentro de la operación del servidor. */
    public function validateSelection(Patient $patient, Doctor $doctor, int $specialtyId, string $date, string $start, string $modality, ?int $exclude = null, ?int $hold = null): array
    {
        $specialty = Specialty::findOrFail($specialtyId);
        if ($patient->status !== 'Activo' || $doctor->status !== 'Activo' || $specialty->status !== 'Activo' || ! $doctor->specialties()->whereKey($specialtyId)->exists()) {
            $this->fail('Paciente, médico y especialidad deben estar activos y vinculados.');
        }
        $slot = collect($this->slots($doctor->id, $date, $exclude, $modality, $hold))->firstWhere('start', $start);
        if (! $slot || $slot['status'] !== 'Disponible') {
            $this->fail('El cupo ya no está disponible para esta modalidad.');
        }
        $demand = AgendaAdjustment::where('doctor_id', $doctor->id)->where('date', $date)->where('status', 'active')->where('kind', 'presential_block')
            ->where('start_time', '<', $slot['end'])->where('end_time', '>', $slot['start'])->first();
        if ($demand && ($modality !== 'presential' || $demand->specialty_id !== $specialtyId || $date !== today()->toDateString())) {
            $this->fail('El bloqueo presencial corresponde a otra especialidad.');
        }
        $overlap = fn ($q) => $q->where('patient_id', $patient->id)->where('date', $date)->where('start_time', '<', $slot['end'])->where('end_time', '>', $slot['start']);
        if (Appointment::where($overlap)->whereIn('status', Appointment::ACTIVE)->when($exclude, fn ($q) => $q->whereKeyNot($exclude))->exists()
            || BookingRequest::where($overlap)->whereIn('status', ['held', 'processing'])->when($hold, fn ($q) => $q->whereKeyNot($hold))->exists()) {
            $this->fail('El paciente tiene una cita o retención que se superpone.');
        }

        return $slot;
    }

    /** Marca como consumido el bloqueo presencial utilizado por una cita real. */
    public function consumeDemand(Appointment $a): void
    {
        if ($a->modality !== 'presential') {
            return;
        }
        AgendaAdjustment::where('doctor_id', $a->doctor_id)->where('date', $a->date)->where('start_time', $a->start_time)->where('end_time', $a->end_time)
            ->where('specialty_id', $a->specialty_id)->where('kind', 'presential_block')->where('status', 'active')->get()
            ->each->update(['status' => 'consumed', 'appointment_id' => $a->id]);
    }

    /** Aplica una transición de estado válida; conserva el historial y evita operaciones incompatibles. */
    public function transition(int $id, string $action, User $actor, ?string $reason = null, array $data = []): Appointment
    {
        return DB::transaction(function () use ($id, $action, $actor, $reason, $data) {
            $a = Appointment::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($actor->role === 'Médico' && $a->doctor_id !== $actor->doctor_id) {
                abort(403, 'Solo puede operar su propia agenda.');
            }
            if (! in_array($a->status, Appointment::ACTIVE)) {
                $this->fail('La cita ya no está vigente.');
            }
            if (in_array($action, ['attendance', 'absent', 'attend', 'missed-call']) && $a->date !== today()->toDateString()) {
                $this->fail('Esta operación solo corresponde a citas de hoy.');
            }
            $before = $a->status;
            switch ($action) {
                case 'cancel':
                    validator($data, ['cause' => 'required|in:patient,clinic'])->validate();
                    $a->fill(['status' => 'Cancelada', 'cancellation_reason' => $reason, 'cancellation_cause' => $data['cause'], 'cancelled_at' => now()]);
                    RescheduleRequest::where('appointment_id', $a->id)->where('status', 'pending')->get()->each->update(['status' => 'closed', 'response' => 'Cita cancelada por Recepción.', 'resolved_by' => $actor->id, 'resolved_at' => now()]);
                    break;
                case 'attendance':
                    $needsPayment = $this->needsPayment($a);
                    if ($a->attendance_at && ! $needsPayment) {
                        $this->fail('La asistencia ya fue registrada.');
                    }
                    if ($needsPayment) {
                        $payment = app(PaymentVerifier::class)->reception($data, app(PaymentVerifier::class)->tariff($a->specialty_id), $actor);
                        $a->fill(['payment_id' => $payment->id, 'complimentary' => false]);
                    }
                    $a->fill(['status' => 'Confirmada', 'attendance_at' => $a->attendance_at ?? now()]);
                    break;
                case 'missed-call':
                    if ($a->attendance_at) {
                        $this->fail('El paciente ya está presente.');
                    }
                    $a->missed_call_at = now();
                    break;
                case 'absent':
                    if ($a->attendance_at) {
                        $this->fail('El paciente ya registró asistencia.');
                    }
                    validator($data, ['definitive' => 'required|accepted', 'reason' => 'required|string|max:3000'])->validate();
                    $a->status = 'Inasistencia';
                    break;
                case 'attend':
                    if ($this->needsPayment($a)) {
                        $this->fail('Recepción debe verificar el pago antes de continuar la atención.');
                    }
                    if (! $a->attendance_at || $a->status !== 'Confirmada') {
                        $this->fail('Debe registrarse la presencia antes de finalizar la consulta.');
                    }
                    validator($data, ['consultation_finished' => 'required|accepted', 'followup_suggested_at' => 'nullable|date|after:now', 'followup_note' => 'nullable|string|max:3000'])->validate();
                    $a->fill(['status' => 'Atendida', 'attended_at' => now(), 'followup_suggested_at' => $data['followup_suggested_at'] ?? null, 'followup_note' => $data['followup_note'] ?? null]);
                    break;
                default: abort(404);
            }
            $a->save();
            $this->event($a, $actor, $action, ['before' => $before, 'after' => $a->status, 'reason' => $reason, 'attendance_at' => $a->attendance_at]);

            return $a->load('patient', 'doctor', 'specialty', 'events', 'payment');
        });
    }

    /** Añade el cambio al historial de la cita con actor y datos permitidos. */
    public function event(Appointment $a, User $actor, string $action, array $details): void
    {
        DB::statement('CALL public.registrar_evento_cita(?, ?, ?, ?::jsonb)', [
            $a->id, $actor->id, $action, json_encode((object) $details, JSON_THROW_ON_ERROR),
        ]);
    }

    /** Comprueba si el pago confirmado o la exención vigente permiten continuar la atención. */
    public function needsPayment(Appointment $a): bool
    {
        if ($a->complimentary) {
            $arrival = $a->attendance_at ? CarbonImmutable::parse($a->attendance_at) : now();

            return $a->missed_call_at !== null || $arrival->gt(CarbonImmutable::parse($a->date.' '.$a->start_time));
        }

        return $a->payment?->status !== 'confirmed';
    }

    /** Interrumpe la operación con un error de validación comprensible. */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['appointment' => $message]);
    }
}
