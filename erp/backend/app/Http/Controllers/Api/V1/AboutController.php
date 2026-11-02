<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionScope;
use App\Http\Controllers\Controller;
use App\Models\AboutEntry;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The organization's About page (erp/docs/ABOUT-AND-TV.md): its revered figures and guides, and the
 * people who contributed. Everyone in the organization reads it; organization-wide admins edit it.
 */
class AboutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $manage = $this->canManage();
        $entries = AboutEntry::query()->when(! $manage || ! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->orderByRaw("FIELD(kind, 'deity', 'sadguru', 'guide', 'contributor')")->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $entries->map(fn (AboutEntry $e) => $this->row($e))->values(), 'meta' => ['can_manage' => $manage]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $entry = AboutEntry::create($this->validated($request));
        $this->savePhoto($request, $entry);
        AuditLogger::record('about', 'create', $entry, null, AuditLogger::snapshot($entry));

        return response()->json(['data' => $this->row($entry->fresh())], 201);
    }

    public function update(Request $request, AboutEntry $entry): JsonResponse
    {
        $this->authorizeManage();
        $before = $entry->getAttributes();
        $entry->fill($this->validated($request))->save();
        $this->savePhoto($request, $entry);
        AuditLogger::recordChanges('about', 'update', $entry, $before);

        return response()->json(['data' => $this->row($entry->fresh())]);
    }

    public function destroy(AboutEntry $entry): JsonResponse
    {
        $this->authorizeManage();
        if ($entry->photo_path) {
            Storage::disk('local')->delete($entry->photo_path);
        }
        $entry->delete();
        AuditLogger::record('about', 'delete', $entry, ['title' => $entry->title]);

        return response()->json(null, 204);
    }

    public function photo(AboutEntry $entry): StreamedResponse
    {
        abort_if($entry->photo_path === null || ! Storage::disk('local')->exists($entry->photo_path), 404);

        return Storage::disk('local')->response($entry->photo_path, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(AboutEntry::KINDS)],
            'title' => ['required', 'string', 'max:150'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:3072'],
        ]);
        unset($data['photo']);

        return $data + ['sort_order' => $data['sort_order'] ?? 0];
    }

    private function savePhoto(Request $request, AboutEntry $entry): void
    {
        if (! $request->hasFile('photo')) {
            return;
        }
        $old = $entry->photo_path;
        $path = $request->file('photo')->storeAs("about/{$entry->organization_id}", Str::uuid().'.'.$request->file('photo')->guessExtension(), 'local');
        $entry->forceFill(['photo_path' => $path])->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }
    }

    /** @return array<string, mixed> */
    private function row(AboutEntry $e): array
    {
        return ['id' => $e->id, 'kind' => $e->kind, 'title' => $e->title, 'subtitle' => $e->subtitle, 'body' => $e->body, 'sort_order' => $e->sort_order,
            'is_active' => $e->is_active, 'has_photo' => $e->photo_path !== null, 'updated_at' => $e->updated_at?->toIso8601String()];
    }

    private function canManage(): bool
    {
        return AccessContext::current()->scope('organizations.edit')?->scope === PermissionScope::Organization;
    }

    private function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403, __('auth.forbidden'));
    }
}
