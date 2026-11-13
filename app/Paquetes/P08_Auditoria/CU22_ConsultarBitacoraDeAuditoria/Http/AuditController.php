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
    public function listar(Request $solicitud)
    {
        $datos = $solicitud->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($solicitud->filled('from') ? ['after_or_equal:from'] : [])],
            'user_id' => 'nullable|integer|exists:users,id', 'module' => 'nullable|string|max:40',
            'action' => 'nullable|string|max:40', 'result' => 'nullable|string|max:20',
            'ip_address' => 'nullable|ip',
            'page' => 'nullable|integer|min:1',
        ]);
        $consultaDatos = AuditEvent::query()
            ->when($datos['from'] ?? null, fn ($consulta, $valor) => $consulta->where('occurred_at', '>=', CarbonImmutable::parse($valor)->startOfDay()->toIso8601String()))
            ->when($datos['to'] ?? null, fn ($consulta, $valor) => $consulta->where('occurred_at', '<', CarbonImmutable::parse($valor)->addDay()->startOfDay()->toIso8601String()));
        foreach (['user_id', 'module', 'action', 'result', 'ip_address'] as $campo) {
            $consultaDatos->when($datos[$campo] ?? null, fn ($consulta, $valor) => $consulta->where($campo, $valor));
        }

        return $consultaDatos->select(['id', 'occurred_at', 'user_id', 'module', 'action', 'record_type', 'record_id', 'result', 'ip_address', 'details'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50)
            ->through(function ($evento) {
                $detalles = AuditDetails::sanear($evento->details ?? []);
                // El listado solo necesita contexto breve; los valores completos se consultan por evento.
                $evento->details = array_intersect_key($detalles, array_flip(['actor', 'record', 'related', 'fields', 'security_changed']));
                if (isset($evento->details['record'])) {
                    $resumen = $evento->details;
                    $resumen['record'] = array_intersect_key($resumen['record'], array_flip([
                        'id', 'name', 'username', 'first_name', 'last_name', 'date', 'start_time', 'end_time', 'status',
                    ]));
                    $evento->details = $resumen;
                }

                return $evento;
            });
    }

    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function consultarDetalle(int $id)
    {
        $evento = AuditEvent::findOrFail($id);
        // Lista permitida para que una integración futura no publique detalles secretos por accidente.
        $evento->details = AuditDetails::sanear($evento->details ?? []);

        return $evento;
    }

    /** Devuelve las opciones permitidas de consulta del módulo. */
    public function filtros()
    {
        return ['users' => User::orderBy('name')->get(['id', 'name', 'role']),
            'modules' => AuditEvent::distinct()->orderBy('module')->pluck('module'),
            'actions' => AuditEvent::distinct()->orderBy('action')->pluck('action'),
            'results' => AuditEvent::distinct()->orderBy('result')->pluck('result')];
    }
}
