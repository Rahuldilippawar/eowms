<?php

namespace App\Services\Assets;

use App\Models\AllocationItem;
use App\Models\AllocationType;
use App\Models\AssetAllocation;
use App\Models\Employee;
use App\Services\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who holds which item (erp/docs/ASSETS.md): an item is with one person at
 * a time; a transfer closes one allocation and opens the next.
 */
final class AllocationDesk
{
    public const FIELD_TYPES = ['text', 'number', 'date', 'dropdown', 'yesno'];

    /**
     * Values for the type's own fields: required ones must be filled, dropdowns take one of their options.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function custom(AllocationType $type, array $values): array
    {
        $clean = [];
        foreach ($type->fields ?? [] as $field) {
            $value = trim((string) ($values[$field['key']] ?? ''));
            if ($value === '' && ! empty($field['required'])) {
                throw ValidationException::withMessages(["custom.{$field['key']}" => [__('assets.field_required', ['field' => $field['label']])]]);
            }
            if ($value !== '' && $field['type'] === 'dropdown' && ! in_array($value, $field['options'] ?? [], true)) {
                throw ValidationException::withMessages(["custom.{$field['key']}" => [__('assets.field_option', ['field' => $field['label']])]]);
            }
            if ($value !== '' && $field['type'] === 'number' && ! is_numeric($value)) {
                throw ValidationException::withMessages(["custom.{$field['key']}" => [__('assets.field_number', ['field' => $field['label']])]]);
            }
            $clean[$field['key']] = $value;
        }

        return $clean;
    }

    /** @param  array<string, mixed>  $attributes  custom, purpose, allocated_at, due_at, transferred_from_id */
    public function allocate(AllocationItem $item, int $userId, array $attributes, int $byUserId): AssetAllocation
    {
        if (! Employee::query()->where('user_id', $userId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['user_id' => [__('letters.choose_recipient')]]);
        }

        return DB::transaction(function () use ($item, $userId, $attributes, $byUserId) {
            AllocationItem::query()->whereKey($item->id)->lockForUpdate()->first();
            $holder = AssetAllocation::query()->where('item_id', $item->id)->whereNull('returned_at')->with('user:id,name')->first();
            if ($holder !== null) {
                throw ValidationException::withMessages(['item_id' => [__('assets.item_busy', ['item' => $item->name, 'name' => $holder->user?->name])]]);
            }
            $allocation = AssetAllocation::create($attributes + [
                'organization_id' => $item->organization_id, 'type_id' => $item->type_id, 'item_id' => $item->id, 'user_id' => $userId, 'allocated_by' => $byUserId,
            ]);
            $due = $allocation->due_at?->toIso8601String();
            Notifier::user($userId, $item->organization_id, 'assets.allocated', ['item' => $item->name, 'due' => $due ?? ''], '/my/assets');

            return $allocation;
        });
    }

    public function giveBack(AssetAllocation $allocation, ?string $reason, int $byUserId, ?Carbon $at = null): void
    {
        if ($allocation->returned_at !== null) {
            throw ValidationException::withMessages(['allocation' => [__('assets.already_returned')]]);
        }
        $allocation->forceFill(['returned_at' => $at ?? now(), 'returned_to' => $byUserId, 'return_reason' => $reason])->save();
    }

    public function transfer(AssetAllocation $allocation, int $toUserId, ?string $reason, int $byUserId): AssetAllocation
    {
        if ($allocation->user_id === $toUserId) {
            throw ValidationException::withMessages(['user_id' => [__('assets.same_person')]]);
        }

        return DB::transaction(function () use ($allocation, $toUserId, $reason, $byUserId) {
            $this->giveBack($allocation, $reason, $byUserId);
            $item = AllocationItem::query()->findOrFail($allocation->item_id);

            return $this->allocate($item, $toUserId, [
                'custom' => $allocation->custom, 'purpose' => $allocation->purpose, 'allocated_at' => now(), 'due_at' => $allocation->due_at,
                'transferred_from_id' => $allocation->id,
            ], $byUserId);
        });
    }
}
