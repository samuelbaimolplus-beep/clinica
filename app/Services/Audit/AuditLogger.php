<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;

final class AuditLogger
{
    /** RN59–RN62. Solo metadatos permitidos; nunca cuerpos HTTP, contraseñas ni tokens. */
    public function record(string $module, string $action, ?Model $record = null, string $result = 'success', array $details = [], ?int $actorId = null): void
    {
        AuditEvent::create([
            'user_id' => $actorId ?? auth()->id(),
            'module' => $module,
            'action' => $action,
            'record_type' => $record ? class_basename($record) : null,
            'record_id' => $record?->getKey(),
            'result' => $result,
            'details' => array_intersect_key($details, array_flip(['fields', 'specialty_ids', 'reason', 'terms_version'])),
        ]);
    }
}
