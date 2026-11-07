<?php

// V6 selecciona exclusivamente la pasarela de pruebas. La URL no es configurable a producción.
return ['key' => env('WIZPAY_TEST_API_KEY'), 'webhook_key' => env('WIZPAY_TEST_WEBHOOK_KEY'), 'base_url' => 'https://api-test.wizpay.app'];
