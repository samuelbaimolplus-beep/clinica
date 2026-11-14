<?php

use App\Notifications\VerificarCorreoClinica;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/** Servidor exclusivo de pruebas: Laravel y su API son reales; solo correo y PayPal se simulan. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__.'/../../public'.$path)) {
    return false;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'clinica_vida_salud_browser_test') {
    http_response_code(503);
    exit('El navegador de pruebas requiere su base independiente.');
}
// Cookies y caché separados: los identificadores de esta base no deben colisionar con los reales.
$fixture = json_decode(file_get_contents(storage_path('app/private/browser-real-fixture.json')), true);
$cache = 'framework/cache/browser-real/'.$fixture['run'];
foreach ([$cache, 'framework/sessions/browser-real'] as $directory) {
    if (! is_dir(storage_path($directory))) {
        mkdir(storage_path($directory), 0700, true);
    }
}
config(['cache.stores.file.path' => storage_path($cache), 'cache.stores.file.lock_path' => storage_path($cache), 'session.files' => storage_path('framework/sessions/browser-real')]);
$orders = storage_path('app/private/browser-real-paypal');
if (! is_dir($orders)) {
    mkdir($orders, 0700, true);
}
if ($path === '/__qa/approve') {
    $id = $_GET['token'] ?? '';
    if (! preg_match('/^SIM-[0-9]+$/D', $id) || ! is_file($orders.'/'.$id.'.json')) {
        http_response_code(404);
        exit;
    }
    $order = json_decode(file_get_contents($orders.'/'.$id.'.json'), true);
    $order['status'] = 'APPROVED';
    file_put_contents($orders.'/'.$id.'.json', json_encode($order));
    $ref = $order['purchase_units'][0]['reference_id'];
    $booking = explode('-', $ref)[1];
    header('Location: /pagos/paypal/retorno?operacion='.$booking);
    exit;
}
Http::preventStrayRequests();
Http::fake(function ($request) use ($orders) {
    $url = $request->url();
    if ($url === 'https://api-m.sandbox.paypal.com/v1/oauth2/token') {
        return Http::response(['access_token' => 'external-simulation']);
    }
    if ($url === 'https://api-m.sandbox.paypal.com/v2/checkout/orders') {
        $header = $request->header('PayPal-Request-Id')[0];
        $payment = substr($header, strlen('CVS-ORDER-'));
        $id = 'SIM-'.$payment;
        $order = ['id' => $id, 'intent' => 'CAPTURE', 'status' => 'CREATED', 'purchase_units' => $request['purchase_units'], 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.$id]]];
        file_put_contents($orders.'/'.$id.'.json', json_encode($order));

        return Http::response($order, 201);
    }
    if (preg_match('~^https://api-m\.sandbox\.paypal\.com/v2/checkout/orders/(SIM-[0-9]+)(/capture)?$~', $url, $m) && is_file($orders.'/'.$m[1].'.json')) {
        $order = json_decode(file_get_contents($orders.'/'.$m[1].'.json'), true);
        if (isset($m[2])) {
            $order['status'] = 'COMPLETED';
            $order['purchase_units'][0]['payments']['captures'] = [['id' => 'CAPTURE-'.$m[1], 'status' => 'COMPLETED', 'final_capture' => true, 'amount' => $order['purchase_units'][0]['amount']]];
            file_put_contents($orders.'/'.$m[1].'.json', json_encode($order));
        }

        return Http::response($order);
    }
    throw new RuntimeException('Salida externa no prevista en el navegador de pruebas.');
});
// Se captura el código únicamente en este entorno aislado; la aplicación real conserva solo su HMAC.
Event::listen(NotificationSent::class, function ($event) {
    if ($event->notification instanceof VerificarCorreoClinica) {
        $file = storage_path('app/private/browser-real-mail.json');
        $mail = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        $mail[$event->notifiable->email] = $event->notification->code;
        file_put_contents($file, json_encode($mail));
    }
});
$app->handleRequest(Request::capture());
