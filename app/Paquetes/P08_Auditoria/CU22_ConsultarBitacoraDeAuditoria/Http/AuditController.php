<?php

/** Entrada HTTP de AuditController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Http;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditDetails;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** CU22: acceso administrativo paginado, sin operaciones de escritura. */
class AuditController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'user_id' => 'nullable|integer|exists:users,id', 'module' => 'nullable|string|max:40',
            'action' => 'nullable|string|max:40', 'result' => 'nullable|string|max:20',
            'ip_address' => 'nullable|ip',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = AuditEvent::query()
            ->when($data['from'] ?? null, fn ($q, $value) => $q->where('occurred_at', '>=', CarbonImmutable::parse($value)->startOfDay()->toIso8601String()))
            ->when($data['to'] ?? null, fn ($q, $value) => $q->where('occurred_at', '<', CarbonImmutable::parse($value)->addDay()->startOfDay()->toIso8601String()));
        foreach (['user_id', 'module', 'action', 'result', 'ip_address'] as $field) {
            $query->when($data[$field] ?? null, fn ($q, $value) => $q->where($field, $value));
        }

        return $query->select(['id', 'occurred_at', 'user_id', 'module', 'action', 'record_type', 'record_id', 'result', 'ip_address', 'details'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50)
            ->through(function ($event) {
                $details = AuditDetails::sanitize($event->details ?? []);
                // El listado solo necesita contexto breve; los valores completos se consultan por evento.
                $event->details = array_intersect_key($details, array_flip(['actor', 'record', 'related', 'fields', 'security_changed']));
                if (isset($event->details['record'])) {
                    $summary = $event->details;
                    $summary['record'] = array_intersect_key($summary['record'], array_flip([
                        'id', 'name', 'username', 'first_name', 'last_name', 'date', 'start_time', 'end_time', 'status',
                    ]));
                    $event->details = $summary;
                }

                return $event;
            });
    }

    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function show(int $id)
    {
        $event = AuditEvent::findOrFail($id);
        // Lista permitida para que una integración futura no publique detalles secretos por accidente.
        $event->details = AuditDetails::sanitize($event->details ?? []);

        return $event;
    }

    /** Devuelve las opciones permitidas de consulta del módulo. */
    public function filters()
    {
        return ['users' => User::orderBy('name')->get(['id', 'name', 'role']),
            'modules' => AuditEvent::distinct()->orderBy('module')->pluck('module'),
            'actions' => AuditEvent::distinct()->orderBy('action')->pluck('action'),
            'results' => AuditEvent::distinct()->orderBy('result')->pluck('result')];
    }
}
