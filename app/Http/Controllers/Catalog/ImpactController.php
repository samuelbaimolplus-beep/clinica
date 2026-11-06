<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogImpact;
use Illuminate\Http\Request;

class ImpactController extends Controller
{
    public function __invoke(Request $request, CatalogImpact $impact)
    {
        abort_unless(in_array($request->user()->role, ['Administrador', 'Recepcionista'], true), 403);

        return $impact->affected();
    }
}
