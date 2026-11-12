<?php

/** Retención online, intentos de pago y conciliación; solo una confirmación del servidor emite la cita. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\CU18_ReservarCitaOnline\Servicios;

use App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Servicios\PaymentVerifier;
use App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Integraciones\PayPalGateway;
use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\ClinicalSlot;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OnlineBookingService
{
    /** Inicia una retención online y su intento de pago con una clave de idempotencia. */
    public function start(array $data, User $actor): BookingRequest
    {
        if (! app(PayPalGateway::class)->configured()) {
            $this->fail('El pago online de pruebas no está disponible. Contacte a Recepción.');
        }
        $request = DB::transaction(function () use ($data, $actor) {
            $patient = Patient::whereKey($actor->patient_id)->lockForUpdate()->firstOrFail();
            $doctor = Doctor::whereKey($data['doctor_id'])->lockForUpdate()->firstOrFail();
            $previous = BookingRequest::where('idempotency_key', $data['idempotency_key'])->first();
            if ($previous) {
                if ($previous->created_by !== $actor->id || $previous->doctor_id !== $doctor->id || $previous->specialty_id !== (int) $data['specialty_id'] || $previous->date !== $data['date'] || substr($previous->start_time, 0, 5) !== $data['start_time']) {
                    $this->fail('La operación ya existe con otros datos.');
                }

                return $previous;
            }
            $slot = app(AppointmentService::class)->validateSelection($patient, $doctor, $data['specialty_id'], $data['date'], $data['start_time'], 'online');
            app(PayPalGateway::class)->snapshot(app(PaymentVerifier::class)->tariff($data['specialty_id']));

            return BookingRequest::create(['idempotency_key' => $data['idempotency_key'], 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
                'specialty_id' => $data['specialty_id'], 'created_by' => $actor->id, 'date' => $data['date'], 'start_time' => $slot['start'], 'end_time' => $slot['end'],
                'modality' => 'online', 'clinical_slot_id' => ClinicalSlot::identify($doctor->id, $data['date'], $slot['start'], $slot['end']), 'amount_minor' => app(PaymentVerifier::class)->tariff($data['specialty_id']), 'status' => 'held',
                'expires_at' => now()->addMinutes(5), 'deadline_at' => now()->addMinutes(10)]);
        }, 3);
        $request->refresh();
        if ($request->attempt === 0) {
            return $this->attempt($request);
        }

        return $request;
    }

    /** Ejecuta el intento de pago permitido sin crear un segundo cobro ante una respuesta ambigua. */
    public function attempt(BookingRequest $request): BookingRequest
    {
        $payment = DB::transaction(function () use ($request) {
            $r = BookingRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'held' || $r->attempt >= 2 || now()->gte($r->deadline_at)) {
                $this->fail('No hay otro intento disponible. Primero consulte el estado del pago.');
            }
            if ($r->payments()->whereNotIn('status', ['failed'])->exists()) {
                $this->fail('Existe una transacción confirmada, pendiente o sin conciliar. No se genera otro cobro.');
            }
            $r->update(['status' => 'processing', 'attempt' => $r->attempt + 1, 'expires_at' => now()->addMinutes(5)->min($r->deadline_at)]);

            return Payment::create(['booking_request_id' => $r->id, 'attempt' => $r->attempt, 'method' => 'paypal', 'amount_minor' => $r->amount_minor, 'status' => 'initializing']
                + app(PayPalGateway::class)->snapshot($r->amount_minor));
        });
        try {
            $data = app(PayPalGateway::class)->create($payment);
            DB::transaction(function () use ($payment, $data) {
                $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($p->reference && $p->reference !== $data['reference']) {
                    throw new \RuntimeException('Referencia de pago inconsistente.');
                }
                $p->fill(['reference' => $data['reference'], 'approval_url' => $data['approval_url']]);
                // A webhook can confirm while the generation HTTP response is still in flight.
                if ($p->status !== 'confirmed') {
                    $p->fill(['status' => 'pending', 'next_check_at' => now()->addSeconds(30)]);
                }
                $p->save();
            });
        } catch (\Throwable $e) {
            // A timeout can hide a provider-side charge. Do not retry or free its slot blindly.
            DB::transaction(function () use ($payment) {
                $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($p->status !== 'confirmed') {
                    $p->update(['status' => 'unknown']);
                }
            });
            Log::warning('PayPal no confirmó la creación de la orden Sandbox.', ['payment_id' => $payment->id, 'error_type' => get_class($e)]);
        }

        return $request->fresh();
    }

    /** CU18 FA02: descartar solo cuando no pueda quedar un cobro en proceso. */
    public function cancel(BookingRequest $request): BookingRequest
    {
        $request = $this->reconcile($request, true, true);

        return DB::transaction(function () use ($request) {
            Patient::whereKey($request->patient_id)->lockForUpdate()->firstOrFail();
            Doctor::whereKey($request->doctor_id)->lockForUpdate()->firstOrFail();
            $current = BookingRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $payments = $current->payments()->orderBy('id')->lockForUpdate()->get();
            if ($current->status === 'completed' || $payments->contains(fn ($p) => $p->status === 'confirmed')) {
                $this->fail('El pago ya fue confirmado y la cita está formalizada. Su cancelación corresponde a Recepción.');
            }
            if ($payments->contains(fn ($p) => $p->status !== 'failed')) {
                $this->fail('No se puede descartar mientras exista un pago pendiente o sin conciliar. Consulte el estado; se conserva el cupo.');
            }
            if (! in_array($current->status, ['cancelled', 'expired'])) {
                $current->update(['status' => 'cancelled']);
            }

            return $current;
        }, 3);
    }

    /** Consulta al proveedor y aplica únicamente estados de pago comprobados en el servidor. */
    public function reconcile(BookingRequest $request, bool $fromWebhook = false, bool $cancelUnapproved = false): BookingRequest
    {
        // Serializa captura y cancelación de la misma reserva, incluso durante la petición HTTP.
        return DB::transaction(function () use ($request, $fromWebhook, $cancelUnapproved) {
            Patient::whereKey($request->patient_id)->lockForUpdate()->firstOrFail();
            Doctor::whereKey($request->doctor_id)->lockForUpdate()->firstOrFail();
            $request = BookingRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $payment = $request->payments()->latest('attempt')->lockForUpdate()->first();
            if (! in_array($request->status, ['processing', 'held'])) {
                return $request;
            }
            if (! $payment || $payment->status === 'failed') {
                return DB::transaction(function () use ($request) {
                    $r = BookingRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
                    $last = $r->payments()->latest('attempt')->first();
                    if ($r->status === 'held' && ((! $last && now()->gte($r->expires_at)) || ($last?->status === 'failed' && now()->gte($r->deadline_at)))) {
                        $r->update(['status' => 'expired']);
                    }

                    return $r;
                });
            }
            // Los registros históricos de otro proveedor no se reinterpretan como órdenes PayPal.
            if ($payment->method !== 'paypal') {
                return $request;
            }
            // Respect provider backoff; repeated clicks do not create aggressive external polling.
            if (! $fromWebhook && $payment->next_check_at?->isFuture()) {
                return $request;
            }
            try {
                $result = app(PayPalGateway::class)->status($payment, $cancelUnapproved);
                $state = $result['status'];
            } catch (\Throwable $e) {
                $payment->update(['poll_count' => $payment->poll_count + 1, 'next_check_at' => now()->addSeconds(min(480, 30 * (2 ** min(4, $payment->poll_count))))]);
                Log::warning('PayPal no respondió a conciliación Sandbox.', ['payment_id' => $payment->id, 'error_type' => get_class($e)]);

                return $request;
            }

            return DB::transaction(function () use ($request, $payment, $state, $result) {
                Patient::whereKey($request->patient_id)->lockForUpdate()->firstOrFail();
                Doctor::whereKey($request->doctor_id)->lockForUpdate()->firstOrFail();
                $r = BookingRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
                $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($r->status === 'completed') {
                    return $r;
                }
                // Monotonic confirmation: a concurrent stale response cannot undo a confirmed payment.
                if ($p->status === 'confirmed') {
                    $state = 'confirmed';
                }
                $p->fill(array_intersect_key($result, array_flip(['reference', 'approval_url', 'capture_id'])));
                $p->update(['status' => $state, 'confirmed_at' => $state === 'confirmed' ? ($p->confirmed_at ?? now()) : null,
                    'poll_count' => $p->poll_count + 1, 'next_check_at' => now()->addSeconds(min(480, 30 * (2 ** min(4, $p->poll_count))))]);
                if ($state === 'confirmed') {
                    $a = Appointment::firstOrCreate(['booking_request_id' => $r->id], ['patient_id' => $r->patient_id, 'doctor_id' => $r->doctor_id,
                        'specialty_id' => $r->specialty_id, 'date' => $r->date, 'start_time' => $r->start_time, 'end_time' => $r->end_time,
                        'modality' => 'online', 'clinical_slot_id' => $r->clinical_slot_id, 'payment_id' => $p->id, 'status' => 'Programada', 'created_by' => $r->created_by]);
                    app(AppointmentService::class)->event($a, User::findOrFail($r->created_by), 'Pago online confirmado y ficha emitida', ['payment_id' => $p->id]);
                    $r->update(['status' => 'completed']);
                } elseif ($state === 'failed') {
                    $r->update(['status' => $r->attempt >= 2 || now()->gte($r->deadline_at) ? 'expired' : 'held']);
                }

                return $r;
            }, 3);
        }, 3);
    }

    /** Interrumpe la operación con un error de validación comprensible. */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
