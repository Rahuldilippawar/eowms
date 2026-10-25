<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Support\Queries\ScopedQueries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'institution_id' => ['nullable', 'integer'],
            'central' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ScopedQueries::departments()
            ->with('institution')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($filters['institution_id'] ?? null, fn ($q, $id) => $q->where('institution_id', $id))
            ->when(($filters['central'] ?? null) === true, fn ($q) => $q->whereNull('institution_id'))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name');

        return DepartmentResource::collection($query->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function show(Department $department): DepartmentResource
    {
        $visible = ScopedQueries::departments()->whereKey($department->id)->exists();
        abort_unless($visible, 404);

        return new DepartmentResource($department->load('institution'));
    }
}
