<?php

/** Entrada HTTP pública: comprueba mantenimiento, carga Laravel y despacha la solicitud a sus rutas y middleware. */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Una instalación en mantenimiento utiliza la respuesta preparada antes de iniciar el resto de Laravel.
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// El autoloader resuelve las clases propias y dependencias instaladas; no se cargan manualmente por CU.
require __DIR__.'/../vendor/autoload.php';

// Rutas, servicios, permisos y manejo de errores se configuran en bootstrap/app.php.
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
