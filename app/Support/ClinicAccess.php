<?php

namespace App\Support;

use App\Models\User;

/** RN12: matriz única de permisos, compartida por servidor e interfaz. */
final class ClinicAccess
{
    public const VIEWS = [
        'Superadmin' => ['CU01', 'CU02', 'CU03', 'CU04', 'CU05', 'CU06', 'CU07', 'CU08', 'CU22', 'CU24', 'CU25'],
        'Administrador' => ['CU01', 'CU02', 'CU03', 'CU04', 'CU05', 'CU06', 'CU07', 'CU22'],
        'Recepcionista' => ['CU01', 'CU02', 'CU06', 'CU24', 'CU25', 'CU08', 'CU17'],
        'Médico' => ['CU01', 'CU02'],
        'Paciente' => ['CU01', 'CU02', 'CU08'],
    ];

    public static function views(string $role): array
    {
        $views = self::VIEWS[$role] ?? [];
        if (config('clinic.appointments_enabled')) {
            $views = array_merge($views, match ($role) {
                'Superadmin' => ['CU09', 'CU10', 'CU11', 'CU12', 'CU13', 'CU14', 'CU15', 'CU16', 'CU17', 'CU18', 'CU19', 'CU20', 'CU21', 'CU23', 'CU26'],
                'Administrador' => ['CU15', 'CU16', 'CU26'],
                'Recepcionista' => ['CU09', 'CU10', 'CU11', 'CU12', 'CU13', 'CU15', 'CU20', 'CU21'],
                'Médico' => ['CU12', 'CU14'],
                'Paciente' => ['CU10', 'CU15', 'CU18', 'CU20'],
                default => [],
            });
        }

        return $views;
    }

    public static function isActive(User $user): bool
    {
        return $user->status === 'Activo' && match ($user->role) {
            'Paciente' => $user->patient?->status === 'Activo',
            'Médico' => $user->doctor?->status === 'Activo',
            'Superadmin', 'Administrador', 'Recepcionista' => true,
            default => false,
        };
    }
}
