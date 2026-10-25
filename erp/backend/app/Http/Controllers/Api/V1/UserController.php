<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Access\AccessContext;
use App\Support\Access\GrantGuard;
use App\Support\Access\UserGuard;
use App\Support\Audit\AuditLogger;
use App\Support\Queries\ScopedQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ScopedQueries::users()
            ->with(['roleAssignments' => fn ($q) => $q->with(['role', 'institutions', 'departments'])])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name');

        return UserResource::collection($query->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function show(User $user): UserResource
    {
        UserGuard::assertVisible($user, 'users.view');

        return new UserResource($this->loaded($user));
    }

    /**
     * Creates the account together with its first role (an account without a
     * role in this organization would be invisible here). The person sets
     * their own password through an emailed link — no password is chosen,
     * shown or sent by the administrator.
     */
    public function store(Request $request): UserResource
    {
        abort_unless(AccessContext::current()->can('roles.assign'), 403, __('auth.forbidden'));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            ...$this->assignmentRules(),
        ]);

        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => [__('access.email_taken')]]);
        }

        [$role, $institutionIds, $departmentIds] = $this->resolveAssignment($data);
        GrantGuard::forCurrent()->assertMayAssign($role, $institutionIds, $departmentIds);

        $user = DB::transaction(function () use ($data, $role, $institutionIds, $departmentIds, $request) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(40), 'is_active' => true]);
            $this->createAssignment($user, $role, $institutionIds, $departmentIds, $request->user()->id);

            return $user;
        });

        AuditLogger::record('users', 'create', $user, null, ['name' => $user->name, 'email' => $user->email, 'role' => $role->key]);
        Password::sendResetLink(['email' => $user->email]);

        return new UserResource($this->loaded($user));
    }

    public function update(Request $request, User $user): UserResource
    {
        UserGuard::assertVisible($user, 'users.edit');
        UserGuard::assertMayChangeAccount($request->user(), $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $before = $user->getAttributes();
        $user->fill($data)->save();
        AuditLogger::recordChanges('users', 'update', $user, $before);

        return new UserResource($this->loaded($user));
    }

    public function setActive(Request $request, User $user): UserResource
    {
        UserGuard::assertVisible($user, 'users.activate');
        UserGuard::assertNotSelf($request->user(), $user);
        UserGuard::assertMayChangeAccount($request->user(), $user);
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);

        $before = $user->getAttributes();
        $user->forceFill(['is_active' => (bool) $data['is_active']])->save();
        if (! $user->is_active) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        AuditLogger::recordChanges('users', $data['is_active'] ? 'activate' : 'deactivate', $user, $before, $data['reason'] ?? null);

        return new UserResource($this->loaded($user));
    }

    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        UserGuard::assertVisible($user, 'users.reset_password');
        UserGuard::assertMayChangeAccount($request->user(), $user);
        abort_unless($user->is_active, 422, __('auth.inactive'));

        Password::sendResetLink(['email' => $user->email]);
        AuditLogger::record('users', 'reset_password', $user);

        return response()->json(['message' => __('passwords.sent')]);
    }

    public function addAssignment(Request $request, User $user): UserResource
    {
        UserGuard::assertVisible($user, 'users.view');
        UserGuard::assertNotSelf($request->user(), $user);
        $data = $request->validate($this->assignmentRules());

        [$role, $institutionIds, $departmentIds] = $this->resolveAssignment($data);
        GrantGuard::forCurrent()->assertMayAssign($role, $institutionIds, $departmentIds);

        if (RoleAssignment::query()->where(['user_id' => $user->id, 'role_id' => $role->id])->exists()) {
            throw ValidationException::withMessages(['role_id' => [__('validation.unique', ['attribute' => 'role'])]]);
        }

        DB::transaction(fn () => $this->createAssignment($user, $role, $institutionIds, $departmentIds, $request->user()->id));
        AuditLogger::record('users', 'assign_role', $user, null, ['role' => $role->key, 'institutions' => $institutionIds, 'departments' => $departmentIds]);

        return new UserResource($this->loaded($user));
    }

    public function removeAssignment(Request $request, RoleAssignment $assignment): JsonResponse
    {
        abort_unless(AccessContext::current()->can('roles.assign'), 403, __('auth.forbidden'));
        $target = $assignment->user;
        UserGuard::assertVisible($target, 'users.view');
        UserGuard::assertNotSelf($request->user(), $target);

        // Taking a role away needs the same reach as giving it.
        $role = $assignment->role()->with('permissions')->firstOrFail();
        GrantGuard::forCurrent()->assertMayAssign($role, $assignment->institutions()->pluck('institutions.id')->all(), $assignment->departments()->pluck('departments.id')->all());
        UserGuard::assertNotLastAdmin($assignment);

        AuditLogger::record('users', 'remove_role', $target, ['role' => $role->key], null);
        $assignment->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, list<mixed>> */
    private function assignmentRules(): array
    {
        $orgId = AccessContext::current()->organizationId();

        return [
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('organization_id', $orgId)],
            'institution_ids' => ['nullable', 'array'],
            'institution_ids.*' => ['integer', 'distinct', Rule::exists('institutions', 'id')->where('organization_id', $orgId)],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Role, 1: list<int>, 2: list<int>}
     */
    private function resolveAssignment(array $data): array
    {
        $role = Role::query()->with('permissions')->findOrFail($data['role_id']);
        $institutionIds = array_map('intval', $data['institution_ids'] ?? []);
        $departmentIds = array_map('intval', $data['department_ids'] ?? []);

        // A role limited to institutions / departments is useless without them.
        $scopes = $role->permissions->pluck('pivot.scope')->unique();
        if ($scopes->contains(PermissionScope::Institutions->value) && $institutionIds === []) {
            throw ValidationException::withMessages(['institution_ids' => [__('validation.required', ['attribute' => 'institutions'])]]);
        }
        if ($scopes->contains(PermissionScope::Departments->value) && $departmentIds === []) {
            throw ValidationException::withMessages(['department_ids' => [__('validation.required', ['attribute' => 'departments'])]]);
        }

        return [$role, $institutionIds, $departmentIds];
    }

    /**
     * @param  list<int>  $institutionIds
     * @param  list<int>  $departmentIds
     */
    private function createAssignment(User $user, Role $role, array $institutionIds, array $departmentIds, int $actorId): void
    {
        $assignment = new RoleAssignment(['user_id' => $user->id, 'role_id' => $role->id, 'organization_id' => $role->organization_id]);
        $assignment->forceFill(['created_by' => $actorId])->save();
        $assignment->institutions()->sync($institutionIds);
        $assignment->departments()->sync($departmentIds);
    }

    private function loaded(User $user): User
    {
        return $user->load(['roleAssignments' => fn ($q) => $q->with(['role', 'institutions', 'departments'])]);
    }
}
