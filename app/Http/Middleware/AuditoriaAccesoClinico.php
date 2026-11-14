<?php

/** Entrada HTTP de ClinicalAccessAudit. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Http\Middleware;

use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\RegistroAuditoria;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AuditoriaAccesoClinico
{
    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
    public function handle(Request $peticion, Closure $siguiente)
    {
        try {
            $respuesta = $siguiente($peticion);
            if ($respuesta->getStatusCode() === 403) {
                $this->registrarDenegacion();
            }

            return $respuesta;
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403)) {
                $this->registrarDenegacion();
            }
            throw $e;
        }
    }

    /** Registra o comunica el acceso rechazado sin exponer datos del recurso. */
    private function registrarDenegacion(): void
    {
        app(RegistroAuditoria::class)->registrar('clinical_access', 'denied', resultado: 'denied', detalles: ['reason' => 'Acceso clínico denegado por permisos o titularidad.']);
    }
}
