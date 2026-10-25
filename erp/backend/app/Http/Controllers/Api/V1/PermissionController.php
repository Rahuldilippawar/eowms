<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Support\Access\AccessContext;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /** The catalogue, grouped by module, with the caller's own scope per key (null = not held). */
    public function index(): JsonResponse
    {
        $context = AccessContext::current();
        $modules = Permission::query()->orderBy('module')->orderBy('id')->get()->groupBy('module')
            ->map(fn ($perms, $module) => [
                'module' => $module,
                'permissions' => $perms->map(fn ($p) => [
                    'key' => $p->key,
                    'action' => $p->action,
                    'my_scope' => $context->scope($p->key)?->scope->value,
                ])->values(),
            ])->values();

        return response()->json(['data' => $modules]);
    }
}
