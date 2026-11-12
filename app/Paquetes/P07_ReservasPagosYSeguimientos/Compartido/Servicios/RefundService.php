<?php

/** Separa solicitud, política de admisión, decisión administrativa y registro de entrega del reembolso. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Servicios;

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU20 admite o rechaza automáticamente; CU26 decide y registra la devolución por separado. */
class RefundService
{
    /** Evalúa y registra una solicitud de devolución sin cancelar automáticamente la cita. */
    public function request(int $id, string $reason, User $actor): RefundRequest
    {
        return DB::transaction(function () use ($id, $reason, $actor) {
            $a = Appointment::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasStaffRole('Recepcionista') || ($actor->role === 'Paciente' && $a->patient_id === $actor->patient_id), 403);
            if ($a->status !== 'Cancelada' || ! $a->cancelled_at) {
                $this->fail('Primero Recepción debe cancelar la cita mediante CU11. Solicitar un reembolso no cancela la cita.');
            }
            $payment = Payment::whereKey($a->payment_id)->lockForUpdate()->first();
            if (! $payment || $payment->status !== 'confirmed') {
                $this->fail('La cita no tiene un pago confirmado reembolsable.');
            }
            if (RefundRequest::where('payment_id', $payment->id)->exists()) {
                $this->fail('Ya existe una solicitud para este pago.');
            }
            $start = CarbonImmutable::parse($a->date.' '.$a->start_time);
            $cancelled = CarbonImmutable::parse($a->cancelled_at);
            $paid = $payment->confirmed_at;
            $eligible = $a->cancellation_cause === 'clinic' || $cancelled->lte($start->subHours(24))
                || ($paid && $paid->gt($start->subHours(24)) && $paid->lt($start) && $cancelled->betweenIncluded($paid, $paid->addMinutes(15)));

            return RefundRequest::create(['appointment_id' => $a->id, 'payment_id' => $payment->id, 'created_by' => $actor->id,
                'amount_minor' => $payment->amount_minor, 'reason' => $reason, 'status' => $eligible ? 'pending' : 'ineligible',
                'decision_reason' => $eligible ? null : 'No cumple anticipación de 24 horas ni gracia de 15 minutos; no consta causa atribuible a la clínica.']);
        });
    }

    /** Registra aprobación o rechazo autorizado y mantiene pendiente la entrega del dinero. */
    public function decide(int $id, array $data, User $actor): RefundRequest
    {
        abort_unless($actor->hasStaffRole('Administrador'), 403);

        return DB::transaction(function () use ($id, $data, $actor) {
            $r = RefundRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($r->created_by === $actor->id, 403, 'No puede resolver su propia solicitud de reembolso.');
            if ($r->status !== 'pending') {
                $this->fail('Solo se resuelven solicitudes admitidas pendientes de decisión.');
            }
            $r->update(['status' => $data['decision'] === 'approve' ? 'approved' : 'rejected', 'decision_reason' => $data['reason'] ?? null,
                'resolved_by' => $actor->id, 'resolved_at' => now()]);

            return $r;
        });
    }

    /** Registra la entrega externa completa después de una aprobación válida. */
    public function complete(int $id, array $data, User $actor): RefundRequest
    {
        abort_unless($actor->hasStaffRole('Administrador'), 403);

        return DB::transaction(function () use ($id, $data) {
            $r = RefundRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'approved') {
                $this->fail('Se requiere aprobación previa y no puede duplicarse la devolución.');
            }
            if ((int) $data['amount_minor'] !== $r->amount_minor) {
                $this->fail('El importe debe coincidir con el reembolso completo autorizado.');
            }
            if (CarbonImmutable::parse($data['completed_at'])->lt(CarbonImmutable::parse($r->resolved_at))) {
                $this->fail('La ejecución no puede ser anterior a la autorización.');
            }
            $r->update(['status' => 'completed', 'executor' => $data['executor'], 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'completed_at' => $data['completed_at']]);

            return $r;
        });
    }

    /** Interrumpe la operación con un error de validación comprensible. */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['refund' => $message]);
    }
}
