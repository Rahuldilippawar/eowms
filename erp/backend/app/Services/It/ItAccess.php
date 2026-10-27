<?php

namespace App\Services\It;

use App\Models\ItLab;
use App\Support\Access\AccessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reaches what in the IT department (erp/docs/IT.md): it.view sees every
 * lab, it.manage runs them; a lab's assistant sees and keeps up to date the
 * assets of their own labs.
 */
final class ItAccess
{
    public function canViewAll(): bool
    {
        return AccessContext::current()->can('it.view') || $this->canManage();
    }

    public function canManage(): bool
    {
        return AccessContext::current()->can('it.manage');
    }

    /** @return list<int>|null lab ids the user may see; null = all */
    public function labIds(): ?array
    {
        if ($this->canViewAll()) {
            return null;
        }

        return ItLab::query()->where('assistant_user_id', AccessContext::current()->user()->id)->where('is_active', true)->pluck('id')->all();
    }

    /** @param  Builder<Model>  $query  a query with a lab_id column */
    public function scope(Builder $query, string $column = 'lab_id'): Builder
    {
        $ids = $this->labIds();

        return $query->when($ids !== null, fn ($q) => $q->whereIn($column, $ids ?: [0]));
    }

    public function canKeepLab(int $labId): bool
    {
        return $this->canManage() || ItLab::query()->whereKey($labId)->where('assistant_user_id', AccessContext::current()->user()->id)->exists();
    }

    public function isAssistant(): bool
    {
        return ItLab::query()->where('assistant_user_id', AccessContext::current()->user()->id)->where('is_active', true)->exists();
    }
}
