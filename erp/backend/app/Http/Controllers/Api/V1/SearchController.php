<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ItAsset;
use App\Models\Letter;
use App\Models\RenewalItem;
use App\Models\StoreItem;
use App\Models\Student;
use App\Models\Vehicle;
use App\Services\Correspondence\LetterService;
use App\Services\It\ItAccess;
use App\Services\Vehicles\VehicleBook;
use App\Support\Access\AccessContext;
use App\Support\Access\Modules;
use App\Support\Access\ScopeDepartments;
use App\Support\Queries\PeopleQueries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global search (erp/docs/SEARCH.md). Each group reuses its module's own
 * visibility rules, so a result never shows what the module's pages would not.
 */
class SearchController extends Controller
{
    public const PER_GROUP = 5;

    public function __invoke(Request $request, VehicleBook $vehicles, ItAccess $it): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $like = '%'.addcslashes(trim($data['q']), '%_\\').'%';
        $ctx = AccessContext::current();
        $off = Modules::disabled($ctx->organization());
        $groups = [];

        if ($ctx->can('employees.view')) {
            $groups['employees'] = $this->take(PeopleQueries::employees()->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('employee_code', 'like', $like)),
                'name', fn (Employee $e) => ['id' => $e->id, 'title' => $e->name, 'subtitle' => trim(($e->employee_code ?? '').' · '.($e->designation ?? ''), ' ·'), 'url' => "/employees/{$e->id}"]);
        }
        if ($ctx->can('students.view')) {
            $groups['students'] = $this->take(PeopleQueries::students()->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('student_code', 'like', $like)
                ->orWhere('admission_no', 'like', $like)->orWhere('roll_no', 'like', $like)),
                'name', fn (Student $s) => ['id' => $s->id, 'title' => $s->name, 'subtitle' => trim(($s->student_code ?? '').' · '.($s->class_name ?? ''), ' ·'), 'url' => '/students?search='.rawurlencode($s->name)]);
        }
        if (! in_array('letters', $off, true)) {
            $groups['letters'] = $this->take(LetterService::visible($ctx->user())->where(fn ($w) => $w->where('subject', 'like', $like)->orWhere('file_number', 'like', $like)
                ->orWhere('outward_no', 'like', $like)->orWhere('reference_no', 'like', $like)),
                'updated_at', fn (Letter $l) => ['id' => $l->id, 'title' => $l->subject, 'subtitle' => $l->file_number ?? $l->outward_no, 'url' => "/letters/{$l->id}"], true);
        }
        if (! in_array('vehicles', $off, true)) {
            $groups['vehicles'] = $this->take($vehicles->visible()->where(fn ($w) => $w->where('vehicle_number', 'like', $like)->orWhere('model', 'like', $like)),
                'vehicle_number', fn (Vehicle $v) => ['id' => $v->id, 'title' => $v->vehicle_number, 'subtitle' => $v->model, 'url' => "/vehicles/{$v->id}"]);
        }
        if ($ctx->can('renewals.view')) {
            $ids = ScopeDepartments::ids($ctx->scope('renewals.view'));
            $groups['renewals'] = $this->take(RenewalItem::query()->when($ids !== null, fn ($q) => $q->whereIn('department_id', $ids ?: [0]))
                ->where(fn ($w) => $w->where('item_name', 'like', $like)->orWhere('reference_no', 'like', $like)),
                'item_name', fn (RenewalItem $r) => ['id' => $r->id, 'title' => $r->item_name, 'subtitle' => $r->expiry_date?->toDateString(), 'url' => "/renewals/{$r->id}"]);
        }
        if ($ctx->can('store.view')) {
            $groups['store'] = $this->take(StoreItem::query()->where('item_name', 'like', $like),
                'item_name', fn (StoreItem $s) => ['id' => $s->id, 'title' => $s->item_name, 'subtitle' => $s->category, 'url' => "/store/items/{$s->id}"]);
        }
        if (! in_array('it', $off, true)) {
            $groups['it'] = $this->take($it->scope(ItAsset::query())->where(fn ($w) => $w->where('asset_tag', 'like', $like)->orWhere('serial_no', 'like', $like)),
                'asset_tag', fn (ItAsset $a) => ['id' => $a->id, 'title' => $a->asset_tag, 'subtitle' => trim("{$a->asset_type} {$a->brand} {$a->model}"), 'url' => "/it/assets/{$a->id}"]);
        }

        return response()->json(['data' => array_filter($groups, fn ($rows) => $rows !== [])]);
    }

    /** @return list<array<string, mixed>> */
    private function take(Builder $query, string $order, callable $map, bool $latestFirst = false): array
    {
        return $query->orderBy($order, $latestFirst ? 'desc' : 'asc')->limit(self::PER_GROUP)->get()->map($map)->values()->all();
    }
}
