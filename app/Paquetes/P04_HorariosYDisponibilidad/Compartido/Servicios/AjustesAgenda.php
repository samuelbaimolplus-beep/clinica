<?php

/** Propone, confirma y libera protección de demanda; distingue reservas online y bloqueos presenciales. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios;

use App\Models\AjusteAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\SolicitudReserva;
use App\Models\Usuario;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\ServicioCuposMedico;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** RN35 y RN37: movimientos operativos de Recepción, sin pacientes ni cobros. */
class AjustesAgenda
{
    /** Selecciona únicamente franjas que pueden utilizarse sin conflictos. */
    public function franjasLibres(int $medicoId, string $fecha): array
    {
        $capacidad = app(ServicioCuposMedico::class)->calcularCapacidad($medicoId, $fecha);
        $ocupadas = Cita::where('doctor_id', $medicoId)->where('date', $fecha)->whereIn('status', Cita::ACTIVE)->get();
        $retenciones = SolicitudReserva::where('doctor_id', $medicoId)->where('date', $fecha)->whereIn('status', ['held', 'processing'])->get();

        return array_values(array_filter($capacidad['blocks'], fn ($bloque) => ! $bloque['demand_blocked']
            && ! $retenciones->contains(fn ($retencion) => $retencion->start_time < $bloque['end_time'].':00' && $retencion->end_time > $bloque['start_time'].':00')
            && CarbonImmutable::parse($fecha.' '.$bloque['start_time']) >= now()
            && ! $ocupadas->contains(fn ($cita) => $cita->start_time < $bloque['end_time'].':00' && $cita->end_time > $bloque['start_time'].':00')));
    }

    /** Calcula una propuesta de franjas; todavía no confirma bloqueos ni crea citas. */
    public function proponer(int $especialidadId, string $fecha, int $cantidad): array
    {
        $this->exigirJornadaActual($fecha);
        $candidatas = [];
        if (Especialidad::whereKey($especialidadId)->where('status', 'Activo')->exists()) {
            $medicos = Medico::where('status', 'Activo')->whereHas('specialties', fn ($consulta) => $consulta->whereKey($especialidadId))->orderBy('id')->get();
            foreach ($medicos as $medico) {
                foreach ($this->franjasLibres($medico->id, $fecha) as $bloque) {
                    if ($bloque['modality'] === 'presential') {
                        $candidatas[] = ['doctor_id' => $medico->id, 'date' => $fecha,
                            'start_time' => $bloque['start_time'], 'end_time' => $bloque['end_time']];
                    }
                }
            }
        }
        usort($candidatas, fn ($citaActual, $elemento) => [$citaActual['start_time'], $citaActual['doctor_id']] <=> [$elemento['start_time'], $elemento['doctor_id']]);
        $seleccionados = array_slice($candidatas, 0, $cantidad);

        return ['blocks' => $seleccionados, 'requested' => $cantidad, 'unfulfilled' => $cantidad - count($seleccionados)];
    }

    /** Confirma las franjas propuestas como bloqueos presenciales o aperturas online, revalidando su disponibilidad. */
    public function crear(string $tipo, ?int $especialidadId, array $bloques, Usuario $responsable, ?int $cantidad = null): array
    {
        return DB::transaction(function () use ($tipo, $especialidadId, $bloques, $responsable, $cantidad) {
            $lote = $tipo === 'presential_block' ? (string) Str::uuid() : null;
            $medicos = Medico::whereIn('id', array_column($bloques, 'doctor_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($tipo === 'presential_block' && ! Especialidad::whereKey($especialidadId)->where('status', 'Activo')->exists()) {
                $this->rechazar('Seleccione una especialidad activa.');
            }
            $creados = [];
            foreach ($bloques as $datos) {
                if ($tipo === 'presential_block') {
                    $this->exigirJornadaActual($datos['date']);
                }
                $medico = $medicos->get($datos['doctor_id']);
                if (! $medico || $medico->status !== 'Activo' || ($especialidadId && ! $medico->specialties()->whereKey($especialidadId)->exists())) {
                    $this->rechazar('El médico ya no está habilitado para esta operación.');
                }
                $bloque = collect($this->franjasLibres($medico->id, $datos['date']))->first(fn ($elementoLista) => $elementoLista['start_time'] === $datos['start_time'] && $elementoLista['end_time'] === $datos['end_time']);
                if (! $bloque || $bloque['modality'] !== 'presential'
                    || ($tipo === 'online_opening' && ! $bloque['protected'])) {
                    $this->rechazar('Una franja ya no está libre o no admite la operación. Consulte nuevamente.');
                }
                $creados[] = AjusteAgenda::create($datos + ['specialty_id' => $especialidadId, 'kind' => $tipo, 'created_by' => $responsable->id,
                    'demand_batch' => $lote, 'demand_quantity' => $lote ? ($cantidad ?? count($bloques)) : null]);
            }

            return $creados;
        });
    }

    /** Libera un bloqueo autorizado conservando el registro de la operación. */
    public function liberar(int $id, Usuario $responsable): AjusteAgenda
    {
        return DB::transaction(function () use ($id, $responsable) {
            $registro = AjusteAgenda::findOrFail($id);
            Medico::whereKey($registro->doctor_id)->lockForUpdate()->firstOrFail();
            $registro = AjusteAgenda::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($registro->kind !== 'presential_block') {
                $this->rechazar('CU25 no permite retirar una habilitación online.');
            }
            $this->exigirJornadaActual($registro->date);
            if ($registro->status !== 'active') {
                $this->rechazar('El movimiento ya no está activo. Actualice la consulta.');
            }
            if (Cita::where('doctor_id', $registro->doctor_id)->where('date', $registro->date)
                ->whereIn('status', Cita::ACTIVE)->where('start_time', '<', $registro->end_time)->where('end_time', '>', $registro->start_time)->exists()) {
                $this->rechazar('El bloqueo está asociado a una cita vigente y no puede liberarse.');
            }
            $registro->update(['status' => 'released', 'released_by' => $responsable->id, 'released_at' => now()]);

            return $registro;
        });
    }

    /** Libera los bloqueos seleccionados después de validar sus estados y permisos. */
    public function liberarSeleccionados(array $ids, Usuario $responsable): array
    {
        return DB::transaction(function () use ($ids, $responsable) {
            $registros = AjusteAgenda::whereIn('id', $ids)->get();
            if ($registros->count() !== count($ids)) {
                $this->rechazar('Uno de los bloqueos ya no existe. Actualice la consulta.');
            }
            Medico::whereIn('id', $registros->pluck('doctor_id'))->orderBy('id')->lockForUpdate()->get();

            return array_map(fn ($id) => $this->liberar($id, $responsable), $ids);
        });
    }

    /** Interrumpe la operación con un error de validación comprensible. */
    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['blocks' => $mensaje]);
    }

    /** Rechaza los ajustes de demanda que no corresponden a la jornada actual. */
    private function exigirJornadaActual(string $fecha): void
    {
        if ($fecha !== today()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'CU24 permite gestionar bloqueos únicamente durante la jornada actual.']);
        }
    }
}
