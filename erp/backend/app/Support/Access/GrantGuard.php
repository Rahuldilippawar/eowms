<?php

namespace App\Support\Access;

use App\Enums\PermissionScope;
use App\Models\Department;
use App\Models\Role;
use Illuminate\Validation\ValidationException;

/**
 * Prevents privilege escalation: nobody can give a role (or put into a role)
 * a permission they do not hold themselves, at a broader scope than their
 * own, or over institutions/departments outside their own reach.
 * Super admins are exempt.
 */
final class GrantGuard
{
    public function __construct(private readonly AccessContext $actor) {}

    public static function forCurrent(): self
    {
        $context = AccessContext::current();
        abort_if($context === null, 403);

        return new self($context);
    }

    /** May the actor put this permission at this scope into a role? */
    public function mayGrant(string $permission, PermissionScope $scope): bool
    {
        if ($this->actor->user()->is_super_admin) {
            return true;
        }
        $own = $this->actor->scope($permission);

        return $own !== null && $own->scope->breadth() >= $scope->breadth();
    }

    /**
     * @param  array<int, array{key: string, scope: PermissionScope}>  $grants
     *
     * @throws ValidationException
     */
    public function assertMayGrantAll(array $grants, string $field = 'permissions'): void
    {
        $refused = array_values(array_map(fn ($g) => $g['key'], array_filter($grants, fn ($g) => ! $this->mayGrant($g['key'], $g['scope']))));
        if ($refused !== []) {
            throw ValidationException::withMessages([$field => [__('access.cannot_grant', ['permissions' => implode(', ', $refused)])]]);
        }
    }

    /**
     * May the actor assign $role to someone, limited to these institutions /
     * departments? Every permission of the role must be grantable, and the
     * listed units must be inside the actor's own reach for those permissions.
     *
     * @param  list<int>  $institutionIds
     * @param  list<int>  $departmentIds
     *
     * @throws ValidationException
     */
    public function assertMayAssign(Role $role, array $institutionIds, array $departmentIds): void
    {
        $grants = $role->permissions->map(fn ($p) => ['key' => $p->key, 'scope' => PermissionScope::from($p->pivot->scope)])->all();
        $this->assertMayGrantAll($grants, 'role_id');

        if ($this->actor->user()->is_super_admin) {
            return;
        }

        foreach ($grants as $grant) {
            $own = $this->actor->scope($grant['key']);
            if ($own === null || $own->isOrganizationWide()) {
                continue;
            }
            $allowedInstitutions = $own->scope === PermissionScope::Institutions ? $own->institutionIds : [];
            $allowedDepartments = $own->scope === PermissionScope::Departments
                ? $own->departmentIds
                : Department::query()->whereIn('institution_id', $allowedInstitutions ?: [0])->pluck('id')->all();

            if (array_diff($institutionIds, $allowedInstitutions) !== [] || array_diff($departmentIds, $allowedDepartments) !== []) {
                throw ValidationException::withMessages(['institution_ids' => [__('access.outside_scope')]]);
            }
        }
    }
}
