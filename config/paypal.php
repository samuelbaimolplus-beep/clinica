<?php

/** Configuración de paypal. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. */

return [
    'enabled' => (bool) env('PAYPAL_ENABLED', false),
    'client_id' => env('PAYPAL_CLIENT_ID'),
    'secret' => env('PAYPAL_CLIENT_SECRET'),
    'bob_per_usd' => env('PAYPAL_BOB_PER_USD'),
    'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    // Fijo: esta integración nunca usa el entorno de dinero real.
    'base_url' => 'https://api-m.sandbox.paypal.com',
];
