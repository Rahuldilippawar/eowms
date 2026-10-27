<?php

namespace App\Services\Store;

use App\Models\StockMovement;
use App\Models\StoreItem;
use App\Services\Notifier;
use App\Support\Access\PermissionHolders;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The store's stock (erp/docs/STORE.md): every movement is a signed row and
 * an item's balance is their sum. Nothing may take the balance below zero.
 */
final class StockLedger
{
    public function balance(int $itemId): float
    {
        return round((float) StockMovement::query()->where('item_id', $itemId)->sum('quantity'), 2);
    }

    /**
     * Records one movement under a lock on the item, so two issues cannot
     * both take the last of it.
     *
     * @param  array<string, mixed>  $attributes  movement_date, department_id, issued_to, unit_cost, remarks, source_type, source_id
     */
    public function move(StoreItem $item, string $type, float $quantity, array $attributes, int $userId): StockMovement
    {
        return DB::transaction(function () use ($item, $type, $quantity, $attributes, $userId) {
            StoreItem::query()->whereKey($item->id)->lockForUpdate()->first();
            $before = $this->balance($item->id);
            $signed = round(match ($type) {
                'receive' => abs($quantity),
                'issue' => -abs($quantity),
                default => $quantity,
            }, 2);
            if ($signed == 0.0) {
                throw ValidationException::withMessages(['quantity' => [__('store.quantity_zero')]]);
            }
            if ($before + $signed < -0.001) {
                throw ValidationException::withMessages(['quantity' => [__('store.not_enough', ['balance' => rtrim(rtrim(number_format($before, 2, '.', ''), '0'), '.'), 'unit' => $item->unit])]]);
            }
            $movement = StockMovement::create($attributes + [
                'organization_id' => $item->organization_id, 'item_id' => $item->id, 'movement_type' => $type, 'quantity' => $signed, 'created_by' => $userId,
            ]);
            $after = round($before + $signed, 2);
            // Tell the store once, when an issue or adjustment takes the item to its reorder level.
            if ($signed < 0 && (float) $item->reorder_level > 0 && $before > (float) $item->reorder_level && $after <= (float) $item->reorder_level) {
                foreach (PermissionHolders::userIds($item->organization_id, 'store.manage', true) as $id) {
                    Notifier::user($id, $item->organization_id, 'store.low_stock', ['item' => $item->item_name, 'balance' => (string) $after, 'unit' => $item->unit], "/store/items/{$item->id}");
                }
            }

            return $movement;
        });
    }
}
