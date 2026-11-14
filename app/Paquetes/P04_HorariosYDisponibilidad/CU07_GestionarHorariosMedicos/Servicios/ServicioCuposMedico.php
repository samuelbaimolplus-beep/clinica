<?php

/** Guarda distribución de cupos y calcula capacidad efectiva según horarios, ausencias y ajustes. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios;

use App\Models\AjusteAgenda;
use App\Models\AusenciaMedica;
use App\Models\CupoMedico;
use App\Models\Medico;
use App\Models\SolicitudReserva;
use App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios\ModalidadesTurno;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioCuposMedico
{
    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function guardar(array $datos): CupoMedico
    {
        if ($this->puntosBase($datos['presential_percent']) + $this->puntosBase($datos['online_percent']) !== 10000) {
            throw ValidationException::withMessages(['presential_percent' => 'Los porcentajes presenciales y online deben sumar exactamente 100%.']);
        }

        return DB::transaction(function () use ($datos) {
            $medico = Medico::whereKey($datos['doctor_id'])->lockForUpdate()->firstOrFail();
            if (SolicitudReserva::where('doctor_id', $medico->id)->whereIn('status', ['held', 'processing'])->exists()) {
                throw ValidationException::withMessages(['quota' => 'Concilie los pagos online retenidos antes de cambiar la distribución.']);
            }
            if ($medico->status !== 'Activo') {
                throw ValidationException::withMessages(['doctor_id' => 'El médico debe estar activo.']);
            }

            return CupoMedico::updateOrCreate(['doctor_id' => $medico->id], $datos);
        });
    }

    /** Calcula cupos y franjas utilizables según turnos, modalidades, ausencias y ajustes. */
    public function calcularCapacidad(int $medicoId, string $fecha): array
    {
        $intervalos = app(ModalidadesTurno::class)->franjasParaFecha($medicoId, $fecha);
        $dia = CarbonImmutable::parse($fecha);
        $bloques = AusenciaMedica::active()->where('doctor_id', $medicoId)->where('starts_at', '<', $dia->addDay())->where('ends_at', '>', $dia)->get();
        $utilizables = array_filter($intervalos, fn ($intervalo) => ! $bloques->contains(fn ($bloque) => CarbonImmutable::parse($bloque->starts_at) < $intervalo['end'] && CarbonImmutable::parse($bloque->ends_at) > $intervalo['start']));
        $ajustes = AjusteAgenda::where('doctor_id', $medicoId)->where('date', $fecha)->where('status', 'active')
            ->when($fecha !== today()->toDateString(), fn ($consultaDatos) => $consultaDatos->where('kind', 'online_opening'))->get();
        $utilizables = array_map(function ($bloque) use ($ajustes) {
            $ajuste = $ajustes->first(fn ($elementoLista) => $elementoLista->start_time === $bloque['start']->format('H:i:s') && $elementoLista->end_time === $bloque['end']->format('H:i:s'));
            $bloque['demand_blocked'] = $ajuste?->kind === 'presential_block';
            $bloque['early_online'] = $ajuste?->kind === 'online_opening';
            if ($ajuste) {
                $bloque['modality'] = $bloque['early_online'] ? 'online' : 'presential';
            }

            return $bloque;
        }, $utilizables);
        $total = count($utilizables);
        $distribucion = CupoMedico::where('doctor_id', $medicoId)->first();
        $presencial = count(array_filter($utilizables, fn ($bloque) => $bloque['modality'] === 'presential'));
        $enLinea = $total - $presencial;

        return ['date' => $fecha, 'configured_blocks' => count($intervalos), 'blocked_blocks' => count($intervalos) - $total,
            'total_capacity' => $total, 'quota' => $distribucion ?? ['presential_percent' => 50, 'online_percent' => 50],
            'uses_default_quota' => $distribucion === null, 'protection_minutes' => 120,
            'protected_capacity' => count(array_filter($utilizables, fn ($bloque) => $bloque['protected'])),
            'presential_capacity' => $presencial, 'online_capacity' => $enLinea,
            'rounding_policy' => 'largest_remainder_presential_tie',
            'blocks' => array_map(fn ($bloque) => ['schedule_id' => $bloque['schedule_id'],
                'start_time' => $bloque['start']->format('H:i'), 'end_time' => $bloque['end']->format('H:i'),
                'protected' => $bloque['protected'], 'modality' => $bloque['modality'],
                'demand_blocked' => $bloque['demand_blocked'], 'early_online' => $bloque['early_online']], array_values($utilizables))];
    }

    /** Representa porcentajes sin depender de redondeos binarios de coma flotante. */
    private function puntosBase(string|int|float $valor): int
    {
        [$entero, $fraccion] = array_pad(explode('.', (string) $valor, 2), 2, '');

        return (int) $entero * 100 + (int) str_pad($fraccion, 2, '0');
    }
}
