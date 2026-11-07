<?php

namespace App\Observers;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class ClinicAuditObserver
{
    public function created(Model $model): void
    {
        $this->write($model, 'created', array_keys($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $fields = array_diff(array_keys($model->getChanges()), ['updated_at', 'remember_token']);
        if ($fields) {
            $this->write($model, 'updated', $fields);
        }
    }

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
