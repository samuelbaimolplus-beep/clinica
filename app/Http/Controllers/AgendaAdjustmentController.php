<?php

namespace App\Http\Controllers;

use App\Models\AgendaAdjustment;
use App\Models\Doctor;
use App\Services\Schedules\AgendaAdjustments;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgendaAdjustmentController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d']);

        return AgendaAdjustment::where('date', $data['date'])->orderBy('start_time')->get()->map(function ($record) {
            $record->setAttribute('age_minutes', (int) $record->created_at->diffInMinutes(now()));
            $record->setAttribute('prolonged', $record->status === 'active' && $record->kind === 'presential_block'
                && $record->created_at->addMinutes(config('clinic.block_warning_minutes')) <= now());

            return $record;
        });
    }

    public function propose(Request $request, AgendaAdjustments $service)
    {
        $data = $request->validate(['specialty_id' => 'required|integer|exists:specialties,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'quantity' => 'required|integer|between:1,100']);

        return $service->propose($data['specialty_id'], $data['date'], $data['quantity']);
    }

    public function protectedBlocks(Request $request, AgendaAdjustments $service)
    {
        $data = $request->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d|after_or_equal:today']);
        abort_unless(Doctor::whereKey($data['doctor_id'])->where('status', 'Activo')->exists(), 422);

        return array_values(array_filter($service->freeBlocks($data['doctor_id'], $data['date']), fn ($block) => $block['protected'] && $block['modality'] === 'presential'));
    }

    public function store(Request $request, AgendaAdjustments $service)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['presential_block', 'online_opening'])],
            'specialty_id' => 'required_if:kind,presential_block|prohibited_if:kind,online_opening|nullable|integer|exists:specialties,id',
            'blocks' => 'required|array|min:1|max:100', 'blocks.*' => 'array:doctor_id,date,start_time,end_time',
            'blocks.*.doctor_id' => 'required|integer|exists:doctors,id',
            'blocks.*.date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'blocks.*.start_time' => 'required|date_format:H:i', 'blocks.*.end_time' => 'required|date_format:H:i',
        ]);

        return response()->json($service->create($data['kind'], $data['specialty_id'] ?? null, $data['blocks'], $request->user()), 201);
    }

    public function release(Request $request, AgendaAdjustments $service, int $id)
    {
        return $service->release($id, $request->user());
    }
}
