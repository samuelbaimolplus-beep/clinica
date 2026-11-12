<?php

/** Observa cambios del dominio y registra campos afectados sin copiar valores sensibles. */

namespace App\Observers;

use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class ClinicAuditObserver
{
    /** Registra el alta de la entidad mediante el observador de auditoría. */
    public function created(Model $model): void
    {
        $this->write($model, 'created', array_keys($model->getAttributes()));
    }

    /** Registra los campos modificados de la entidad sin copiar sus valores sensibles. */
    public function updated(Model $model): void
    {
        $fields = array_diff(array_keys($model->getChanges()), ['updated_at', 'remember_token']);
        if ($fields) {
            $this->write($model, 'updated', $fields);
        }
    }

    /** Guarda el resultado de la operación siguiendo el contrato de este componente. */
    private function write(Model $model, string $action, array $fields): void
    {
        $module = match (class_basename($model)) {
            'User' => 'users', 'Patient' => 'patients', 'Doctor' => 'doctors',
            'Specialty' => 'specialties', 'Schedule', 'Unavailability', 'DoctorQuota', 'AgendaAdjustment' => 'schedules',
            'Payment' => 'payments', 'RefundRequest' => 'refunds',
            default => 'appointments',
        };
        app(AuditLogger::class)->record($module, $action, $model, details: [
            'fields' => array_values(array_diff($fields, ['password', 'remember_token'])),
        ]);
    }
}
