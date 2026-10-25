<?php

namespace App\Support\Access;

use App\Enums\PermissionScope;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is acting, in which organization, and what they may do there.
 * Built once per request by the SetAccessContext middleware.
 */
final class AccessContext
{
    /** @var array<string, ResolvedScope>|null */
    private ?array $grants = null;

    public function __construct(
        private readonly User $user,
        private readonly ?Organization $organization,
    ) {}

    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }

    public static function set(?self $context): void
    {
        if ($context === null) {
            app()->forgetInstance(self::class);
            app()->offsetUnset(self::class);

            return;
        }
        app()->instance(self::class, $context);
    }

    public function user(): User
    {
        return $this->user;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    /** The current organization's id, or 0 when the user has none (matches no rows). */
    public function organizationId(): int
    {
        return $this->organization?->id ?? 0;
    }

    public function can(string $permission): bool
    {
        return $this->scope($permission) !== null;
    }

    /** Null when the user does not hold the permission in this organization. */
    public function scope(string $permission): ?ResolvedScope
    {
        if ($this->organization === null || ! Modules::permissionAllowed($this->organization, $permission)) {
            return null;
        }
        if ($this->user->is_super_admin) {
            return new ResolvedScope(PermissionScope::Organization, $this->user->id);
        }

        return $this->grants()[$permission] ?? null;
    }

    /** @return list<string> permission keys held in the current organization */
    public function permissionKeys(): array
    {
        if ($this->organization === null) {
            return [];
        }
        $keys = $this->user->is_super_admin ? DB::table('permissions')->orderBy('key')->pluck('key')->all() : array_keys($this->grants());

        // Permissions of modules the organization switched off count as not held.
        return array_values(array_filter($keys, fn (string $key) => Modules::permissionAllowed($this->organization, $key)));
    }

    /**
     * Organizations the user may switch into: active organizations where they
     * hold a role, or every active organization for a super admin.
     *
     * @return Collection<int, Organization>
     */
    public static function organizationsFor(User $user): Collection
    {
        $query = Organization::query()->where('is_active', true)->orderBy('name');
        if (! $user->is_super_admin) {
            $query->whereIn('id', DB::table('role_assignments')->where('user_id', $user->id)->select('organization_id'));
        }

        return $query->get();
    }

    /** @return array<string, ResolvedScope> */
    private function grants(): array
    {
        if ($this->grants !== null) {
            return $this->grants;
        }

        $rows = DB::table('role_assignments as ra')
            ->join('role_permission as rp', 'rp.role_id', '=', 'ra.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ra.user_id', $this->user->id)
            ->where('ra.organization_id', $this->organization->id)
            ->get(['ra.id as assignment_id', 'p.key', 'rp.scope']);

        $assignmentIds = $rows->pluck('assignment_id')->unique()->all();
        $institutionsByAssignment = DB::table('role_assignment_institution')->whereIn('role_assignment_id', $assignmentIds)
            ->get()->groupBy('role_assignment_id')->map(fn ($g) => $g->pluck('institution_id')->map(fn ($id) => (int) $id)->all());
        $departmentsByAssignment = DB::table('role_assignment_department')->whereIn('role_assignment_id', $assignmentIds)
            ->get()->groupBy('role_assignment_id')->map(fn ($g) => $g->pluck('department_id')->map(fn ($id) => (int) $id)->all());

        $grants = [];
        foreach ($rows->groupBy('key') as $key => $keyRows) {
            // The broadest scope wins; ids are unioned across assignments with that scope.
            $best = $keyRows->map(fn ($r) => PermissionScope::from($r->scope))->sortByDesc(fn ($s) => $s->breadth())->first();
            $winning = $keyRows->filter(fn ($r) => PermissionScope::from($r->scope)->breadth() === $best->breadth());

            $institutionIds = $winning->flatMap(fn ($r) => $institutionsByAssignment[$r->assignment_id] ?? [])->unique()->values()->all();
            $departmentIds = $winning->flatMap(fn ($r) => $departmentsByAssignment[$r->assignment_id] ?? [])->unique()->values()->all();

            $grants[$key] = new ResolvedScope($best, $this->user->id, $institutionIds, $departmentIds);
        }

        return $this->grants = $grants;
    }
}
