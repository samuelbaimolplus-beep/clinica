<?php

namespace App\Support;

use App\Models\User;

/** RN12: matriz única de permisos, compartida por servidor e interfaz. */
final class ClinicAccess
{
    public const VIEWS = [
        'Administrador' => ['CU01', 'CU02', 'CU03', 'CU04', 'CU05', 'CU07', 'PERFIL'],
        'Recepcionista' => ['CU01', 'CU02', 'CU06', 'PERFIL'],
        'Médico' => ['CU01', 'CU02', 'PERFIL'],
        'Paciente' => ['CU01', 'CU02', 'PERFIL'],
    ];

    public static function views(string $role): array
    {
        $views = self::VIEWS[$role] ?? [];
        if (config('clinic.appointments_enabled')) {
            $views = array_merge($views, match ($role) {
                'Administrador' => ['CU15', 'CU16'],
                'Recepcionista' => ['CU08', 'CU09', 'CU10', 'CU11', 'CU12', 'CU13', 'CU15', 'CU17'],
                'Médico' => ['CU12', 'CU14'],
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
            'Administrador', 'Recepcionista' => true,
            default => false,
        };
    }
}
