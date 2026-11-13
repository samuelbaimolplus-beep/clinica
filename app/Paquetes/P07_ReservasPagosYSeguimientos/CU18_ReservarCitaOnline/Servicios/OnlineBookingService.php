<?php

/** Retención online, intentos de pago y conciliación; solo una confirmación del servidor emite la cita. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\CU18_ReservarCitaOnline\Servicios;

use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\ClinicalSlot;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Integraciones\PayPalGateway;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Servicios\PaymentVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OnlineBookingService
{
    /** Inicia una retención online y su intento de pago con una clave de idempotencia. */
    public function iniciar(array $datos, User $responsable): BookingRequest
    {
        if (! app(PayPalGateway::class)->estaConfigurada()) {
            $this->rechazar('El pago online de pruebas no está disponible. Contacte a Recepción.');
        }
        $reserva = DB::transaction(function () use ($datos, $responsable) {
            $paciente = Patient::whereKey($responsable->patient_id)->lockForUpdate()->firstOrFail();
            $medico = Doctor::whereKey($datos['doctor_id'])->lockForUpdate()->firstOrFail();
            $anterior = BookingRequest::where('idempotency_key', $datos['idempotency_key'])->first();
            if ($anterior) {
                if ($anterior->created_by !== $responsable->id || $anterior->doctor_id !== $medico->id || $anterior->specialty_id !== (int) $datos['specialty_id'] || $anterior->date !== $datos['date'] || substr($anterior->start_time, 0, 5) !== $datos['start_time']) {
                    $this->rechazar('La operación ya existe con otros datos.');
                }

                return $anterior;
            }
            $franja = app(AppointmentService::class)->validarSeleccion($paciente, $medico, $datos['specialty_id'], $datos['date'], $datos['start_time'], 'online');
            app(PayPalGateway::class)->instantaneaImporte(app(PaymentVerifier::class)->tarifa($datos['specialty_id']));

            return BookingRequest::create(['idempotency_key' => $datos['idempotency_key'], 'patient_id' => $paciente->id, 'doctor_id' => $medico->id,
                'specialty_id' => $datos['specialty_id'], 'created_by' => $responsable->id, 'date' => $datos['date'], 'start_time' => $franja['start'], 'end_time' => $franja['end'],
                'modality' => 'online', 'clinical_slot_id' => ClinicalSlot::identify($medico->id, $datos['date'], $franja['start'], $franja['end']), 'amount_minor' => app(PaymentVerifier::class)->tarifa($datos['specialty_id']), 'status' => 'held',
                'expires_at' => now()->addMinutes(5), 'deadline_at' => now()->addMinutes(10)]);
        }, 3);
        $reserva->refresh();
        if ($reserva->attempt === 0) {
            return $this->intentarPago($reserva);
        }

        return $reserva;
    }

    /** Ejecuta el intento de pago permitido sin crear un segundo cobro ante una respuesta ambigua. */
    public function intentarPago(BookingRequest $reserva): BookingRequest
    {
        $pago = DB::transaction(function () use ($reserva) {
            $reservaBloqueada = BookingRequest::whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            if ($reservaBloqueada->status !== 'held' || $reservaBloqueada->attempt >= 2 || now()->gte($reservaBloqueada->deadline_at)) {
                $this->rechazar('No hay otro intento disponible. Primero consulte el estado del pago.');
            }
            if ($reservaBloqueada->payments()->whereNotIn('status', ['failed'])->exists()) {
                $this->rechazar('Existe una transacción confirmada, pendiente o sin conciliar. No se genera otro cobro.');
            }
            $reservaBloqueada->update(['status' => 'processing', 'attempt' => $reservaBloqueada->attempt + 1, 'expires_at' => now()->addMinutes(5)->min($reservaBloqueada->deadline_at)]);

            return Payment::create(['booking_request_id' => $reservaBloqueada->id, 'attempt' => $reservaBloqueada->attempt, 'method' => 'paypal', 'amount_minor' => $reservaBloqueada->amount_minor, 'status' => 'initializing']
                + app(PayPalGateway::class)->instantaneaImporte($reservaBloqueada->amount_minor));
        });
        try {
            $datos = app(PayPalGateway::class)->crearOrden($pago);
            DB::transaction(function () use ($pago, $datos) {
                $pagoBloqueado = Payment::whereKey($pago->id)->lockForUpdate()->firstOrFail();
                if ($pagoBloqueado->reference && $pagoBloqueado->reference !== $datos['reference']) {
                    throw new \RuntimeException('Referencia de pago inconsistente.');
                }
                $pagoBloqueado->fill(['reference' => $datos['reference'], 'approval_url' => $datos['approval_url']]);
                // La notificación puede confirmar el pago antes de recibir la respuesta de creación.
                if ($pagoBloqueado->status !== 'confirmed') {
                    $pagoBloqueado->fill(['status' => 'pending', 'next_check_at' => now()->addSeconds(30)]);
                }
                $pagoBloqueado->save();
            });
        } catch (\Throwable $error) {
            // Una espera agotada puede ocultar un cobro del proveedor: se conserva el cupo hasta conciliar.
            DB::transaction(function () use ($pago) {
                $pagoBloqueado = Payment::whereKey($pago->id)->lockForUpdate()->firstOrFail();
                if ($pagoBloqueado->status !== 'confirmed') {
                    $pagoBloqueado->update(['status' => 'unknown']);
                }
            });
            Log::warning('PayPal no confirmó la creación de la orden Sandbox.', ['payment_id' => $pago->id, 'error_type' => get_class($error)]);
        }

        return $reserva->fresh();
    }

    /** CU18 FA02: descartar solo cuando no pueda quedar un cobro en proceso. */
    public function descartar(BookingRequest $reserva): BookingRequest
    {
        $reserva = $this->conciliar($reserva, true, true);

        return DB::transaction(function () use ($reserva) {
            Patient::whereKey($reserva->patient_id)->lockForUpdate()->firstOrFail();
            Doctor::whereKey($reserva->doctor_id)->lockForUpdate()->firstOrFail();
            $actual = BookingRequest::whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            $pagos = $actual->payments()->orderBy('id')->lockForUpdate()->get();
            if ($actual->status === 'completed' || $pagos->contains(fn ($pagoBloqueado) => $pagoBloqueado->status === 'confirmed')) {
                $this->rechazar('El pago ya fue confirmado y la cita está formalizada. Su cancelación corresponde a Recepción.');
            }
            if ($pagos->contains(fn ($pagoBloqueado) => $pagoBloqueado->status !== 'failed')) {
                $this->rechazar('No se puede descartar mientras exista un pago pendiente o sin conciliar. Consulte el estado; se conserva el cupo.');
            }
            if (! in_array($actual->status, ['cancelled', 'expired'])) {
                $actual->update(['status' => 'cancelled']);
            }

            return $actual;
        }, 3);
    }

    /** Consulta al proveedor y aplica únicamente estados de pago comprobados en el servidor. */
    public function conciliar(BookingRequest $reserva, bool $desdeNotificacion = false, bool $descartarNoAprobado = false): BookingRequest
    {
        // Serializa captura y cancelación de la misma reserva, incluso durante la petición HTTP.
        return DB::transaction(function () use ($reserva, $desdeNotificacion, $descartarNoAprobado) {
            Patient::whereKey($reserva->patient_id)->lockForUpdate()->firstOrFail();
            Doctor::whereKey($reserva->doctor_id)->lockForUpdate()->firstOrFail();
            $reserva = BookingRequest::whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            $pago = $reserva->payments()->latest('attempt')->lockForUpdate()->first();
            if (! in_array($reserva->status, ['processing', 'held'])) {
                return $reserva;
            }
            if (! $pago || $pago->status === 'failed') {
                return DB::transaction(function () use ($reserva) {
                    $reservaBloqueada = BookingRequest::whereKey($reserva->id)->lockForUpdate()->firstOrFail();
                    $ultimo = $reservaBloqueada->payments()->latest('attempt')->first();
                    if ($reservaBloqueada->status === 'held' && ((! $ultimo && now()->gte($reservaBloqueada->expires_at)) || ($ultimo?->status === 'failed' && now()->gte($reservaBloqueada->deadline_at)))) {
                        $reservaBloqueada->update(['status' => 'expired']);
                    }

                    return $reservaBloqueada;
                });
            }
            // Los registros históricos de otro proveedor no se reinterpretan como órdenes PayPal.
            if ($pago->method !== 'paypal') {
                return $reserva;
            }
            // Se respeta la espera entre consultas al proveedor aunque el usuario pulse repetidamente.
            if (! $desdeNotificacion && $pago->next_check_at?->isFuture()) {
                return $reserva;
            }
            try {
                $resultado = app(PayPalGateway::class)->consultarEstado($pago, $descartarNoAprobado);
                $estadoPago = $resultado['status'];
            } catch (\Throwable $error) {
                $pago->update(['poll_count' => $pago->poll_count + 1, 'next_check_at' => now()->addSeconds(min(480, 30 * (2 ** min(4, $pago->poll_count))))]);
                Log::warning('PayPal no respondió a conciliación Sandbox.', ['payment_id' => $pago->id, 'error_type' => get_class($error)]);

                return $reserva;
            }

            return DB::transaction(function () use ($reserva, $pago, $estadoPago, $resultado) {
                Patient::whereKey($reserva->patient_id)->lockForUpdate()->firstOrFail();
                Doctor::whereKey($reserva->doctor_id)->lockForUpdate()->firstOrFail();
                $reservaBloqueada = BookingRequest::whereKey($reserva->id)->lockForUpdate()->firstOrFail();
                $pagoBloqueado = Payment::whereKey($pago->id)->lockForUpdate()->firstOrFail();
                if ($reservaBloqueada->status === 'completed') {
                    return $reservaBloqueada;
                }
                // Una respuesta concurrente atrasada nunca revierte un pago ya confirmado.
                if ($pagoBloqueado->status === 'confirmed') {
                    $estadoPago = 'confirmed';
                }
                $pagoBloqueado->fill(array_intersect_key($resultado, array_flip(['reference', 'approval_url', 'capture_id'])));
                $pagoBloqueado->update(['status' => $estadoPago, 'confirmed_at' => $estadoPago === 'confirmed' ? ($pagoBloqueado->confirmed_at ?? now()) : null,
                    'poll_count' => $pagoBloqueado->poll_count + 1, 'next_check_at' => now()->addSeconds(min(480, 30 * (2 ** min(4, $pagoBloqueado->poll_count))))]);
                if ($estadoPago === 'confirmed') {
                    $citaActual = Appointment::firstOrCreate(['booking_request_id' => $reservaBloqueada->id], ['patient_id' => $reservaBloqueada->patient_id, 'doctor_id' => $reservaBloqueada->doctor_id,
                        'specialty_id' => $reservaBloqueada->specialty_id, 'date' => $reservaBloqueada->date, 'start_time' => $reservaBloqueada->start_time, 'end_time' => $reservaBloqueada->end_time,
                        'modality' => 'online', 'clinical_slot_id' => $reservaBloqueada->clinical_slot_id, 'payment_id' => $pagoBloqueado->id, 'status' => 'Programada', 'created_by' => $reservaBloqueada->created_by]);
                    app(AppointmentService::class)->registrarEvento($citaActual, User::findOrFail($reservaBloqueada->created_by), 'Pago online confirmado y ficha emitida', ['payment_id' => $pagoBloqueado->id]);
                    $reservaBloqueada->update(['status' => 'completed']);
                } elseif ($estadoPago === 'failed') {
                    $reservaBloqueada->update(['status' => $reservaBloqueada->attempt >= 2 || now()->gte($reservaBloqueada->deadline_at) ? 'expired' : 'held']);
                }

                return $reservaBloqueada;
            }, 3);
        }, 3);
    }

    /** Interrumpe la operación con un error de validación comprensible. */
    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['payment' => $mensaje]);
    }
}
