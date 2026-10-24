<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Schedule;
use App\Models\Unavailability;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    public function availability(Request $r, AppointmentService $service)
    {
        abort_unless($r->user()->role === 'Recepcionista', 403);
        $d = $r->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d', 'exclude' => 'nullable|integer|exists:appointments,id']);

        return $service->slots($d['doctor_id'], $d['date'], $d['exclude'] ?? null);
    }

    public function index(Request $r)
    {
        abort_unless($r->user()->role === 'Administrador', 403);
        $d = $r->validate(['doctor_id' => 'required|integer|exists:doctors,id']);

        return ['schedules' => Schedule::where('doctor_id', $d['doctor_id'])->orderBy('weekday')->get(), 'unavailabilities' => Unavailability::where('doctor_id', $d['doctor_id'])->orderByDesc('starts_at')->get()];
    }

    public function save(Request $r)
    {
        abort_unless($r->user()->role === 'Administrador', 403);
        $d = $r->validate(['doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->where('status', 'Activo')], 'weekday' => 'required|integer|between:0,6', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time', 'duration' => 'required|integer|between:5,240']);
        if (CarbonImmutable::parse($d['start_time'])->diffInMinutes(CarbonImmutable::parse($d['end_time'])) < $d['duration']) {
            throw ValidationException::withMessages(['duration' => 'La duración no puede superar el intervalo de atención.']);
        }

        return DB::transaction(function () use ($d) {
            Doctor::whereKey($d['doctor_id'])->lockForUpdate()->firstOrFail();
            $future = Appointment::where('doctor_id', $d['doctor_id'])->where('date', '>=', today()->toDateString())->whereIn('status', Appointment::ACTIVE)->whereRaw('EXTRACT(DOW FROM date) = ?', [$d['weekday']])->get();
            foreach ($future as $a) {
                $offset = CarbonImmutable::parse($d['start_time'])->diffInMinutes(CarbonImmutable::parse($a->start_time), false);
                $duration = CarbonImmutable::parse($a->start_time)->diffInMinutes(CarbonImmutable::parse($a->end_time));
                if ($a->start_time < $d['start_time'].':00' || $a->end_time > $d['end_time'].':00' || $duration !== (float) $d['duration'] || $offset < 0 || (int) $offset % (int) $d['duration'] !== 0) {
                    throw ValidationException::withMessages(['schedule' => 'El cambio afecta citas vigentes. Reprográmelas o cancélelas antes de modificar este horario.']);
                }
            }

            return Schedule::updateOrCreate(['doctor_id' => $d['doctor_id'], 'weekday' => $d['weekday']], $d);
        });
    }

    public function unavailable(Request $r, bool $preview = false)
    {
        abort_unless($r->user()->role === 'Administrador', 403);
        $d = $r->validate(['doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->where('status', 'Activo')], 'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i|after:starts_at', 'reason' => 'required|string|max:300']);

        return DB::transaction(function () use ($d, $preview) {
            Doctor::whereKey($d['doctor_id'])->lockForUpdate()->firstOrFail();
            $affected = Appointment::with('patient', 'doctor', 'specialty')->where('doctor_id', $d['doctor_id'])->whereIn('status', Appointment::ACTIVE)->whereRaw('(date + start_time) < ?::timestamp AND (date + end_time) > ?::timestamp', [$d['ends_at'], $d['starts_at']])->get();
            $block = $preview ? null : Unavailability::create($d);

            return response()->json(['unavailability' => $block, 'affected' => $affected], $preview ? 200 : 201);
        });
    }

    public function preview(Request $r)
    {
        return $this->unavailable($r, true);
    }
}
