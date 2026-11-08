<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveUserRequest;
use App\Models\User;
use App\Services\Auth\EmailActivation;
use App\Services\Catalog\CatalogService;

class UserController extends Controller
{
    public function index()
    {
        return User::where('role', '<>', 'Paciente')->when(request()->user()->role !== 'Superadmin', fn ($q) => $q->where('role', '<>', 'Superadmin'))->orderBy('id')->get();
    }

    public function show(int $id)
    {
        return User::where('role', '<>', 'Paciente')->when(request()->user()->role !== 'Superadmin', fn ($q) => $q->where('role', '<>', 'Superadmin'))->findOrFail($id);
    }

    public function save(SaveUserRequest $request, CatalogService $service, ?int $id = null)
    {
        $record = $id ? User::findOrFail($id) : new User;

        $record = $service->save($record, $request->validated(), $request->user());
        if (! $record->email_verified_at) {
            $record->setAttribute('activation_email_sent', app(EmailActivation::class)->send($record));
        }

        return response()->json($record, $id ? 200 : 201)->header('Cache-Control', 'no-store');
    }
}
