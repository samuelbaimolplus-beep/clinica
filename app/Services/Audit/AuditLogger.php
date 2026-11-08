<?php

namespace App\Services\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class AuditLogger
{
    /** RN59–RN62. Solo metadatos permitidos; nunca cuerpos HTTP, contraseñas ni tokens. */
    public function record(string $module, string $action, ?Model $record = null, string $result = 'success', array $details = [], ?int $actorId = null): void
    {
        $safe = array_intersect_key($details, array_flip(['fields', 'specialty_ids', 'reason', 'terms_version']));
        DB::statement('CALL public.registrar_auditoria(?, ?, ?, ?, ?, ?, ?::jsonb)', [
            $actorId ?? auth()->id(), $module, $action,
            $record ? class_basename($record) : null, $record?->getKey(), $result,
            json_encode((object) $safe, JSON_THROW_ON_ERROR),
        ]);
    }
}
