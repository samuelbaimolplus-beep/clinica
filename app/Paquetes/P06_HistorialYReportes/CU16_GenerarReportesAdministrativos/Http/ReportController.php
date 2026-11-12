<?php

/** Genera reportes autorizados, porcentajes y exportación CSV con datos del servidor. */

namespace App\Paquetes\P06_HistorialYReportes\CU16_GenerarReportesAdministrativos\Http;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function index(Request $r)
    {
        abort_unless($r->user()->hasStaffRole('Administrador'), 403);
        $d = $r->validate([
            'type' => ['required', Rule::in(['REP01', 'REP02', 'REP03', 'REP04', 'REP05', 'REP06', 'REP07'])],
            'date' => ['nullable', 'date_format:Y-m-d', Rule::requiredIf(fn () => $r->input('type') === 'REP07' || ($r->input('type') === 'REP01' && ! $r->filled('from')))],
            'from' => 'required_if:type,REP02,REP03,REP04,REP06|nullable|date_format:Y-m-d',
            'to' => 'required_with:from|nullable|date_format:Y-m-d|after_or_equal:from',
            'doctor_id' => 'required_if:type,REP02,REP07|nullable|integer|exists:doctors,id',
            'specialty_id' => 'required_if:type,REP03|nullable|integer|exists:specialties,id',
            'status' => ['nullable', Rule::in(Appointment::STATES)], 'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);
        if ($d['type'] === 'REP05') {
            $rows = Patient::orderBy('last_name')->get();
            $headers = ['Código', 'CI', 'Paciente', 'Nacimiento', 'Sexo', 'Teléfono', 'Estado'];
            $table = $rows->map(fn ($p) => ['PAC-'.str_pad($p->id, 3, '0', STR_PAD_LEFT), $p->identity, $p->first_name.' '.$p->last_name, $p->birth_date, $p->sex, $p->phone, $p->status]);
        } else {
            $q = Appointment::with('patient', 'doctor', 'specialty')->orderBy('date')->orderBy('start_time');
            if ($d['type'] === 'REP07' || ($d['type'] === 'REP01' && ! empty($d['date']))) {
                $q->where('date', $d['date']);
            } else {
                $q->whereBetween('date', [$d['from'], $d['to']]);
            }
            if (in_array($d['type'], ['REP02', 'REP07']) && ! empty($d['doctor_id'])) {
                $q->where('doctor_id', $d['doctor_id']);
            }
            if ($d['type'] === 'REP03' && ! empty($d['specialty_id'])) {
                $q->where('specialty_id', $d['specialty_id']);
            }
            if ($d['type'] === 'REP04' && ! empty($d['status'])) {
                $q->where('status', $d['status']);
            }
            $rows = $q->get();
            $headers = ['Código', 'Paciente', 'Médico', 'Especialidad', 'Fecha', 'Inicio', 'Fin', 'Estado'];
            $table = $rows->map(fn ($a) => ['CIT-'.str_pad($a->id, 4, '0', STR_PAD_LEFT), $a->patient->first_name.' '.$a->patient->last_name, $a->doctor->first_name.' '.$a->doctor->last_name, $a->specialty->name, $a->date, substr($a->start_time, 0, 5), substr($a->end_time, 0, 5), $a->status]);
        }
        $summary = ['total' => $rows->count()];
        if ($d['type'] !== 'REP05') {
            foreach (Appointment::STATES as $status) {
                $n = $rows->where('status', $status)->count();
                $summary[$status] = ['count' => $n, 'percentage' => $rows->count() ? round($n * 100 / $rows->count(), 1) : 0];
            }
        }
        if (($d['format'] ?? '') === 'csv') {
            return response()->streamDownload(function () use ($headers, $table) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $headers, ';', '"', '');
                foreach ($table as $row) {
                    fputcsv($out, array_map(fn ($v) => preg_match('/^[\s]*[=+@\-]/u', (string) $v) ? "'".$v : $v, $row), ';', '"', '');
                }
                fclose($out);
            }, $d['type'].'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return ['headers' => $headers, 'rows' => $table, 'summary' => $summary];
    }
}
