<?php

/** Proxies que realmente conectan al servidor. Se carga también al usar config:cache. */
return [
    // Ngrok local conecta desde loopback. En otro despliegue, declarar las IP o redes exactas.
    // No se admite confiar indiscriminadamente en todos los remitentes de X-Forwarded-For.
    'proxies' => array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', '127.0.0.1,::1'))),
        fn ($address) => ! in_array($address, ['*', '**', 'REMOTE_ADDR'], true))),
];
