<?php

namespace App\Services\Hostels;

use App\Models\Hostel;
use App\Models\HostelResident;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees and looks after which hostel (erp/docs/HOSTELS.md):
 * - hostels.view — hostels of the departments in scope;
 * - hostels.care — residents, roll calls, out-passes, health, fees there;
 * - hostels.manage — hostels, rooms and staff there (and everything above);
 * - the hostel's own staff (wardens) look after its residents without any of these.
 */
final class HostelAccess
{
    /** @return Builder<Hostel> */
    public function visible(): Builder
    {
        $ctx = AccessContext::current();
        $me = $ctx->user()->id;
        $ids = ScopeDepartments::ids($ctx->can('hostels.view') ? $ctx->scope('hostels.view') : null);
        if ($ids === null) {
            return Hostel::query();
        }

        return Hostel::query()->where(fn ($w) => $w->whereIn('department_id', $ids ?: [0])
            ->orWhereIn('id', fn ($s) => $s->select('hostel_id')->from('hostel_staff')->where('user_id', $me)));
    }

    public function isStaff(Hostel $hostel): bool
    {
        return $hostel->staff()->where('user_id', AccessContext::current()->user()->id)->exists();
    }

    public function canManage(Hostel $hostel): bool
    {
        return ScopeDepartments::reaches(AccessContext::current()->scope('hostels.manage'), $hostel->department_id);
    }

    public function canCare(Hostel $hostel): bool
    {
        return $this->isStaff($hostel) || ScopeDepartments::reaches(AccessContext::current()->scope('hostels.care'), $hostel->department_id) || $this->canManage($hostel);
    }

    public function hostel(int $id): Hostel
    {
        return $this->visible()->findOrFail($id);
    }

    public function cared(int $id): Hostel
    {
        $hostel = $this->hostel($id);
        abort_unless($this->canCare($hostel), 403, __('auth.forbidden'));

        return $hostel;
    }

    public function managed(int $id): Hostel
    {
        $hostel = $this->hostel($id);
        abort_unless($this->canManage($hostel), 403, __('auth.forbidden'));

        return $hostel;
    }

    /** A resident of a hostel the viewer looks after. */
    public function resident(int $id): HostelResident
    {
        $resident = HostelResident::query()->findOrFail($id);
        $this->cared($resident->hostel_id);

        return $resident;
    }
}
