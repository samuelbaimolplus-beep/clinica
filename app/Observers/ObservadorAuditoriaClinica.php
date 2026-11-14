<?php

/** Conserva los valores de negocio anteriores y nuevos sin copiar secretos de acceso. */

namespace App\Observers;

use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\DetallesAuditoria;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\RegistroAuditoria;
use Illuminate\Database\Eloquent\Model;

class ObservadorAuditoriaClinica
{
    /** Registra el alta de la entidad mediante el observador de auditoría. */
    public function created(Model $modelo): void
    {
        // Incluye valores predeterminados y normalizaciones aplicadas por PostgreSQL.
        $instantanea = $modelo->fresh() ?? $modelo;
        $this->registrarCambio($instantanea, 'created', $instantanea->getAttributes());
    }

    /** Conserva los valores originales antes de que Eloquent sincronice el modelo. */
    public function updated(Model $modelo): void
    {
        $cambios = array_diff_key($modelo->getChanges(), array_flip(['updated_at', 'remember_token']));
        if ($cambios) {
            $this->registrarCambio($modelo, 'updated', $cambios);
        }
    }

    public function deleted(Model $modelo): void
    {
        $this->registrarCambio($modelo, 'deleted', $modelo->getAttributes());
    }

    /** Guarda el resultado de la operación siguiendo el contrato de este componente. */
    private function registrarCambio(Model $modelo, string $accion, array $atributos): void
    {
        $modulo = match (class_basename($modelo)) {
            'Usuario' => 'users', 'Paciente' => 'patients', 'Medico' => 'doctors',
            'Especialidad' => 'specialties', 'Horario', 'AusenciaMedica', 'CupoMedico', 'AjusteAgenda' => 'schedules',
            'Pago' => 'payments', 'SolicitudReembolso' => 'refunds',
            default => 'appointments',
        };
        $cambios = [];
        foreach (DetallesAuditoria::atributosPermitidos($atributos) as $campo => $valor) {
            $cambios[$campo] = [
                'before' => $accion === 'created' ? null : $modelo->getRawOriginal($campo),
                'after' => $accion === 'deleted' ? null : $valor,
            ];
        }
        app(RegistroAuditoria::class)->registrar($modulo, $accion, $modelo, detalles: [
            'fields' => array_keys($cambios), 'changes' => $cambios,
            'security_changed' => $accion === 'updated' && array_key_exists('password', $atributos),
        ]);
    }
}
