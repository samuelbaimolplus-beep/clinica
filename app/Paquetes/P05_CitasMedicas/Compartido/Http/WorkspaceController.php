<?php

/** Resumen real de trabajo: filtra pacientes y médicos por identidad y muestra tareas pendientes por rol. */

namespace App\Paquetes\P05_CitasMedicas\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\RefundRequest;
use App\Models\RescheduleRequest;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use Illuminate\Http\Request;

/** Resumen de trabajo con el mismo alcance de identidad que la agenda; nunca es un padrón público. */
class WorkspaceController extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $request, AppointmentService $service)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['Superadmin', 'Administrador', 'Recepcionista', 'Médico', 'Paciente'], true), 403);
        $query = Appointment::query();
        if ($user->role === 'Paciente') {
            $query->where('patient_id', $user->patient_id);
        } elseif ($user->role === 'Médico') {
            $query->where('doctor_id', $user->doctor_id);
        }
        $today = today()->toDateString();
        $daily = (clone $query)->where('date', $today);
        $next = (clone $query)->where('date', '>=', $today)->whereIn('status', Appointment::ACTIVE)
            ->with('patient:id,first_name,last_name', 'doctor:id,first_name,last_name', 'specialty:id,name', 'payment')
            ->orderBy('date')->orderBy('start_time')->orderBy('id')->limit(6)->get();
        $records = $next->map(fn ($a) => [
            'id' => $a->id, 'date' => $a->date, 'start_time' => $a->start_time, 'end_time' => $a->end_time,
            'status' => $a->status, 'attendance_at' => $a->attendance_at,
            'patient' => $a->patient?->only(['id', 'first_name', 'last_name']),
            'doctor' => $a->doctor?->only(['id', 'first_name', 'last_name']),
            'specialty' => $a->specialty?->only(['id', 'name']),
            'requires_arrival_payment' => $service->needsPayment($a),
        ]);
        $staff = $user->hasStaffRole('Administrador', 'Recepcionista');
        $setup = $user->hasStaffRole('Administrador') ? [
            'specialties' => Specialty::where('status', 'Activo')->count(),
            'doctors' => Doctor::where('status', 'Activo')->count(),
            'scheduled_doctors' => Schedule::whereIn('doctor_id', Doctor::where('status', 'Activo')->select('id'))->distinct()->count('doctor_id'),
        ] : null;

        return response()->json([
            'date' => $today,
            'today' => [
                'total' => (clone $daily)->count(),
                'expected' => (clone $daily)->whereIn('status', Appointment::ACTIVE)->whereNull('attendance_at')->count(),
                'waiting' => (clone $daily)->where('status', 'Confirmada')->whereNotNull('attendance_at')->count(),
                'attended' => (clone $daily)->where('status', 'Atendida')->count(),
            ],
            'next' => $records,
            'requests' => $user->hasStaffRole('Recepcionista') ? RescheduleRequest::where('status', 'pending')->count() : null,
            'refunds' => $user->hasStaffRole('Administrador') ? RefundRequest::whereIn('status', ['pending', 'approved'])->count() : null,
            'patients' => $staff ? Patient::count() : null,
            'setup' => $setup,
        ])->header('Cache-Control', 'private, no-store');
    }
}
