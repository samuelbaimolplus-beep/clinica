<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** CU22: acceso administrativo paginado, sin operaciones de escritura. */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'user_id' => 'nullable|integer|exists:users,id', 'module' => 'nullable|string|max:40',
            'action' => 'nullable|string|max:40', 'result' => 'nullable|string|max:20',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = AuditEvent::query()
            ->when($data['from'] ?? null, fn ($q, $value) => $q->where('occurred_at', '>=', CarbonImmutable::parse($value)->startOfDay()->toIso8601String()))
            ->when($data['to'] ?? null, fn ($q, $value) => $q->where('occurred_at', '<', CarbonImmutable::parse($value)->addDay()->startOfDay()->toIso8601String()));
        foreach (['user_id', 'module', 'action', 'result'] as $field) {
            $query->when($data[$field] ?? null, fn ($q, $value) => $q->where($field, $value));
        }

        return $query->select(['id', 'occurred_at', 'user_id', 'module', 'action', 'record_type', 'record_id', 'result'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50);
    }

    public function show(int $id)
    {
        $event = AuditEvent::findOrFail($id);
        // Lista permitida para que una integración futura no publique detalles secretos por accidente.
        $event->details = array_intersect_key($event->details ?? [], array_flip(['fields', 'specialty_ids', 'reason', 'terms_version']));

        return $event;
    }

    public function filters()
    {
        return ['users' => User::orderBy('name')->get(['id', 'name', 'role']),
            'modules' => AuditEvent::distinct()->orderBy('module')->pluck('module'),
            'actions' => AuditEvent::distinct()->orderBy('action')->pluck('action'),
            'results' => AuditEvent::distinct()->orderBy('result')->pluck('result')];
    }
}
