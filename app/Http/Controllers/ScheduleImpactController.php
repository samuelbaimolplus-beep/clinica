<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\Catalog\CatalogImpact;
use App\Services\Schedules\ScheduleImpact;
use Illuminate\Http\Request;

class ScheduleImpactController extends Controller
{
    public function __invoke(Request $request, ScheduleImpact $schedules, CatalogImpact $catalogs)
    {
        abort_unless(in_array($request->user()->role, ['Administrador', 'Recepcionista'], true), 403);
        $data = $request->validate(['doctor_id' => 'nullable|integer|exists:doctors,id']);
        $doctorId = $data['doctor_id'] ?? null;

        $affected = $schedules->affected($doctorId)->concat($catalogs->affected($doctorId))->groupBy('id')
            ->map(fn ($items) => array_replace($items->first(), ['reasons' => $items->pluck('reasons')->flatten()->unique()->values()->all()]))
            ->sortBy(fn ($item) => $item['date'].' '.$item['start_time'])->values();
        if ($request->user()->role === 'Recepcionista') {
            $appointments = Appointment::whereIn('id', $affected->pluck('id'))->with('patient:id,first_name,last_name,phone')->get()->keyBy('id');
            $affected = $affected->map(fn ($item) => $item + ['patient' => $appointments[$item['id']]->patient]);
        }

        return $affected;
    }
}
