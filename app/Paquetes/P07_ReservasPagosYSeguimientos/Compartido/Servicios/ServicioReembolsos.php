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
class ServicioReembolsos
{
    /** Evalúa y registra una solicitud de devolución sin cancelar automáticamente la cita. */
    public function solicitar(int $id, string $motivo, User $responsable): RefundRequest
    {
        return DB::transaction(function () use ($id, $motivo, $responsable) {
            $cita = Appointment::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($responsable->hasStaffRole('Recepcionista') || ($responsable->role === 'Paciente' && $cita->patient_id === $responsable->patient_id), 403);
            if ($cita->status !== 'Cancelada' || ! $cita->cancelled_at) {
                $this->rechazar('Primero Recepción debe cancelar la cita mediante CU11. Solicitar un reembolso no cancela la cita.');
            }
            $pago = Payment::whereKey($cita->payment_id)->lockForUpdate()->first();
            if (! $pago || $pago->status !== 'confirmed') {
                $this->rechazar('La cita no tiene un pago confirmado reembolsable.');
            }
            if (RefundRequest::where('payment_id', $pago->id)->exists()) {
                $this->rechazar('Ya existe una solicitud para este pago.');
            }
            $inicio = CarbonImmutable::parse($cita->date.' '.$cita->start_time);
            $canceladoEl = CarbonImmutable::parse($cita->cancelled_at);
            $pagadoEl = $pago->confirmed_at;
            $procede = $cita->cancellation_cause === 'clinic' || $canceladoEl->lte($inicio->subHours(24))
                || ($pagadoEl && $pagadoEl->gt($inicio->subHours(24)) && $pagadoEl->lt($inicio) && $canceladoEl->betweenIncluded($pagadoEl, $pagadoEl->addMinutes(15)));

            return RefundRequest::create(['appointment_id' => $cita->id, 'payment_id' => $pago->id, 'created_by' => $responsable->id,
                'amount_minor' => $pago->amount_minor, 'reason' => $motivo, 'status' => $procede ? 'pending' : 'ineligible',
                'decision_reason' => $procede ? null : 'No cumple anticipación de 24 horas ni gracia de 15 minutos; no consta causa atribuible a la clínica.']);
        });
    }

    /** Registra aprobación o rechazo autorizado y mantiene pendiente la entrega del dinero. */
    public function resolver(int $id, array $datos, User $responsable): RefundRequest
    {
        abort_unless($responsable->hasStaffRole('Administrador'), 403);

        return DB::transaction(function () use ($id, $datos, $responsable) {
            $solicitudReembolso = RefundRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($solicitudReembolso->created_by === $responsable->id, 403, 'No puede resolver su propia solicitud de reembolso.');
            if ($solicitudReembolso->status !== 'pending') {
                $this->rechazar('Solo se resuelven solicitudes admitidas pendientes de decisión.');
            }
            $solicitudReembolso->update(['status' => $datos['decision'] === 'approve' ? 'approved' : 'rejected', 'decision_reason' => $datos['reason'] ?? null,
                'resolved_by' => $responsable->id, 'resolved_at' => now()]);

            return $solicitudReembolso;
        });
    }

    /** Registra la entrega externa completa después de una aprobación válida. */
    public function registrarEntrega(int $id, array $datos, User $responsable): RefundRequest
    {
        abort_unless($responsable->hasStaffRole('Administrador'), 403);

        return DB::transaction(function () use ($id, $datos) {
            $solicitudReembolso = RefundRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($solicitudReembolso->status !== 'approved') {
                $this->rechazar('Se requiere aprobación previa y no puede duplicarse la devolución.');
            }
            if ((int) $datos['amount_minor'] !== $solicitudReembolso->amount_minor) {
                $this->rechazar('El importe debe coincidir con el reembolso completo autorizado.');
            }
            if (CarbonImmutable::parse($datos['completed_at'])->lt(CarbonImmutable::parse($solicitudReembolso->resolved_at))) {
                $this->rechazar('La ejecución no puede ser anterior a la autorización.');
            }
            $solicitudReembolso->update(['status' => 'completed', 'executor' => $datos['executor'], 'method' => $datos['method'], 'reference' => $datos['reference'] ?? null, 'completed_at' => $datos['completed_at']]);

            return $solicitudReembolso;
        });
    }

    /** Interrumpe la operación con un error de validación comprensible. */
    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['refund' => $mensaje]);
    }
}
