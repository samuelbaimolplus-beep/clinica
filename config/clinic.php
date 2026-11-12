<?php

/** Configuración de clinic. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. */

return [
    'appointments_enabled' => env('CLINIC_APPOINTMENTS_ENABLED', true),
    'terms_version' => '2026-11-02',
];
