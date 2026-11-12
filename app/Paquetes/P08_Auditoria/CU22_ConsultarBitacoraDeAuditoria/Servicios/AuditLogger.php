<?php

/** Registro de cambios y contexto de negocio; no almacena contraseñas ni cuerpos HTTP. */

namespace App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class AuditLogger
{
    /** La IP proviene de la conexión HTTP y de los proxies confiables; nunca del formulario. */
    public function clientIp(): ?string
    {
        $request = app()->bound('request') ? request() : null;
        $ip = $request?->server('REMOTE_ADDR') ? $request->ip() : null;

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    /** RN59–RN62. Datos seleccionados por el servidor; nunca cuerpos HTTP, contraseñas ni tokens. */
    public function record(string $module, string $action, ?Model $record = null, string $result = 'success', array $details = [], ?int $actorId = null): void
    {
        $actorId ??= auth()->id();
        $actor = $actorId ? User::find($actorId) : null;
        $context = ['actor' => $actor?->only(['id', 'name', 'username', 'role']) ?? ['name' => 'Sistema / sin sesión']];
        if ($record) {
            $context['record'] = AuditDetails::attributes($record->getAttributes());
            $context['related'] = AuditDetails::related($record);
        }
        if (app()->bound('request') && request()->route()) {
            $context['request'] = ['method' => request()->method(), 'route' => request()->route()->uri()];
        }
        $safe = AuditDetails::sanitize($details + $context);
        DB::statement('CALL public.registrar_auditoria(?, ?, ?, ?, ?, ?, ?::jsonb, ?::inet)', [
            $actorId, $module, $action,
            $record ? class_basename($record) : null, $record?->getKey(), $result,
            json_encode((object) $safe, JSON_THROW_ON_ERROR),
            $this->clientIp(),
        ]);
    }
}
