<?php

/** Coordinación de citas, franjas, pagos y estados dentro de transacciones con bloqueos contra concurrencia. */

namespace App\Paquetes\P05_CitasMedicas\Compartido\Servicios;

use App\Models\AjusteAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\FranjaClinica;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\SolicitudReprogramacion;
use App\Models\SolicitudReserva;
use App\Models\Usuario;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\ServicioCuposMedico;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Servicios\VerificadorPago;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU21_RegistrarCitaDeSeguimiento\Servicios\ReglasSeguimiento;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\DetallesAuditoria;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\RegistroAuditoria;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Coordinador CU09/CU10/CU21: pago o exención y ficha se confirman en una transacción. */
class ServicioCitas
{
    /** Devuelve franjas con su estado, descontando ocupación, retenciones y horarios pasados. */
    public function franjasDisponibles(int $medicoId, string $fecha, ?int $excluirId = null, string $modalidad = 'presential', ?int $retencion = null): array
    {
        if (Medico::findOrFail($medicoId)->status !== 'Activo') {
            return [];
        }
        $ocupadas = Cita::where('doctor_id', $medicoId)->where('date', $fecha)->whereIn('status', Cita::ACTIVE)
            ->when($excluirId, fn ($consulta) => $consulta->whereKeyNot($excluirId))->get();
        // Un pago pendiente o desconocido conserva su franja aunque venza el temporizador local (RN43).
        $retenciones = SolicitudReserva::where('doctor_id', $medicoId)->where('date', $fecha)->whereIn('status', ['held', 'processing'])
            ->when($retencion, fn ($consulta) => $consulta->whereKeyNot($retencion))->get();

        return array_map(function ($bloque) use ($fecha, $modalidad, $ocupadas, $retenciones) {
            $ocupada = $ocupadas->contains(fn ($citaActual) => $citaActual->start_time < $bloque['end_time'].':00' && $citaActual->end_time > $bloque['start_time'].':00');
            $retenida = $retenciones->contains(fn ($citaActual) => $citaActual->start_time < $bloque['end_time'].':00' && $citaActual->end_time > $bloque['start_time'].':00');

            return $bloque + ['start' => $bloque['start_time'], 'end' => $bloque['end_time'], 'detail' => '',
                'status' => $bloque['modality'] !== $modalidad ? 'Otra modalidad' : ($ocupada || $retenida ? 'Ocupado' : (CarbonImmutable::parse($fecha.' '.$bloque['start_time']) < now() ? 'Pasado' : 'Disponible'))];
        }, app(ServicioCuposMedico::class)->calcularCapacidad($medicoId, $fecha)['blocks']);
    }

    /** Crea o reprograma la cita de forma atómica: cupo, paciente, pago, identidad de franja e historial. */
    public function programar(array $datos, Usuario $responsable, ?int $id = null): Cita
    {
        abort_unless($responsable->tieneRolPersonal('Recepcionista'), 403);

        return DB::transaction(function () use ($datos, $responsable, $id) {
            $original = $id ? Cita::findOrFail($id) : null;
            $paciente = Paciente::whereKey($original?->patient_id ?? $datos['patient_id'])->lockForUpdate()->firstOrFail();
            // El orden estable de bloqueo también protege los cambios de un médico a otro.
            $medicosIds = array_unique(array_filter([$original?->doctor_id, (int) $datos['doctor_id']]));
            Medico::whereIn('id', $medicosIds)->orderBy('id')->lockForUpdate()->get();
            $medico = Medico::findOrFail($datos['doctor_id']);
            $citaActual = $id ? Cita::whereKey($id)->lockForUpdate()->firstOrFail() : new Cita;
            $especialidadId = $original?->specialty_id ?? $datos['specialty_id'];
            if (! $id) {
                validator($datos, ['idempotency_key' => 'required|uuid'])->validate();
                $existente = SolicitudReserva::where('idempotency_key', $datos['idempotency_key'])->first();
                if ($existente) {
                    if ($existente->created_by !== $responsable->id || $existente->patient_id !== $paciente->id || $existente->doctor_id !== $medico->id
                        || $existente->specialty_id !== (int) $especialidadId || $existente->date !== $datos['date'] || substr($existente->start_time, 0, 5) !== $datos['start_time']) {
                        $this->rechazar('La clave de operación ya se utilizó con otros datos.');
                    }

                    return $existente->appointment()->with('patient', 'doctor', 'specialty', 'events', 'payment')->firstOrFail();
                }
            }
            if ($id && ! in_array($citaActual->status, Cita::ACTIVE)) {
                $this->rechazar('Solo se reprograman citas vigentes.');
            }
            if ($id) {
                validator($datos, ['cause' => 'required|in:patient,clinic', 'patient_accepted' => 'required|accepted', 'change_reason' => 'required|string|max:3000'])->validate();
            }
            $franja = $this->validarSeleccion($paciente, $medico, $especialidadId, $datos['date'], $datos['start_time'], $original?->modality ?? 'presential', $id);
            $antes = $id ? $citaActual->only(['doctor_id', 'date', 'start_time', 'end_time', 'status', 'attendance_at', 'missed_call_at']) : null;
            if ($id && $citaActual->doctor_id === $medico->id && $citaActual->date === $datos['date'] && substr($citaActual->start_time, 0, 5) === $datos['start_time']) {
                $this->rechazar('Seleccione una programación diferente de la actual.');
            }
            [$citaOrigen, $esGratuita] = app(ReglasSeguimiento::class)->evaluar($datos, $id, $paciente, (int) $especialidadId);
            $pierdeExencion = $id && $citaActual->complimentary && $datos['cause'] === 'patient';
            $pagoHistoricoSinVerificar = $id && ! $citaActual->complimentary && $citaActual->payment?->status !== 'confirmed';
            $pago = (! $id && ! $esGratuita) || $pierdeExencion || $pagoHistoricoSinVerificar
                ? app(VerificadorPago::class)->verificarPagoPresencial($datos, app(VerificadorPago::class)->tarifa($especialidadId), $responsable) : null;
            $citaActual->fill(['patient_id' => $paciente->id, 'doctor_id' => $medico->id, 'specialty_id' => $especialidadId,
                'date' => $datos['date'], 'start_time' => $franja['start'], 'end_time' => $franja['end']]);
            $citaActual->clinical_slot_id = FranjaClinica::identificar($medico->id, $datos['date'], $franja['start'], $franja['end']);
            if ($id) {
                $citaActual->fill(['status' => 'Programada', 'attendance_at' => null, 'missed_call_at' => null]);
            }
            if (! $id) {
                $solicitud = SolicitudReserva::create(['idempotency_key' => $datos['idempotency_key'], 'patient_id' => $paciente->id, 'doctor_id' => $medico->id,
                    'specialty_id' => $especialidadId, 'created_by' => $responsable->id, 'date' => $datos['date'], 'start_time' => $franja['start'], 'end_time' => $franja['end'],
                    'modality' => 'presential', 'clinical_slot_id' => $citaActual->clinical_slot_id, 'amount_minor' => $pago?->amount_minor ?? 0, 'status' => 'completed', 'expires_at' => now(), 'deadline_at' => now()]);
                if ($pago) {
                    $pago->update(['booking_request_id' => $solicitud->id]);
                }
                $citaActual->fill(['booking_request_id' => $solicitud->id, 'status' => 'Programada', 'reason' => $datos['reason'] ?? null,
                    'created_by' => $responsable->id, 'modality' => 'presential', 'parent_appointment_id' => $citaOrigen?->id, 'complimentary' => $esGratuita]);
            }
            if ($pago) {
                $citaActual->fill(['payment_id' => $pago->id, 'complimentary' => false]);
            }
            $citaActual->save();
            $this->consumirBloqueoDemanda($citaActual);
            if ($id) {
                SolicitudReprogramacion::where('appointment_id', $id)->where('status', 'pending')->get()->each->update([
                    'status' => 'accepted', 'response' => $datos['change_reason'], 'resolved_by' => $responsable->id, 'resolved_at' => now()]);
            }
            $this->registrarEvento($citaActual, $responsable, $id ? 'Reprogramación' : 'Creación', ['before' => $antes, 'after' => $citaActual->only(['doctor_id', 'date', 'start_time', 'end_time']),
                'cause' => $datos['cause'] ?? ($citaOrigen ? ($datos['followup_change_cause'] ?? 'patient') : null), 'patient_accepted' => $datos['patient_accepted'] ?? null, 'reason' => $datos['change_reason'] ?? $datos['followup_change_reason'] ?? null]);

            return $citaActual->load('patient', 'doctor', 'specialty', 'events', 'payment');
        }, 3);
    }

    /** Revalida médico, especialidad, paciente, horario y superposiciones dentro de la operación del servidor. */
    public function validarSeleccion(Paciente $paciente, Medico $medico, int $especialidadId, string $fecha, string $inicio, string $modalidad, ?int $excluirId = null, ?int $retencion = null): array
    {
        $especialidad = Especialidad::findOrFail($especialidadId);
        if ($paciente->status !== 'Activo' || $medico->status !== 'Activo' || $especialidad->status !== 'Activo' || ! $medico->specialties()->whereKey($especialidadId)->exists()) {
            $this->rechazar('Paciente, médico y especialidad deben estar activos y vinculados.');
        }
        $franja = collect($this->franjasDisponibles($medico->id, $fecha, $excluirId, $modalidad, $retencion))->firstWhere('start', $inicio);
        if (! $franja || $franja['status'] !== 'Disponible') {
            $this->rechazar('El cupo ya no está disponible para esta modalidad.');
        }
        $bloqueoDemanda = AjusteAgenda::where('doctor_id', $medico->id)->where('date', $fecha)->where('status', 'active')->where('kind', 'presential_block')
            ->where('start_time', '<', $franja['end'])->where('end_time', '>', $franja['start'])->first();
        if ($bloqueoDemanda && ($modalidad !== 'presential' || $bloqueoDemanda->specialty_id !== $especialidadId || $fecha !== today()->toDateString())) {
            $this->rechazar('El bloqueo presencial corresponde a otra especialidad.');
        }
        $filtrarSuperposicion = fn ($consulta) => $consulta->where('patient_id', $paciente->id)->where('date', $fecha)->where('start_time', '<', $franja['end'])->where('end_time', '>', $franja['start']);
        if (Cita::where($filtrarSuperposicion)->whereIn('status', Cita::ACTIVE)->when($excluirId, fn ($consulta) => $consulta->whereKeyNot($excluirId))->exists()
            || SolicitudReserva::where($filtrarSuperposicion)->whereIn('status', ['held', 'processing'])->when($retencion, fn ($consulta) => $consulta->whereKeyNot($retencion))->exists()) {
            $this->rechazar('El paciente tiene una cita o retención que se superpone.');
        }

        return $franja;
    }

    /** Marca como consumido el bloqueo presencial utilizado por una cita real. */
    public function consumirBloqueoDemanda(Cita $citaActual): void
    {
        if ($citaActual->modality !== 'presential') {
            return;
        }
        AjusteAgenda::where('doctor_id', $citaActual->doctor_id)->where('date', $citaActual->date)->where('start_time', $citaActual->start_time)->where('end_time', $citaActual->end_time)
            ->where('specialty_id', $citaActual->specialty_id)->where('kind', 'presential_block')->where('status', 'active')->get()
            ->each->update(['status' => 'consumed', 'appointment_id' => $citaActual->id]);
    }

    /** Aplica una transición de estado válida; conserva el historial y evita operaciones incompatibles. */
    public function cambiarEstado(int $id, string $accion, Usuario $responsable, ?string $motivo = null, array $datos = []): Cita
    {
        return DB::transaction(function () use ($id, $accion, $responsable, $motivo, $datos) {
            $citaActual = Cita::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($responsable->role === 'Médico' && $citaActual->doctor_id !== $responsable->doctor_id) {
                abort(403, 'Solo puede operar su propia agenda.');
            }
            if (! in_array($citaActual->status, Cita::ACTIVE)) {
                $this->rechazar('La cita ya no está vigente.');
            }
            if (in_array($accion, ['attendance', 'absent', 'attend', 'missed-call']) && $citaActual->date !== today()->toDateString()) {
                $this->rechazar('Esta operación solo corresponde a citas de hoy.');
            }
            $antes = $citaActual->status;
            switch ($accion) {
                case 'cancel':
                    validator($datos, ['cause' => 'required|in:patient,clinic'])->validate();
                    $citaActual->fill(['status' => 'Cancelada', 'cancellation_reason' => $motivo, 'cancellation_cause' => $datos['cause'], 'cancelled_at' => now()]);
                    SolicitudReprogramacion::where('appointment_id', $citaActual->id)->where('status', 'pending')->get()->each->update(['status' => 'closed', 'response' => 'Cita cancelada por Recepción.', 'resolved_by' => $responsable->id, 'resolved_at' => now()]);
                    break;
                case 'attendance':
                    $requierePago = $this->requierePago($citaActual);
                    if ($citaActual->attendance_at && ! $requierePago) {
                        $this->rechazar('La asistencia ya fue registrada.');
                    }
                    if ($requierePago) {
                        $pago = app(VerificadorPago::class)->verificarPagoPresencial($datos, app(VerificadorPago::class)->tarifa($citaActual->specialty_id), $responsable);
                        $citaActual->fill(['payment_id' => $pago->id, 'complimentary' => false]);
                    }
                    $citaActual->fill(['status' => 'Confirmada', 'attendance_at' => $citaActual->attendance_at ?? now()]);
                    break;
                case 'missed-call':
                    if ($citaActual->attendance_at) {
                        $this->rechazar('El paciente ya está presente.');
                    }
                    $citaActual->missed_call_at = now();
                    break;
                case 'absent':
                    if ($citaActual->attendance_at) {
                        $this->rechazar('El paciente ya registró asistencia.');
                    }
                    validator($datos, ['definitive' => 'required|accepted', 'reason' => 'required|string|max:3000'])->validate();
                    $citaActual->status = 'Inasistencia';
                    break;
                case 'attend':
                    if ($this->requierePago($citaActual)) {
                        $this->rechazar('Recepción debe verificar el pago antes de continuar la atención.');
                    }
                    if (! $citaActual->attendance_at || $citaActual->status !== 'Confirmada') {
                        $this->rechazar('Debe registrarse la presencia antes de finalizar la consulta.');
                    }
                    validator($datos, ['consultation_finished' => 'required|accepted', 'followup_suggested_at' => 'nullable|date|after:now', 'followup_note' => 'nullable|string|max:3000'])->validate();
                    $citaActual->fill(['status' => 'Atendida', 'attended_at' => now(), 'followup_suggested_at' => $datos['followup_suggested_at'] ?? null, 'followup_note' => $datos['followup_note'] ?? null]);
                    break;
                default: abort(404);
            }
            $citaActual->save();
            $this->registrarEvento($citaActual, $responsable, $accion, ['before' => $antes, 'after' => $citaActual->status, 'reason' => $motivo, 'attendance_at' => $citaActual->attendance_at]);

            return $citaActual->load('patient', 'doctor', 'specialty', 'events', 'payment');
        });
    }

    /** Añade el cambio al historial de la cita con actor y datos permitidos. */
    public function registrarEvento(Cita $citaActual, Usuario $responsable, string $accion, array $detalles): void
    {
        DB::statement('CALL public.registrar_evento_cita(?, ?, ?, ?::jsonb)', [
            $citaActual->id, $responsable->id, $accion, json_encode((object) $detalles, JSON_THROW_ON_ERROR),
        ]);
        $antes = is_array($detalles['before'] ?? null) ? $detalles['before'] : ['status' => $detalles['before'] ?? null];
        $despues = is_array($detalles['after'] ?? null) ? $detalles['after'] : ['status' => $detalles['after'] ?? $citaActual->status];
        foreach (['cause' => 'change_cause', 'patient_accepted' => 'patient_accepted'] as $clave => $campo) {
            if (isset($detalles[$clave])) {
                $despues[$campo] = $detalles[$clave];
            }
        }
        $cambios = [];
        foreach (DetallesAuditoria::atributosPermitidos($despues) as $campo => $valor) {
            $cambios[$campo] = ['before' => $antes[$campo] ?? null, 'after' => $valor];
        }
        app(RegistroAuditoria::class)->registrar('appointments', match ($accion) {
            'Creación' => 'booked', 'Reprogramación' => 'rescheduled', default => $accion,
        }, $citaActual, detalles: ['reason' => $detalles['reason'] ?? null, 'fields' => array_keys($cambios), 'changes' => $cambios], responsableId: $responsable->id);
    }

    /** Comprueba si el pago confirmado o la exención vigente permiten continuar la atención. */
    public function requierePago(Cita $citaActual): bool
    {
        if ($citaActual->complimentary) {
            $llegada = $citaActual->attendance_at ? CarbonImmutable::parse($citaActual->attendance_at) : now();

            return $citaActual->missed_call_at !== null || $llegada->gt(CarbonImmutable::parse($citaActual->date.' '.$citaActual->start_time));
        }

        return $citaActual->payment?->status !== 'confirmed';
    }

    /** Interrumpe la operación con un error de validación comprensible. */
    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['appointment' => $mensaje]);
    }
}
