<?php

namespace App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios;

use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use Illuminate\Database\Eloquent\Model;

/** Valores de negocio permitidos; nunca credenciales, sesiones ni respuestas del proveedor. */
final class AuditDetails
{
    public const FIELDS = [
        'id', 'name', 'username', 'email', 'role', 'status', 'email_verified_at',
        'registration', 'first_name', 'last_name', 'phone', 'identity', 'birth_date', 'sex', 'address',
        'description', 'doctor_id', 'patient_id', 'specialty_id', 'user_id', 'weekday',
        'start_time', 'end_time', 'duration', 'starts_at', 'ends_at', 'date', 'reason',
        'attendance_at', 'cancellation_reason', 'cancellation_cause', 'cancelled_at', 'cancelled_by',
        'created_by', 'appointment_id', 'issued_by', 'terms_version', 'terms_accepted_at',
        'presential_percent', 'online_percent', 'kind', 'released_by', 'released_at', 'demand_quantity',
        'fee_minor', 'modality', 'amount_minor', 'attempt', 'booking_request_id', 'method',
        'reference', 'bank_reference', 'qr_number', 'confirmed_by', 'confirmed_at', 'payment_id',
        'parent_appointment_id', 'complimentary', 'followup_suggested_at', 'followup_note',
        'response', 'resolved_by', 'resolved_at', 'decision_reason', 'executor', 'completed_at',
        'expires_at', 'deadline_at', 'missed_call_at', 'attended_at', 'clinical_slot_id',
        'provider_currency', 'provider_amount_minor', 'exchange_rate', 'capture_id',
        'created_at', 'updated_at', 'specialty_ids', 'specialties', 'change_cause', 'patient_accepted',
    ];

    public static function attributes(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::FIELDS));
    }

    /** La misma selección se aplica al escribir y al consultar eventos históricos. */
    public static function sanitize(array $details): array
    {
        $safe = array_intersect_key($details, array_flip([
            'fields', 'specialty_ids', 'reason', 'terms_version', 'changes', 'record',
            'actor', 'request', 'related', 'security_changed',
        ]));
        foreach (['changes', 'record'] as $key) {
            if (isset($safe[$key])) {
                $safe[$key] = self::attributes((array) $safe[$key]);
            }
        }
        if (isset($safe['fields'])) {
            $safe['fields'] = array_values(array_intersect((array) $safe['fields'], self::FIELDS));
        }
        if (isset($safe['actor'])) {
            $safe['actor'] = array_intersect_key((array) $safe['actor'], array_flip(['id', 'name', 'username', 'role']));
        }
        if (isset($safe['request'])) {
            $safe['request'] = array_intersect_key((array) $safe['request'], array_flip(['method', 'route']));
        }

        return self::withoutSecrets($safe);
    }

    private static function withoutSecrets(array $values): array
    {
        $safe = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|contrase|token|secret|credential|authorization|cookie|session_id|idempotency|approval_url|provider_payload|provider_response/i', $key)) {
                continue;
            }
            $safe[$key] = is_array($value) ? self::withoutSecrets($value) : $value;
        }

        return $safe;
    }

    /** Conserva los nombres vigentes en ese momento, aunque después se editen los catálogos. */
    public static function related(Model $model): array
    {
        $related = [];
        $source = $model;
        if (! $model->getAttribute('doctor_id')) {
            if ($model->getAttribute('appointment_id')) {
                $source = (new Appointment)->setConnection($model->getConnectionName())->find($model->getAttribute('appointment_id')) ?? $model;
            } elseif ($model->getAttribute('booking_request_id')) {
                $source = (new BookingRequest)->setConnection($model->getConnectionName())->find($model->getAttribute('booking_request_id')) ?? $model;
            }
        }
        foreach (['doctor_id' => Doctor::class, 'patient_id' => Patient::class, 'specialty_id' => Specialty::class] as $field => $class) {
            if (! $source->getAttribute($field)) {
                continue;
            }
            $record = (new $class)->setConnection($model->getConnectionName())->find($source->getAttribute($field));
            if ($record) {
                $related[$field] = ['id' => $record->id, 'label' => $record->name ?? trim($record->first_name.' '.$record->last_name)];
            }
        }

        return $related;
    }
}
