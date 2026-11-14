<?php

/** Resumen real de trabajo: filtra pacientes y médicos por identidad y muestra tareas pendientes por rol. */

namespace App\Paquetes\P05_CitasMedicas\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Horario;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\SolicitudReembolso;
use App\Models\SolicitudReprogramacion;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\ServicioCitas;
use Illuminate\Http\Request;

/** Resumen de trabajo con el mismo alcance de identidad que la agenda; nunca es un padrón público. */
class ControladorPanelInicio extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $solicitud, ServicioCitas $servicio)
    {
        $usuario = $solicitud->user();
        abort_unless(in_array($usuario->role, ['Superadmin', 'Administrador', 'Recepcionista', 'Médico', 'Paciente'], true), 403);
        $consultaDatos = Cita::query();
        if ($usuario->role === 'Paciente') {
            $consultaDatos->where('patient_id', $usuario->patient_id);
        } elseif ($usuario->role === 'Médico') {
            $consultaDatos->where('doctor_id', $usuario->doctor_id);
        }
        $hoy = today()->toDateString();
        $citasDelDia = (clone $consultaDatos)->where('date', $hoy);
        $siguiente = (clone $consultaDatos)->where('date', '>=', $hoy)->whereIn('status', Cita::ACTIVE)
            ->with('patient:id,first_name,last_name', 'doctor:id,first_name,last_name', 'specialty:id,name', 'payment')
            ->orderBy('date')->orderBy('start_time')->orderBy('id')->limit(6)->get();
        $registros = $siguiente->map(fn ($citaActual) => [
            'id' => $citaActual->id, 'date' => $citaActual->date, 'start_time' => $citaActual->start_time, 'end_time' => $citaActual->end_time,
            'status' => $citaActual->status, 'attendance_at' => $citaActual->attendance_at,
            'patient' => $citaActual->patient?->only(['id', 'first_name', 'last_name']),
            'doctor' => $citaActual->doctor?->only(['id', 'first_name', 'last_name']),
            'specialty' => $citaActual->specialty?->only(['id', 'name']),
            'requires_arrival_payment' => $servicio->requierePago($citaActual),
        ]);
        $esPersonal = $usuario->tieneRolPersonal('Administrador', 'Recepcionista');
        $preparacion = $usuario->tieneRolPersonal('Administrador') ? [
            'specialties' => Especialidad::where('status', 'Activo')->count(),
            'doctors' => Medico::where('status', 'Activo')->count(),
            'scheduled_doctors' => Horario::whereIn('doctor_id', Medico::where('status', 'Activo')->select('id'))->distinct()->count('doctor_id'),
        ] : null;

        return response()->json([
            'date' => $hoy,
            'today' => [
                'total' => (clone $citasDelDia)->count(),
                'expected' => (clone $citasDelDia)->whereIn('status', Cita::ACTIVE)->whereNull('attendance_at')->count(),
                'waiting' => (clone $citasDelDia)->where('status', 'Confirmada')->whereNotNull('attendance_at')->count(),
                'attended' => (clone $citasDelDia)->where('status', 'Atendida')->count(),
            ],
            'next' => $registros,
            'requests' => $usuario->tieneRolPersonal('Recepcionista') ? SolicitudReprogramacion::where('status', 'pending')->count() : null,
            'refunds' => $usuario->tieneRolPersonal('Administrador') ? SolicitudReembolso::whereIn('status', ['pending', 'approved'])->count() : null,
            'patients' => $esPersonal ? Paciente::count() : null,
            'setup' => $preparacion,
        ])->header('Cache-Control', 'private, no-store');
    }
}
