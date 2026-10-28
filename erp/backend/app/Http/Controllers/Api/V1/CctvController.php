<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CctvCamera;
use App\Models\CctvCameraStatus;
use App\Models\CctvCheckup;
use App\Models\CctvDvr;
use App\Services\AttendanceRules;
use App\Services\Cctv\CctvImport;
use App\Services\Notifier;
use App\Support\Access\AccessContext;
use App\Support\Access\PermissionHolders;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CCTV (erp/docs/CCTV.md): recorders (DVRs), their cameras and the daily
 * check of each recorder — storage, footage kept, backup and every camera.
 */
class CctvController extends Controller
{
    /** A recorder not checked for longer than this many days is flagged. */
    public const LATE_AFTER_DAYS = 2;

    public function dashboard(): JsonResponse
    {
        $today = $this->today();
        $dvrs = CctvDvr::query()->where('is_active', true)->orderBy('name')
            ->withCount(['cameras' => fn ($q) => $q->where('is_active', true)])->get();
        // The latest check of each recorder by its date (a late entry for an earlier day does not count as the latest).
        $latest = $dvrs->mapWithKeys(fn (CctvDvr $d) => [$d->id => CctvCheckup::query()->where('dvr_id', $d->id)->orderByDesc('checkup_date')->first()])->filter();
        $statuses = CctvCameraStatus::query()->whereIn('checkup_id', $latest->pluck('id')->all() ?: [0])
            ->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status')->map(fn ($c) => (int) $c);

        return response()->json(['data' => [
            'dvrs' => $dvrs->map(function (CctvDvr $d) use ($latest, $today) {
                $last = $latest->get($d->id);
                $days = $last ? (int) CarbonImmutable::parse($last->checkup_date)->diffInDays(CarbonImmutable::parse($today)) : null;

                return $this->dvr($d) + [
                    'cameras_count' => $d->cameras_count,
                    'last_checkup' => $last ? ['date' => $last->checkup_date->toDateString(), 'storage_used_percent' => $last->storage_used_percent === null ? null : (float) $last->storage_used_percent,
                        'footage_days' => $last->footage_days, 'backup_done' => $last->backup_done] : null,
                    'days_since_checkup' => $days,
                    'late' => $days === null || $days > self::LATE_AFTER_DAYS,
                ];
            })->values(),
            'cameras' => ['working' => $statuses['working'] ?? 0, 'not_working' => $statuses['not_working'] ?? 0, 'needs_attention' => $statuses['needs_attention'] ?? 0],
            'checked_today' => CctvCheckup::query()->where('checkup_date', $today)->count(),
            'can_manage' => AccessContext::current()->can('cctv.manage'),
            'can_check' => AccessContext::current()->can('cctv.check') || AccessContext::current()->can('cctv.manage'),
        ]]);
    }

    public function show(CctvDvr $dvr): JsonResponse
    {
        $dvr->load(['department:id,name', 'cameras' => fn ($q) => $q->orderBy('name')]);
        $checkups = CctvCheckup::query()->where('dvr_id', $dvr->id)->with('checker:id,name')
            ->withCount(['statuses as not_working' => fn ($q) => $q->where('status', 'not_working'), 'statuses as needs_attention' => fn ($q) => $q->where('status', 'needs_attention')])
            ->orderByDesc('checkup_date')->limit(30)->get();

        return response()->json(['data' => $this->dvr($dvr) + [
            'cameras' => $dvr->cameras->map(fn (CctvCamera $c) => $c->only(['id', 'name', 'location', 'camera_type', 'is_active']))->values(),
            'checkups' => $checkups->map(fn (CctvCheckup $c) => [
                'id' => $c->id, 'date' => $c->checkup_date->toDateString(), 'storage_used_percent' => $c->storage_used_percent === null ? null : (float) $c->storage_used_percent,
                'footage_days' => $c->footage_days, 'backup_done' => $c->backup_done, 'backup_location' => $c->backup_location, 'remarks' => $c->remarks,
                'checked_by' => $c->checker?->name, 'not_working' => $c->not_working, 'needs_attention' => $c->needs_attention,
            ])->values(),
            'can_manage' => AccessContext::current()->can('cctv.manage'),
        ]]);
    }

    public function storeDvr(Request $request): JsonResponse
    {
        $dvr = CctvDvr::create($this->dvrData($request));
        AuditLogger::record('cctv', 'dvr_create', $dvr, null, AuditLogger::snapshot($dvr));

        return $this->show($dvr)->setStatusCode(201);
    }

    public function updateDvr(Request $request, CctvDvr $dvr): JsonResponse
    {
        $data = $this->dvrData($request, $dvr) + $request->validate(['is_active' => ['sometimes', 'boolean']]);
        $active = CctvCamera::query()->where('dvr_id', $dvr->id)->where('is_active', true)->count();
        if (isset($data['channel_capacity']) && $data['channel_capacity'] < $active) {
            throw ValidationException::withMessages(['channel_capacity' => [__('cctv.capacity_below_cameras', ['count' => $active])]]);
        }
        $before = $dvr->getAttributes();
        $dvr->fill($data)->save();
        AuditLogger::recordChanges('cctv', 'dvr_update', $dvr, $before);

        return $this->show($dvr);
    }

    public function storeCamera(Request $request, CctvDvr $dvr): JsonResponse
    {
        $data = $this->cameraData($request, $dvr);
        $this->assertRoom($dvr);
        $camera = CctvCamera::create($data + ['dvr_id' => $dvr->id]);
        AuditLogger::record('cctv', 'camera_create', $camera);

        return $this->show($dvr);
    }

    public function updateCamera(Request $request, CctvCamera $camera): JsonResponse
    {
        $dvr = CctvDvr::query()->findOrFail($camera->dvr_id);
        $data = $this->cameraData($request, $dvr, $camera) + $request->validate(['is_active' => ['sometimes', 'boolean']]);
        if (($data['is_active'] ?? true) && ! $camera->is_active) {
            $this->assertRoom($dvr);
        }
        $before = $camera->getAttributes();
        $camera->fill($data)->save();
        AuditLogger::recordChanges('cctv', 'camera_update', $camera, $before);

        return $this->show($dvr);
    }

    /** The day's sheet for a recorder: the saved check, or every active camera as working. */
    public function sheet(Request $request, CctvDvr $dvr): JsonResponse
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()]])['date'] ?? $this->today();
        $checkup = CctvCheckup::query()->where('dvr_id', $dvr->id)->where('checkup_date', $date)->with(['statuses', 'checker:id,name'])->first();
        $saved = $checkup?->statuses->keyBy('camera_id');
        $cameras = CctvCamera::query()->where('dvr_id', $dvr->id)->where('is_active', true)->orderBy('name')->get();

        return response()->json(['data' => [
            'dvr' => $this->dvr($dvr), 'date' => $date, 'saved' => $checkup !== null, 'checked_by' => $checkup?->checker?->name,
            'storage_used_percent' => $checkup?->storage_used_percent === null ? null : (float) $checkup->storage_used_percent,
            'footage_days' => $checkup?->footage_days, 'backup_done' => $checkup?->backup_done ?? false, 'backup_location' => $checkup?->backup_location, 'remarks' => $checkup?->remarks,
            'cameras' => $cameras->map(fn (CctvCamera $c) => ['id' => $c->id, 'name' => $c->name, 'location' => $c->location,
                'status' => $saved?->get($c->id)?->status ?? 'working', 'remarks' => $saved?->get($c->id)?->remarks])->values(),
        ]]);
    }

    /** Saving the same recorder and day again replaces it. Cameras not working are reported to the managers. */
    public function saveCheckup(Request $request, CctvDvr $dvr): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'storage_used_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'footage_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'backup_done' => ['required', 'boolean'],
            'backup_location' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'cameras' => ['present', 'array', 'max:512'],
            'cameras.*.camera_id' => ['required', 'integer', 'distinct', Rule::exists('cctv_cameras', 'id')->where('dvr_id', $dvr->id)],
            'cameras.*.status' => ['required', Rule::in(CctvCameraStatus::STATUSES)],
            'cameras.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);
        $checkup = DB::transaction(function () use ($dvr, $data, $request) {
            $checkup = CctvCheckup::query()->updateOrCreate(['dvr_id' => $dvr->id, 'checkup_date' => $data['date']], [
                'organization_id' => $dvr->organization_id, 'storage_used_percent' => $data['storage_used_percent'] ?? null, 'footage_days' => $data['footage_days'] ?? null,
                'backup_done' => $data['backup_done'], 'backup_location' => $data['backup_location'] ?? null, 'remarks' => $data['remarks'] ?? null, 'checked_by' => $request->user()->id,
            ]);
            $checkup->statuses()->delete();
            foreach ($data['cameras'] as $c) {
                $checkup->statuses()->create(['camera_id' => $c['camera_id'], 'status' => $c['status'], 'remarks' => $c['remarks'] ?? null]);
            }

            return $checkup;
        });
        $down = collect($data['cameras'])->where('status', 'not_working')->count();
        if ($down > 0) {
            foreach (PermissionHolders::userIds($dvr->organization_id, 'cctv.manage', true) as $userId) {
                Notifier::user($userId, $dvr->organization_id, 'cctv.cameras_down', ['dvr' => $dvr->name, 'count' => (string) $down, 'date' => $data['date']], "/cctv/dvrs/{$dvr->id}",
                    "cctv-down:{$checkup->id}");
            }
        }
        AuditLogger::record('cctv', 'checkup', $checkup, null, ['dvr' => $dvr->id, 'date' => $data['date'], 'not_working' => $down]);

        return $this->sheet(new Request(['date' => $data['date']]), $dvr);
    }

    /** Weekly or monthly: how many days each recorder was checked, backups, and camera results. */
    public function report(Request $request): JsonResponse
    {
        $period = $request->validate(['period' => ['nullable', Rule::in(['weekly', 'monthly'])]])['period'] ?? 'weekly';
        $days = $period === 'monthly' ? 30 : 7;
        $to = $this->today();
        $from = CarbonImmutable::parse($to)->subDays($days - 1)->toDateString();
        $rows = CctvDvr::query()->where('is_active', true)->orderBy('name')->get()->map(function (CctvDvr $d) use ($from, $to, $days) {
            $checkups = CctvCheckup::query()->where('dvr_id', $d->id)->whereBetween('checkup_date', [$from, $to])->get(['id', 'backup_done', 'storage_used_percent']);
            $statuses = CctvCameraStatus::query()->whereIn('checkup_id', $checkups->pluck('id')->all() ?: [0])->groupBy('status')
                ->selectRaw('status, count(*) as total')->pluck('total', 'status');

            return ['dvr' => $this->dvr($d), 'checkups' => $checkups->count(), 'compliance' => (int) round($checkups->count() / $days * 100),
                'backups' => $checkups->where('backup_done', true)->count(), 'max_storage' => $checkups->max('storage_used_percent') === null ? null : (float) $checkups->max('storage_used_percent'),
                'working' => (int) ($statuses['working'] ?? 0), 'not_working' => (int) ($statuses['not_working'] ?? 0), 'needs_attention' => (int) ($statuses['needs_attention'] ?? 0)];
        });

        return response()->json(['data' => ['period' => $period, 'from' => $from, 'to' => $to, 'days' => $days, 'dvrs' => $rows->values()]]);
    }

    public function importTemplate(): StreamedResponse
    {
        return Csv::download('cctv-import-template.csv', CctvImport::COLUMNS, [
            ['Main building DVR', 'Server room', '16', '4', '2026-01-15', 'Cam-01', 'Main gate', 'dome'],
            ['Main building DVR', 'Server room', '16', '4', '2026-01-15', 'Cam-02', 'Office', 'bullet'],
        ]);
    }

    public function importPreview(Request $request, CctvImport $import): JsonResponse
    {
        $checked = $import->check($this->rows($request));

        return response()->json(['data' => ['rows' => $checked, 'total' => count($checked), 'with_errors' => collect($checked)->filter(fn ($r) => $r['errors'] !== [])->count()]]);
    }

    public function import(Request $request, CctvImport $import): JsonResponse
    {
        $checked = $import->check($this->rows($request));
        $bad = collect($checked)->filter(fn ($r) => $r['errors'] !== [])->values();
        if ($bad->isNotEmpty()) {
            return response()->json(['message' => __('import.has_errors'), 'errors' => ['file' => [__('import.has_errors')]], 'rows' => $bad], 422);
        }
        $done = $import->import($checked);

        return response()->json(['data' => ['imported' => $done['cameras'], 'dvrs_created' => $done['dvrs']]], 201);
    }

    private function assertRoom(CctvDvr $dvr): void
    {
        $active = CctvCamera::query()->where('dvr_id', $dvr->id)->where('is_active', true)->count();
        if ($dvr->channel_capacity !== null && $active >= $dvr->channel_capacity) {
            throw ValidationException::withMessages(['name' => [__('cctv.dvr_full', ['capacity' => $dvr->channel_capacity])]]);
        }
    }

    /** @return list<array<string, string>> */
    private function rows(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);

        return CsvReader::rows($request->file('file'), CctvImport::REQUIRED, 2000);
    }

    /** @return array<string, mixed> */
    private function dvrData(Request $request, ?CctvDvr $dvr = null): array
    {
        $orgId = AccessContext::current()->organizationId();

        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('cctv_dvrs', 'name')->where('organization_id', $orgId)->ignore($dvr?->id)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'location' => ['nullable', 'string', 'max:255'],
            'channel_capacity' => ['nullable', 'integer', 'min:1', 'max:512'],
            'storage_tb' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'installed_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
        ]);
    }

    /** @return array<string, mixed> */
    private function cameraData(Request $request, CctvDvr $dvr, ?CctvCamera $camera = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('cctv_cameras', 'name')->where('dvr_id', $dvr->id)->ignore($camera?->id)],
            'location' => ['nullable', 'string', 'max:255'],
            'camera_type' => ['required', Rule::in(CctvCamera::TYPES)],
        ]);
    }

    /** @return array<string, mixed> */
    private function dvr(CctvDvr $d): array
    {
        return [
            'id' => $d->id, 'name' => $d->name, 'location' => $d->location, 'channel_capacity' => $d->channel_capacity,
            'storage_tb' => $d->storage_tb === null ? null : (float) $d->storage_tb, 'installed_date' => $d->installed_date?->toDateString(), 'is_active' => $d->is_active,
            'department' => $d->relationLoaded('department') && $d->department ? ['id' => $d->department->id, 'name' => $d->department->name] : null,
        ];
    }

    private function today(): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
    }
}
