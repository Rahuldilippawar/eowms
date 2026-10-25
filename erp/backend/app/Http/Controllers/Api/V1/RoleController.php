<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Access\AccessContext;
use App\Support\Access\GrantGuard;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::query()->withCount('assignments')->orderBy('name')->get());
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions')->loadCount('assignments'));
    }

    public function store(Request $request): RoleResource
    {
        $data = $this->validated($request);
        $grants = $this->grants($data['permissions']);
        GrantGuard::forCurrent()->assertMayGrantAll($grants);

        $role = DB::transaction(function () use ($data, $grants, $request) {
            $role = Role::create([
                'key' => $this->uniqueKey($data['name']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);
            $role->forceFill(['created_by' => $request->user()->id])->save();
            $this->syncPermissions($role, $grants);

            return $role;
        });

        AuditLogger::record('roles', 'create', $role, null, ['name' => $role->name, 'permissions' => $this->describe($grants)]);

        return new RoleResource($role->load('permissions')->loadCount('assignments'));
    }

    public function update(Request $request, Role $role): RoleResource
    {
        $this->assertNotOwnRole($request, $role);
        $data = $this->validated($request);
        $grants = $this->grants($data['permissions']);

        $role->load('permissions');
        $oldGrants = $role->permissions->map(fn ($p) => ['key' => $p->key, 'scope' => PermissionScope::from($p->pivot->scope)])->all();

        // Removing a permission is also a grant decision: the actor must hold what they take away.
        $guard = GrantGuard::forCurrent();
        $guard->assertMayGrantAll($grants);
        $guard->assertMayGrantAll($oldGrants);

        if ($role->is_system && $role->key === 'org_admin' && $this->describe($grants) !== $this->describe($oldGrants)) {
            throw ValidationException::withMessages(['permissions' => [__('access.last_admin')]]);
        }

        DB::transaction(function () use ($role, $data, $grants, $request) {
            $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $role->forceFill(['updated_by' => $request->user()->id])->save();
            $this->syncPermissions($role, $grants);
        });

        AuditLogger::record('roles', 'update', $role,
            ['name' => $role->getOriginal('name'), 'permissions' => $this->describe($oldGrants)],
            ['name' => $role->name, 'permissions' => $this->describe($grants)]);

        return new RoleResource($role->load('permissions')->loadCount('assignments'));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => [__('access.system_role')]]);
        }
        if ($role->assignments()->exists()) {
            throw ValidationException::withMessages(['role' => [__('access.role_in_use')]]);
        }
        $role->load('permissions');
        GrantGuard::forCurrent()->assertMayGrantAll(
            $role->permissions->map(fn ($p) => ['key' => $p->key, 'scope' => PermissionScope::from($p->pivot->scope)])->all()
        );

        AuditLogger::record('roles', 'delete', $role, ['name' => $role->name, 'key' => $role->key]);
        $role->delete();

        return response()->json(null, 204);
    }

    private function assertNotOwnRole(Request $request, Role $role): void
    {
        $user = $request->user();
        if (! $user->is_super_admin && $role->assignments()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['role' => [__('access.self_change')]]);
        }
    }

    /** @return array{name: string, description?: string|null, permissions: list<array{key: string, scope: string}>} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['present', 'array'],
            'permissions.*.key' => ['required', 'string', 'distinct', Rule::exists('permissions', 'key')],
            'permissions.*.scope' => ['required', Rule::enum(PermissionScope::class)],
        ]);
    }

    /**
     * @param  list<array{key: string, scope: string}>  $items
     * @return list<array{key: string, scope: PermissionScope}>
     */
    private function grants(array $items): array
    {
        return array_map(fn ($i) => ['key' => $i['key'], 'scope' => PermissionScope::from($i['scope'])], $items);
    }

    /** @param  list<array{key: string, scope: PermissionScope}>  $grants */
    private function syncPermissions(Role $role, array $grants): void
    {
        $ids = Permission::whereIn('key', array_column($grants, 'key'))->pluck('id', 'key');
        $role->permissions()->sync(collect($grants)->mapWithKeys(fn ($g) => [$ids[$g['key']] => ['scope' => $g['scope']->value]])->all());
    }

    /**
     * @param  list<array{key: string, scope: PermissionScope}>  $grants
     * @return array<string, string> key => scope, sorted
     */
    private function describe(array $grants): array
    {
        $out = [];
        foreach ($grants as $g) {
            $out[$g['key']] = $g['scope']->value;
        }
        ksort($out);

        return $out;
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'role';
        $key = $base;
        $orgId = AccessContext::current()->organizationId();
        for ($i = 2; Role::withoutGlobalScopes()->where(['organization_id' => $orgId, 'key' => $key])->exists(); $i++) {
            $key = "{$base}_{$i}";
        }

        return mb_substr($key, 0, 60);
    }
}
