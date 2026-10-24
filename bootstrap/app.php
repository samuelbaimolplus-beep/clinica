<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (QueryException $e, Request $r) {
            if ($r->is('api/*')) {
                $conflict = ($e->errorInfo[0] ?? null) === '23505';

                return response()->json(['message' => $conflict ? 'Ya existe un registro con esos datos. Actualice la pantalla e intente nuevamente.' : 'No se pudo completar la operación en PostgreSQL. Revise la conexión o consulte al administrador.'], $conflict ? 409 : 503);
            }
        });
    })->create();
