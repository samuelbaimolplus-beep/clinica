<?php

/** Entrada HTTP de MedicalAvailabilityController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU08_ConsultarDisponibilidadMedica\Http;

use App\Http\Controllers\Controller;
use App\Models\AgendaAdjustment;
use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\DoctorQuotaService;
use App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Integraciones\PayPalGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CU08 consulta disponibilidad; los coordinadores revalidan antes de formalizar. */
class MedicalAvailabilityController extends Controller
{
    /** Comprueba el rol requerido antes de leer o modificar datos del módulo. */
    private function autorizarRol(Request $solicitud): void
    {
        abort_unless(in_array($solicitud->user()->role, ['Superadmin', 'Recepcionista', 'Paciente'], true), 403);
    }

    /** Devuelve opciones activas y configuración pública necesaria para seleccionar una atención. */
    public function opciones(Request $solicitud)
    {
        $this->autorizarRol($solicitud);

        return ['online_payment_available' => app(PayPalGateway::class)->estaConfigurada(),
            'payment_provider' => 'paypal', 'payment_currency' => 'USD', 'bob_per_usd' => config('paypal.bob_per_usd'),
            'specialties' => Specialty::where('status', 'Activo')->orderBy('name')->get(['id', 'name', 'fee_minor']),
            'doctors' => Doctor::where('status', 'Activo')->whereHas('specialties', fn ($consulta) => $consulta->where('status', 'Activo'))
                ->with(['specialties' => fn ($consulta) => $consulta->where('status', 'Activo')->select('specialties.id', 'name')])
                ->orderBy('last_name')->get(['id', 'first_name', 'last_name'])];
    }

    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function listar(Request $solicitud, DoctorQuotaService $servicio)
    {
        $this->autorizarRol($solicitud);
        $datos = $solicitud->validate([
            'doctor_id' => 'nullable|integer|exists:doctors,id',
            'include_demand' => 'sometimes|boolean',
            'specialty_id' => 'required|integer|exists:specialties,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'modality' => ['sometimes', Rule::in($solicitud->user()->role === 'Superadmin' ? ['online', 'presential'] : [$solicitud->user()->role === 'Paciente' ? 'online' : 'presential'])],
        ]);
        $modalidad = $solicitud->user()->role === 'Superadmin' ? ($datos['modality'] ?? 'presential') : ($solicitud->user()->role === 'Paciente' ? 'online' : 'presential');
        $medicos = Doctor::where('status', 'Activo')->when($datos['doctor_id'] ?? null, fn ($consulta, $id) => $consulta->whereKey($id))
            ->whereHas('specialties', fn ($consulta) => $consulta->whereKey($datos['specialty_id'])->where('status', 'Activo'))->get();
        $ordenadosPorCarga = [];
        foreach ($medicos as $medico) {
            $citas = Appointment::where('doctor_id', $medico->id)->where('date', $datos['date'])->whereIn('status', Appointment::ACTIVE)->get(['start_time', 'end_time']);
            $capacidad = $servicio->calcularCapacidad($medico->id, $datos['date']);
            $retenciones = BookingRequest::where('doctor_id', $medico->id)->where('date', $datos['date'])->whereIn('status', ['held', 'processing'])->get();
            $bloqueosDemanda = AgendaAdjustment::where('doctor_id', $medico->id)->where('date', $datos['date'])->where('kind', 'presential_block')->where('status', 'active')->get();
            $bloques = array_values(array_filter($capacidad['blocks'], fn ($bloque) => $bloque['modality'] === $modalidad
                && (! $bloque['demand_blocked'] || (! empty($datos['include_demand']) && $modalidad === 'presential' && $bloqueosDemanda->contains(fn ($criterios) => $criterios->specialty_id === (int) $datos['specialty_id'] && substr($criterios->start_time, 0, 5) === $bloque['start_time'])))
                && ! $retenciones->contains(fn ($retencion) => $retencion->start_time < $bloque['end_time'].':00' && $retencion->end_time > $bloque['start_time'].':00')
                && CarbonImmutable::parse($datos['date'].' '.$bloque['start_time']) >= now()
                && ! $citas->contains(fn ($cita) => $cita->start_time < $bloque['end_time'].':00' && $cita->end_time > $bloque['start_time'].':00')));
            if ($bloques) {
                $ordenadosPorCarga[] = ['doctor_id' => $medico->id, 'doctor_name' => $medico->first_name.' '.$medico->last_name,
                    'load' => $citas->count(), 'blocks' => $bloques];
            }
        }
        usort($ordenadosPorCarga, fn ($citaActual, $elemento) => [$citaActual['load'], -count($citaActual['blocks']), $citaActual['doctor_id']] <=> [$elemento['load'], -count($elemento['blocks']), $elemento['doctor_id']]);
        $bloques = [];
        foreach ($ordenadosPorCarga as $medico) {
            foreach ($medico['blocks'] as $bloque) {
                $bloques[] = $bloque + ['doctor_id' => $medico['doctor_id'], 'doctor_name' => $medico['doctor_name']];
            }
        }

        $mensaje = null;
        if (! $bloques) {
            $mensaje = $medicos->isEmpty() ? 'No hay médicos activos habilitados para la especialidad seleccionada.'
                : (! Schedule::whereIn('doctor_id', $medicos->pluck('id'))->where('weekday', CarbonImmutable::parse($datos['date'])->dayOfWeek)->exists()
                    ? 'Los médicos seleccionados no tienen atención programada para esa fecha.'
                    : 'No hay cupos libres para esta modalidad. Consulte otra fecha o profesional.');
        }

        return ['modality' => $modalidad, 'blocks' => $bloques, 'message' => $mensaje];
    }
}
