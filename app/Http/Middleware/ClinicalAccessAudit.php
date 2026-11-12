<?php

/** Entrada HTTP de ClinicalAccessAudit. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Http\Middleware;

use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ClinicalAccessAudit
{
    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
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

    /** Registra o comunica el acceso rechazado sin exponer datos del recurso. */
    private function denied(): void
    {
        app(AuditLogger::class)->record('clinical_access', 'denied', result: 'denied', details: ['reason' => 'Acceso clínico denegado por permisos o titularidad.']);
    }
}
