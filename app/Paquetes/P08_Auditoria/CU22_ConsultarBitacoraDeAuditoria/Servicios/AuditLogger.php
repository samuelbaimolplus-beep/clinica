<?php

/** Registro de auditoría con metadatos permitidos e IP del cliente; no almacena contraseñas ni cuerpos HTTP. */

namespace App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios;

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
    /** RN59–RN62. Solo metadatos permitidos; nunca cuerpos HTTP, contraseñas ni tokens. */
    public function record(string $module, string $action, ?Model $record = null, string $result = 'success', array $details = [], ?int $actorId = null): void
    {
        $safe = array_intersect_key($details, array_flip(['fields', 'specialty_ids', 'reason', 'terms_version']));
        DB::statement('CALL public.registrar_auditoria(?, ?, ?, ?, ?, ?, ?::jsonb, ?::inet)', [
            $actorId ?? auth()->id(), $module, $action,
            $record ? class_basename($record) : null, $record?->getKey(), $result,
            json_encode((object) $safe, JSON_THROW_ON_ERROR),
            $this->clientIp(),
        ]);
    }
}
