<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ClinicalAccessAudit
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
            if ($response->getStatusCode() === 403) {
                $this->denied();
            }

            return $response;
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403)) {
                $this->denied();
            }
            throw $e;
        }
    }

    private function denied(): void
    {
        app(AuditLogger::class)->record('clinical_access', 'denied', result: 'denied', details: ['reason' => 'Acceso clínico denegado por permisos o titularidad.']);
    }
}
