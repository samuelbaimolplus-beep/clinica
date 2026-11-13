<?php

/** Registro de cambios y contexto de negocio; no almacena contraseñas ni cuerpos HTTP. */

namespace App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class RegistroAuditoria
{
    /** La IP proviene de la conexión HTTP y de los proxies confiables; nunca del formulario. */
    public function ipCliente(): ?string
    {
        $solicitud = app()->bound('request') ? request() : null;
        $ip = $solicitud?->server('REMOTE_ADDR') ? $solicitud->ip() : null;

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    /** RN59–RN62. Datos seleccionados por el servidor; nunca cuerpos HTTP, contraseñas ni tokens. */
    public function registrar(string $modulo, string $accion, ?Model $registro = null, string $resultado = 'success', array $detalles = [], ?int $responsableId = null): void
    {
        $responsableId ??= auth()->id();
        $responsable = $responsableId ? User::find($responsableId) : null;
        $contexto = ['actor' => $responsable?->only(['id', 'name', 'username', 'role']) ?? ['name' => 'Sistema / sin sesión']];
        if ($registro) {
            $contexto['record'] = DetallesAuditoria::atributosPermitidos($registro->getAttributes());
            $contexto['related'] = DetallesAuditoria::registrosRelacionados($registro);
        }
        if (app()->bound('request') && request()->route()) {
            $contexto['request'] = ['method' => request()->method(), 'route' => request()->route()->uri()];
        }
        $permitidos = DetallesAuditoria::sanear($detalles + $contexto);
        DB::statement('CALL public.registrar_auditoria(?, ?, ?, ?, ?, ?, ?::jsonb, ?::inet)', [
            $responsableId, $modulo, $accion,
            $registro ? class_basename($registro) : null, $registro?->getKey(), $resultado,
            json_encode((object) $permitidos, JSON_THROW_ON_ERROR),
            $this->ipCliente(),
        ]);
    }
}
