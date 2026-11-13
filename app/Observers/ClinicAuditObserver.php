<?php

/** Conserva los valores de negocio anteriores y nuevos sin copiar secretos de acceso. */

namespace App\Observers;

use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\DetallesAuditoria;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\RegistroAuditoria;
use Illuminate\Database\Eloquent\Model;

class ClinicAuditObserver
{
    /** Registra el alta de la entidad mediante el observador de auditoría. */
    public function created(Model $model): void
    {
        // Incluye valores predeterminados y normalizaciones aplicadas por PostgreSQL.
        $snapshot = $model->fresh() ?? $model;
        $this->write($snapshot, 'created', $snapshot->getAttributes());
    }

    /** Conserva los valores originales antes de que Eloquent sincronice el modelo. */
    public function updated(Model $model): void
    {
        $changes = array_diff_key($model->getChanges(), array_flip(['updated_at', 'remember_token']));
        if ($changes) {
            $this->write($model, 'updated', $changes);
        }
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted', $model->getAttributes());
    }

    /** Guarda el resultado de la operación siguiendo el contrato de este componente. */
    private function write(Model $model, string $action, array $attributes): void
    {
        $module = match (class_basename($model)) {
            'User' => 'users', 'Patient' => 'patients', 'Doctor' => 'doctors',
            'Specialty' => 'specialties', 'Schedule', 'Unavailability', 'DoctorQuota', 'AgendaAdjustment' => 'schedules',
            'Payment' => 'payments', 'RefundRequest' => 'refunds',
            default => 'appointments',
        };
        $changes = [];
        foreach (DetallesAuditoria::atributosPermitidos($attributes) as $field => $value) {
            $changes[$field] = [
                'before' => $action === 'created' ? null : $model->getRawOriginal($field),
                'after' => $action === 'deleted' ? null : $value,
            ];
        }
        app(RegistroAuditoria::class)->registrar($module, $action, $model, detalles: [
            'fields' => array_keys($changes), 'changes' => $changes,
            'security_changed' => $action === 'updated' && array_key_exists('password', $attributes),
        ]);
    }
}
