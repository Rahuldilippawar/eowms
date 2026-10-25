<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in user's own notifications in the current organization. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['unread' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $this->mine($request)
            ->when($f['unread'] ?? false, fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate($f['per_page'] ?? 20, ['id', 'type', 'data', 'link', 'read_at', 'created_at'])
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (UserNotification $n) => [
                'id' => $n->id, 'type' => $n->type, 'data' => $n->data ?? (object) [], 'link' => $n->link,
                'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'unread' => $this->mine($request)->whereNull('read_at')->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $this->mine($request)->whereNull('read_at')->count()]]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $updated = $this->mine($request)->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);
        abort_if($updated === 0 && ! $this->mine($request)->whereKey($id)->exists(), 404);

        return $this->unreadCount($request);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->mine($request)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->unreadCount($request);
    }

    /** @return Builder<UserNotification> */
    private function mine(Request $request): Builder
    {
        return UserNotification::query()->where('user_id', $request->user()->id);
    }
}
