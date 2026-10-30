<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionScope;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AttendanceRules;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Activity and sign-in log viewer (erp/docs/AUDIT.md). Entries span every
 * department, so viewing needs organization-wide `audit.view`.
 */
class AuditLogController extends Controller
{
    /** Sign-in related actions shown under login activity. */
    public const LOGIN_ACTIONS = ['login', 'login_failed', 'logout', 'password_changed', 'password_reset_requested', 'password_reset'];

    public const EXPORT_LIMIT = 10000;

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $f = $this->filters($request);
        $query = $this->organizationRows()
            ->when($f['module'] ?? null, fn ($q, $m) => $q->where('module', $m))
            ->when($f['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($f['subject_id'] ?? null, fn ($q, $id) => $q->where('auditable_id', $id));
        $query = $this->common($query, $f);

        if (($f['format'] ?? null) === 'csv') {
            return $this->export('activity-log.csv', $query, 'activity_export', $f);
        }

        $page = $query->paginate(50)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $l) => $this->row($l, true))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
                'modules' => $this->organizationRows()->distinct()->orderBy('module')->pluck('module'),
                'can_export' => AccessContext::current()->can('audit.export')],
        ]);
    }

    public function logins(Request $request): JsonResponse|StreamedResponse
    {
        $f = $this->filters($request);
        $query = $this->loginRows()->when($f['action'] ?? null, fn ($q, $a) => $q->where('action', $a));
        $query = $this->common($query, $f);

        if (($f['format'] ?? null) === 'csv') {
            return $this->export('login-activity.csv', $query, 'login_export', $f);
        }

        $page = $query->paginate(50)->withQueryString();
        $since = now()->subDays(7);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $l) => $this->row($l, false))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
                'summary' => [
                    'logins_7d' => $this->loginRows()->where('action', 'login')->where('created_at', '>=', $since)->count(),
                    'failed_7d' => $this->loginRows()->where('action', 'login_failed')->where('created_at', '>=', $since)->count(),
                    'users_7d' => $this->loginRows()->where('action', 'login')->where('created_at', '>=', $since)->distinct()->count('user_id'),
                ],
                'can_export' => AccessContext::current()->can('audit.export')],
        ]);
    }

    /** People who appear in this organization's log, for the "who" filter. */
    public function users(): JsonResponse
    {
        $this->authorizeView();

        return response()->json(['data' => User::query()->whereIn('id', $this->members())->orderBy('name')->get(['id', 'name'])]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $this->authorizeView();

        return $request->validate([
            'user_id' => ['nullable', 'integer'],
            'module' => ['nullable', 'string', 'max:50'],
            'action' => ['nullable', 'string', 'max:50'],
            'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
    }

    private function authorizeView(): void
    {
        abort_unless(AccessContext::current()->scope('audit.view')?->scope === PermissionScope::Organization, 403, __('auth.forbidden'));
    }

    /** @return Builder<AuditLog> */
    private function organizationRows(): Builder
    {
        return AuditLog::query()->where('organization_id', AccessContext::current()->organizationId());
    }

    /**
     * Sign-ins happen before an organization is chosen, so those rows carry no
     * organization: they are shown for the people who belong to this one.
     *
     * @return Builder<AuditLog>
     */
    private function loginRows(): Builder
    {
        $orgId = AccessContext::current()->organizationId();

        return AuditLog::query()->where('module', 'auth')->whereIn('action', self::LOGIN_ACTIONS)
            ->where(fn ($w) => $w->where('organization_id', $orgId)
                ->orWhere(fn ($n) => $n->whereNull('organization_id')->whereIn('user_id', $this->members())));
    }

    private function members(): \Illuminate\Database\Query\Builder
    {
        return DB::table('role_assignments')->where('organization_id', AccessContext::current()->organizationId())->select('user_id');
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $f
     * @return Builder<AuditLog>
     */
    private function common(Builder $query, array $f): Builder
    {
        // Dates are the organization's days; rows are stored in UTC.
        $tz = AttendanceRules::for(AccessContext::current()->organization())->timezone;

        return $query->with('user:id,name')
            ->when($f['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', CarbonImmutable::parse($d, $tz)->startOfDay()->utc()))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', CarbonImmutable::parse($d, $tz)->addDay()->startOfDay()->utc()))
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return array<string, mixed> */
    private function row(AuditLog $l, bool $values): array
    {
        return [
            'id' => $l->id,
            'created_at' => $l->created_at?->toIso8601String(),
            'user' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name] : null,
            'module' => $l->module,
            'action' => $l->action,
            'subject' => $l->auditable_type ? ['type' => class_basename($l->auditable_type), 'id' => $l->auditable_id] : null,
            'reason' => $l->reason,
            'ip_address' => $l->ip_address,
            'user_agent' => $l->user_agent,
        ] + ($values ? ['old_values' => $l->old_values, 'new_values' => $l->new_values] : []);
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $f
     */
    private function export(string $filename, Builder $query, string $action, array $f): StreamedResponse
    {
        abort_unless(AccessContext::current()->can('audit.export'), 403, __('auth.forbidden'));
        $rows = $query->limit(self::EXPORT_LIMIT)->get();
        AuditLogger::record('audit', $action, null, null, ['rows' => $rows->count(), 'filters' => array_diff_key($f, ['format' => 1])]);

        return Csv::download($filename, ['time_utc', 'user', 'module', 'action', 'subject', 'subject_id', 'reason', 'ip_address', 'changes'],
            $rows->map(fn (AuditLog $l) => [
                $l->created_at?->toDateTimeString(), $l->user?->name, $l->module, $l->action,
                $l->auditable_type ? class_basename($l->auditable_type) : null, $l->auditable_id, $l->reason, $l->ip_address,
                $l->new_values === null ? null : json_encode(['old' => $l->old_values, 'new' => $l->new_values], JSON_UNESCAPED_UNICODE),
            ]));
    }
}
