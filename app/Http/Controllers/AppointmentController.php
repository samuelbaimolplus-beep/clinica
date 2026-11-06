<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Unavailability;
use App\Services\AppointmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate(['mode' => ['required', Rule::in(['agenda', 'history', 'affected', 'active', 'today', 'attend'])], 'date' => 'nullable|date_format:Y-m-d', 'doctor_id' => 'nullable|integer|exists:doctors,id', 'status' => ['nullable', Rule::in(Appointment::STATES)], 'q' => 'nullable|string|max:150']);
        $role = $r->user()->role;
        $allowed = match ($role) {
            'Administrador' => ['history'], 'Recepcionista' => ['agenda', 'history', 'affected', 'active', 'today'], 'Médico' => ['agenda', 'attend'], default => []
        };
        abort_unless(in_array($d['mode'], $allowed), 403);
        $q = Appointment::with('patient', 'doctor', 'specialty', 'events')->orderBy('date')->orderBy('start_time')->orderBy('id');
        if ($role === 'Médico') {
            abort_unless($r->user()->doctor_id, 403);
            $q->where('doctor_id', $r->user()->doctor_id);
        } elseif (! empty($d['doctor_id'])) {
            $q->where('doctor_id', $d['doctor_id']);
        }
        if (in_array($d['mode'], ['today', 'attend'])) {
            $q->where('date', today()->toDateString());
        } elseif (! empty($d['date'])) {
            $q->where('date', $d['date']);
        }
        if (in_array($d['mode'], ['active', 'affected'])) {
            $q->whereIn('status', Appointment::ACTIVE);
        }
        if (! empty($d['status'])) {
            $q->where('status', $d['status']);
        }
        if ($d['mode'] === 'affected') {
            $q->whereExists(fn ($b) => $b->selectRaw('1')->from('unavailabilities')->whereColumn('unavailabilities.doctor_id', 'appointments.doctor_id')->whereRaw('(appointments.date + appointments.start_time) < unavailabilities.ends_at AND (appointments.date + appointments.end_time) > unavailabilities.starts_at'));
        }
        if (! empty($d['q'])) {
            $text = '%'.$d['q'].'%';
            $q->where(fn ($b) => $b->whereRaw("('CIT-' || lpad(id::text, 4, '0')) ILIKE ?", [$text])->orWhereHas('patient', fn ($p) => $p->whereRaw("(first_name || ' ' || last_name || ' ' || identity) ILIKE ?", [$text]))->orWhereHas('doctor', fn ($p) => $p->whereRaw("(first_name || ' ' || last_name) ILIKE ?", [$text])));
        }
        $rows = $q->get();
        if ($d['mode'] === 'affected') {
            $blocks = Unavailability::all();
            foreach ($rows as $a) {
                $a->setAttribute('blocks', $blocks->filter(fn ($b) => $b->doctor_id === $a->doctor_id && $a->date.' '.$a->start_time < $b->ends_at && $a->date.' '.$a->end_time > $b->starts_at)->values());
            }
        }

        return $rows;
    }

    public function store(Request $r, AppointmentService $service)
    {
        abort_unless($r->user()->role === 'Recepcionista', 403);
        $d = $r->validate(['patient_id' => 'required|integer|exists:patients,id', 'doctor_id' => 'required|integer|exists:doctors,id', 'specialty_id' => 'required|integer|exists:specialties,id', 'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'start_time' => 'required|date_format:H:i', 'reason' => 'nullable|string|max:3000']);

        return response()->json($service->book($d, $r->user()), 201);
    }

    public function reschedule(Request $r, int $id, AppointmentService $service)
    {
        abort_unless($r->user()->role === 'Recepcionista', 403);
        $d = $r->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'start_time' => 'required|date_format:H:i']);

        return $service->book($d, $r->user(), $id);
    }

    public function action(Request $r, int $id, string $action, AppointmentService $service)
    {
        abort_unless(in_array($action, ['cancel', 'confirm', 'attendance', 'absent', 'attend']), 404);
        abort_unless($r->user()->role === ($action === 'attend' ? 'Médico' : 'Recepcionista'), 403);
        $d = $r->validate(['reason' => [$action === 'cancel' ? 'required' : 'nullable', 'string', 'max:3000']]);

        return $service->transition($id, $action, $r->user(), $d['reason'] ?? null);
    }
}
