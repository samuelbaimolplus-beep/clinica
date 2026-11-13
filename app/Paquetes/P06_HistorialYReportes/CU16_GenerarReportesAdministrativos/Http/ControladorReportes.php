<?php

/** Genera reportes autorizados, porcentajes y exportación CSV con datos del servidor. */

namespace App\Paquetes\P06_HistorialYReportes\CU16_GenerarReportesAdministrativos\Http;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ControladorReportes extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function listar(Request $peticion)
    {
        abort_unless($peticion->user()->hasStaffRole('Administrador'), 403);
        $criterios = $peticion->validate([
            'type' => ['required', Rule::in(['REP01', 'REP02', 'REP03', 'REP04', 'REP05', 'REP06', 'REP07'])],
            'date' => ['nullable', 'date_format:Y-m-d', Rule::requiredIf(fn () => $peticion->input('type') === 'REP07' || ($peticion->input('type') === 'REP01' && ! $peticion->filled('from')))],
            'from' => 'required_if:type,REP02,REP03,REP04,REP06|nullable|date_format:Y-m-d',
            'to' => 'required_with:from|nullable|date_format:Y-m-d|after_or_equal:from',
            'doctor_id' => 'required_if:type,REP02,REP07|nullable|integer|exists:doctors,id',
            'specialty_id' => 'required_if:type,REP03|nullable|integer|exists:specialties,id',
            'status' => ['nullable', Rule::in(Appointment::STATES)], 'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);
        if ($criterios['type'] === 'REP05') {
            $filas = Patient::orderBy('last_name')->get();
            $cabeceras = ['Código', 'CI', 'Paciente', 'Nacimiento', 'Sexo', 'Teléfono', 'Estado'];
            $tabla = $filas->map(fn ($elementoActual) => ['PAC-'.str_pad($elementoActual->id, 3, '0', STR_PAD_LEFT), $elementoActual->identity, $elementoActual->first_name.' '.$elementoActual->last_name, $elementoActual->birth_date, $elementoActual->sex, $elementoActual->phone, $elementoActual->status]);
        } else {
            $consulta = Appointment::with('patient', 'doctor', 'specialty')->orderBy('date')->orderBy('start_time');
            if ($criterios['type'] === 'REP07' || ($criterios['type'] === 'REP01' && ! empty($criterios['date']))) {
                $consulta->where('date', $criterios['date']);
            } else {
                $consulta->whereBetween('date', [$criterios['from'], $criterios['to']]);
            }
            if (in_array($criterios['type'], ['REP02', 'REP07']) && ! empty($criterios['doctor_id'])) {
                $consulta->where('doctor_id', $criterios['doctor_id']);
            }
            if ($criterios['type'] === 'REP03' && ! empty($criterios['specialty_id'])) {
                $consulta->where('specialty_id', $criterios['specialty_id']);
            }
            if ($criterios['type'] === 'REP04' && ! empty($criterios['status'])) {
                $consulta->where('status', $criterios['status']);
            }
            $filas = $consulta->get();
            $cabeceras = ['Código', 'Paciente', 'Médico', 'Especialidad', 'Fecha', 'Inicio', 'Fin', 'Estado'];
            $tabla = $filas->map(fn ($citaActual) => ['CIT-'.str_pad($citaActual->id, 4, '0', STR_PAD_LEFT), $citaActual->patient->first_name.' '.$citaActual->patient->last_name, $citaActual->doctor->first_name.' '.$citaActual->doctor->last_name, $citaActual->specialty->name, $citaActual->date, substr($citaActual->start_time, 0, 5), substr($citaActual->end_time, 0, 5), $citaActual->status]);
        }
        $resumen = ['total' => $filas->count()];
        if ($criterios['type'] !== 'REP05') {
            foreach (Appointment::STATES as $estado) {
                $cantidad = $filas->where('status', $estado)->count();
                $resumen[$estado] = ['count' => $cantidad, 'percentage' => $filas->count() ? round($cantidad * 100 / $filas->count(), 1) : 0];
            }
        }
        if (($criterios['format'] ?? '') === 'csv') {
            return response()->streamDownload(function () use ($cabeceras, $tabla) {
                $salida = fopen('php://output', 'w');
                fwrite($salida, "\xEF\xBB\xBF");
                fputcsv($salida, $cabeceras, ';', '"', '');
                foreach ($tabla as $fila) {
                    fputcsv($salida, array_map(fn ($valorCelda) => preg_match('/^[\s]*[=+@\-]/u', (string) $valorCelda) ? "'".$valorCelda : $valorCelda, $fila), ';', '"', '');
                }
                fclose($salida);
            }, $criterios['type'].'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return ['headers' => $cabeceras, 'rows' => $tabla, 'summary' => $resumen];
    }
}
