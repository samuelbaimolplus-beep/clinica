<?php

/** Consultas de agenda e historial con filtros de identidad y datos limitados para el médico. */

namespace App\Paquetes\P05_CitasMedicas\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Unavailability;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentQueryController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function listar(Request $peticion)
    {
        $criterios = $peticion->validate(['mode' => ['required', Rule::in(['agenda', 'history', 'affected', 'active', 'today', 'attend'])], 'date' => 'nullable|date_format:Y-m-d', 'doctor_id' => 'nullable|integer|exists:doctors,id', 'status' => ['nullable', Rule::in(Appointment::STATES)], 'q' => 'nullable|string|max:150']);
        $rol = $peticion->user()->role;
        $filtroPaciente = $peticion->validate(['patient_id' => 'nullable|integer|exists:patients,id']);
        $permitidos = match ($rol) {
            'Superadmin' => ['agenda', 'history', 'affected', 'active', 'today', 'attend'], 'Administrador' => ['history'], 'Recepcionista' => ['agenda', 'history', 'affected', 'active', 'today'], 'Médico' => ['agenda', 'attend'], 'Paciente' => ['history', 'active'], default => []
        };
        abort_unless(in_array($criterios['mode'], $permitidos), 403);
        $relaciones = $rol === 'Médico'
            ? ['patient:id,first_name,last_name', 'doctor:id,first_name,last_name', 'specialty:id,name', 'events']
            : ['patient', 'doctor', 'specialty', 'events', 'payment'];
        $consulta = Appointment::with($relaciones)->orderBy('date')->orderBy('start_time')->orderBy('id');
        if ($rol === 'Paciente') {
            abort_if(! empty($filtroPaciente['patient_id']) && (int) $filtroPaciente['patient_id'] !== $peticion->user()->patient_id, 403);
            $consulta->where('patient_id', $peticion->user()->patient_id);
        }
        if (! empty($filtroPaciente['patient_id'])) {
            $consulta->where('patient_id', $filtroPaciente['patient_id']);
        }
        if ($rol === 'Médico') {
            abort_unless($peticion->user()->doctor_id, 403);
            abort_if(! empty($criterios['doctor_id']) && (int) $criterios['doctor_id'] !== $peticion->user()->doctor_id, 403, 'Solo puede consultar su propia agenda.');
            $consulta->where('doctor_id', $peticion->user()->doctor_id);
        } elseif (! empty($criterios['doctor_id'])) {
            $consulta->where('doctor_id', $criterios['doctor_id']);
        }
        if (in_array($criterios['mode'], ['today', 'attend'])) {
            $consulta->where('date', today()->toDateString());
        } elseif (! empty($criterios['date'])) {
            $consulta->where('date', $criterios['date']);
        }
        if (in_array($criterios['mode'], ['active', 'affected'])) {
            $consulta->whereIn('status', Appointment::ACTIVE);
        }
        if (! empty($criterios['status'])) {
            $consulta->where('status', $criterios['status']);
        }
        if ($criterios['mode'] === 'affected') {
            $consulta->whereExists(fn ($elemento) => $elemento->selectRaw('1')->from('unavailabilities')->whereNull('unavailabilities.cancelled_at')->whereColumn('unavailabilities.doctor_id', 'appointments.doctor_id')->whereRaw('(appointments.date + appointments.start_time) < unavailabilities.ends_at AND (appointments.date + appointments.end_time) > unavailabilities.starts_at'));
        }
        if (! empty($criterios['q'])) {
            $texto = '%'.$criterios['q'].'%';
            $consulta->where(fn ($elemento) => $elemento->whereRaw("('CIT-' || lpad(id::text, 4, '0')) ILIKE ?", [$texto])->orWhereHas('patient', fn ($elementoActual) => $elementoActual->whereRaw("(first_name || ' ' || last_name || ' ' || coalesce(identity, '')) ILIKE ?", [$texto]))->orWhereHas('doctor', fn ($elementoActual) => $elementoActual->whereRaw("(first_name || ' ' || last_name) ILIKE ?", [$texto])));
        }
        $filas = $consulta->get();
        foreach ($filas as $citaActual) {
            $citaActual->setAttribute('care_ready', $citaActual->status === 'Confirmada' && $citaActual->attendance_at && ! app(AppointmentService::class)->requierePago($citaActual));
            if ($rol !== 'Médico') {
                $citaActual->setAttribute('requires_arrival_payment', app(AppointmentService::class)->requierePago($citaActual));
            }
            $citaActual->setAttribute('arrival_late', $citaActual->attendance_at && ($citaActual->missed_call_at || CarbonImmutable::parse($citaActual->attendance_at)->gt(CarbonImmutable::parse($citaActual->date.' '.$citaActual->start_time))));
        }
        if ($criterios['mode'] === 'attend') {
            // Los presentes puntuales conservan su turno; los retrasados se ordenan por llegada.
            $filas = $filas->sortBy(fn ($citaActual) => [$citaActual->attendance_at ? ($citaActual->arrival_late ? 1 : 0) : 2,
                $citaActual->arrival_late ? $citaActual->attendance_at : $citaActual->start_time, $citaActual->id])->values();
        }
        if ($criterios['mode'] === 'affected') {
            $bloques = Unavailability::active()->get();
            foreach ($filas as $citaActual) {
                $citaActual->setAttribute('blocks', $bloques->filter(fn ($elemento) => $elemento->doctor_id === $citaActual->doctor_id && $citaActual->date.' '.$citaActual->start_time < $elemento->ends_at && $citaActual->date.' '.$citaActual->end_time > $elemento->starts_at)->values());
            }
        }
        if ($rol === 'Médico') {
            $filas->each(fn (Appointment $cita) => $this->limitarDatosMedico($cita));
        }

        return $filas;
    }

    /** Retira datos de contacto y de pago que no necesita la atención médica. */
    private function limitarDatosMedico(Appointment $cita): void
    {
        $cita->unsetRelation('payment');
        $cita->makeHidden(['payment_id', 'booking_request_id', 'created_by', 'clinical_slot_id', 'modality']);
        $cita->patient?->setVisible(['id', 'first_name', 'last_name']);
        $cita->doctor?->setVisible(['id', 'first_name', 'last_name']);
        $cita->specialty?->setVisible(['id', 'name']);
        $cita->events?->each(fn ($evento) => $evento->setVisible(['id', 'appointment_id', 'action', 'details', 'created_at']));
    }
}
