<?php

namespace App\Support\Access;

use App\Enums\PermissionScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The effective reach of one permission for one user in one organization,
 * after combining all of the user's roles.
 */
final class ResolvedScope
{
    /**
     * @param  list<int>  $institutionIds  institutions on the user's assignments (Institutions scope)
     * @param  list<int>  $departmentIds  departments on the user's assignments (Departments scope)
     */
    public function __construct(
        public readonly PermissionScope $scope,
        public readonly int $userId,
        public readonly array $institutionIds = [],
        public readonly array $departmentIds = [],
    ) {}

    public function isOrganizationWide(): bool
    {
        return $this->scope === PermissionScope::Organization;
    }

    /**
     * Limits a query to the records this scope reaches. `$columns` tells which
     * columns of the queried table hold each kind of reference; a scope whose
     * column is not given matches nothing (deny by default).
     *
     * @param  array{institution?: string, department?: string, owner?: string, assignee?: string}  $columns
     */
    public function apply(Builder $query, array $columns): Builder
    {
        return match ($this->scope) {
            PermissionScope::Organization => $query,
            PermissionScope::Institutions => $this->whereIn($query, $columns['institution'] ?? null, $this->institutionIds),
            PermissionScope::Departments => $this->whereIn($query, $columns['department'] ?? null, $this->departmentIds),
            PermissionScope::Own => $this->whereIn($query, $columns['owner'] ?? null, [$this->userId]),
            PermissionScope::Assigned => $this->whereIn($query, $columns['assignee'] ?? null, [$this->userId]),
        };
    }

    /** @param  list<int>  $ids */
    private function whereIn(Builder $query, ?string $column, array $ids): Builder
    {
        if ($column === null || $ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->qualifyColumn($column), $ids);
    }
}
