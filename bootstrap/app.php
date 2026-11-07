<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['webhooks/wizpay']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (QueryException $e, Request $r) {
            if ($r->is('api/*')) {
                $conflict = in_array($e->errorInfo[0] ?? null, ['23505', '23P01'], true);

                return response()->json(['message' => $conflict ? 'Ya existe un registro con esos datos. Actualice la pantalla e intente nuevamente.' : 'No se pudo completar la operación en PostgreSQL. Revise la conexión o consulte al administrador.'], $conflict ? 409 : 503);
            }
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/*')
                && ! $exception instanceof HttpExceptionInterface
                && ! $exception instanceof ValidationException) {
                return response()->json(['message' => 'No se pudo completar la operación. Intente nuevamente o consulte al administrador.'], 503);
            }
        });
    })->create();
