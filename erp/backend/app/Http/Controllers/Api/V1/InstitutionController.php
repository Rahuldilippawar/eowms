<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InstitutionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\InstitutionResource;
use App\Models\Institution;
use App\Support\Queries\ScopedQueries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class InstitutionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(InstitutionType::class)],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ScopedQueries::institutions()
            ->withCount('departments')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name');

        return InstitutionResource::collection($query->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function show(Institution $institution): InstitutionResource
    {
        // Bound within the current organization already; this enforces the user's scope.
        $visible = ScopedQueries::institutions()->whereKey($institution->id)->exists();
        abort_unless($visible, 404);

        return new InstitutionResource($institution->loadCount('departments'));
    }
}
