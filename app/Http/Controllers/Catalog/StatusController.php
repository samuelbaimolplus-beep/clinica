<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StatusController extends Controller
{
    public function __invoke(Request $request, CatalogService $service, int $id)
    {
        $catalog = $request->route('catalog');
        $model = match ($catalog) {
            'users' => User::class, 'doctors' => Doctor::class, 'specialties' => Specialty::class, 'patients' => Patient::class,
            default => abort(404),
        };
        abort_unless($request->user()->can('manage-'.$catalog), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['Activo', 'Inactivo'])]]);

        return $service->changeStatus($model::findOrFail($id), $data['status'], $request->user());
    }
}
