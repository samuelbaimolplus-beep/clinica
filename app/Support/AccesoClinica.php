<?php

/** Matriz central de vistas por rol; Superadmin hereda gestión, los demás roles mantienen su ámbito. */

namespace App\Support;

use App\Models\Usuario;

/** RN12: matriz única de permisos, compartida por servidor e interfaz. */
final class AccesoClinica
{
    public const VIEWS = [
        'Superadmin' => ['CU01', 'CU02', 'CU03', 'CU04', 'CU05', 'CU06', 'CU07', 'CU08', 'CU22', 'CU24', 'CU25'],
        'Administrador' => ['CU01', 'CU02', 'CU03', 'CU04', 'CU05', 'CU06', 'CU07', 'CU22'],
        'Recepcionista' => ['CU01', 'CU02', 'CU06', 'CU24', 'CU25', 'CU08', 'CU17'],
        'Médico' => ['CU01', 'CU02'],
        'Paciente' => ['CU01', 'CU02', 'CU08'],
    ];

    /** Devuelve los CU navegables por rol y por disponibilidad del módulo de citas. */
    public static function vistasPermitidas(string $rol): array
    {
        $vistas = self::VIEWS[$rol] ?? [];
        if (config('clinic.appointments_enabled')) {
            $vistas = array_merge($vistas, match ($rol) {
                'Superadmin' => ['CU09', 'CU10', 'CU11', 'CU12', 'CU13', 'CU14', 'CU15', 'CU16', 'CU17', 'CU18', 'CU19', 'CU20', 'CU21', 'CU23', 'CU26'],
                'Administrador' => ['CU15', 'CU16', 'CU26'],
                'Recepcionista' => ['CU09', 'CU10', 'CU11', 'CU12', 'CU13', 'CU15', 'CU20', 'CU21'],
                'Médico' => ['CU12', 'CU14'],
                'Paciente' => ['CU10', 'CU15', 'CU18', 'CU20'],
                default => [],
            });
        }

        return $vistas;
    }

    /** Comprueba que cuenta y ficha vinculada estén activas para el rol correspondiente. */
    public static function estaActivo(Usuario $usuario): bool
    {
        return $usuario->status === 'Activo' && match ($usuario->role) {
            'Paciente' => $usuario->patient?->status === 'Activo',
            'Médico' => $usuario->doctor?->status === 'Activo',
            'Superadmin', 'Administrador', 'Recepcionista' => true,
            default => false,
        };
    }
}
