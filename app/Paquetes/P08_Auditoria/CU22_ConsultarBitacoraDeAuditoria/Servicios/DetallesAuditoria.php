<?php

namespace App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios;

use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use Illuminate\Database\Eloquent\Model;

/** Valores de negocio permitidos; nunca credenciales, sesiones ni respuestas del proveedor. */
final class DetallesAuditoria
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

    public static function atributosPermitidos(array $atributos): array
    {
        return array_intersect_key($atributos, array_flip(self::FIELDS));
    }

    /** La misma selección se aplica al escribir y al consultar eventos históricos. */
    public static function sanear(array $detalles): array
    {
        $permitidos = array_intersect_key($detalles, array_flip([
            'fields', 'specialty_ids', 'reason', 'terms_version', 'changes', 'record',
            'actor', 'request', 'related', 'security_changed',
        ]));
        foreach (['changes', 'record'] as $clave) {
            if (isset($permitidos[$clave])) {
                $permitidos[$clave] = self::atributosPermitidos((array) $permitidos[$clave]);
            }
        }
        if (isset($permitidos['fields'])) {
            $permitidos['fields'] = array_values(array_intersect((array) $permitidos['fields'], self::FIELDS));
        }
        if (isset($permitidos['actor'])) {
            $permitidos['actor'] = array_intersect_key((array) $permitidos['actor'], array_flip(['id', 'name', 'username', 'role']));
        }
        if (isset($permitidos['request'])) {
            $permitidos['request'] = array_intersect_key((array) $permitidos['request'], array_flip(['method', 'route']));
        }

        return self::excluirSecretos($permitidos);
    }

    private static function excluirSecretos(array $valores): array
    {
        $permitidos = [];
        foreach ($valores as $clave => $valor) {
            if (is_string($clave) && preg_match('/password|contrase|token|secret|credential|authorization|cookie|session_id|idempotency|approval_url|provider_payload|provider_response/i', $clave)) {
                continue;
            }
            $permitidos[$clave] = is_array($valor) ? self::excluirSecretos($valor) : $valor;
        }

        return $permitidos;
    }

    /** Conserva los nombres vigentes en ese momento, aunque después se editen los catálogos. */
    public static function registrosRelacionados(Model $modelo): array
    {
        $relacionados = [];
        $origen = $modelo;
        if (! $modelo->getAttribute('doctor_id')) {
            if ($modelo->getAttribute('appointment_id')) {
                $origen = (new Appointment)->setConnection($modelo->getConnectionName())->find($modelo->getAttribute('appointment_id')) ?? $modelo;
            } elseif ($modelo->getAttribute('booking_request_id')) {
                $origen = (new BookingRequest)->setConnection($modelo->getConnectionName())->find($modelo->getAttribute('booking_request_id')) ?? $modelo;
            }
        }
        foreach (['doctor_id' => Doctor::class, 'patient_id' => Patient::class, 'specialty_id' => Specialty::class] as $campo => $clase) {
            if (! $origen->getAttribute($campo)) {
                continue;
            }
            $registro = (new $clase)->setConnection($modelo->getConnectionName())->find($origen->getAttribute($campo));
            if ($registro) {
                $relacionados[$campo] = ['id' => $registro->id, 'label' => $registro->name ?? trim($registro->first_name.' '.$registro->last_name)];
            }
        }

        return $relacionados;
    }
}
