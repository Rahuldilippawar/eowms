<?php

namespace App\Services\Assets;

use App\Models\AllocationItem;
use App\Models\AllocationType;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Allocation types and their items from CSV (the old allocations import): missing types are created. */
final class AllocationImport
{
    public const COLUMNS = ['type_name', 'item_name', 'default_location', 'is_loan'];

    public const REQUIRED = ['type_name', 'item_name'];

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>
     */
    public function check(array $rows): array
    {
        $types = AllocationType::query()->get(['id', 'name'])->keyBy(fn ($t) => mb_strtolower($t->name));
        $items = AllocationItem::query()->get(['type_id', 'name'])->map(fn ($i) => $i->type_id.'|'.mb_strtolower($i->name))->flip();
        $seen = [];
        $result = [];
        foreach ($rows as $i => $row) {
            $errors = [];
            $type = trim($row['type_name'] ?? '');
            $name = trim($row['item_name'] ?? '');
            if ($type === '' || mb_strlen($type) > 255) {
                $errors['type_name'] = __('validation.required', ['attribute' => 'type_name']);
            }
            if ($name === '' || mb_strlen($name) > 255) {
                $errors['item_name'] = __('validation.required', ['attribute' => 'item_name']);
            }
            $loan = mb_strtolower(trim($row['is_loan'] ?? ''));
            if (! in_array($loan, ['', 'yes', 'no', '1', '0', 'हो', 'नाही'], true)) {
                $errors['is_loan'] = __('assets.yes_or_no');
            }
            $existingType = $types->get(mb_strtolower($type));
            $key = mb_strtolower($type).'|'.mb_strtolower($name);
            if ($name !== '' && (isset($seen[$key]) || ($existingType && isset($items[$existingType->id.'|'.mb_strtolower($name)])))) {
                $errors['item_name'] = __('assets.duplicate_item');
            }
            $seen[$key] = true;
            $result[] = ['line' => $i + 2, 'data' => [
                'type_name' => $type, 'item_name' => $name, 'default_location' => trim($row['default_location'] ?? '') ?: null,
                'is_loan' => in_array($loan, ['yes', '1', 'हो'], true),
            ], 'errors' => $errors];
        }

        return $result;
    }

    /** @param  list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>  $checked */
    public function import(array $checked): int
    {
        return DB::transaction(function () use ($checked) {
            $types = [];
            foreach ($checked as $row) {
                $d = $row['data'];
                $key = mb_strtolower($d['type_name']);
                $types[$key] ??= AllocationType::query()->whereRaw('lower(name) = ?', [$key])->first()
                    ?? AllocationType::create(['name' => $d['type_name'], 'is_loan' => $d['is_loan'], 'fields' => []]);
                AllocationItem::create(['type_id' => $types[$key]->id, 'name' => $d['item_name'], 'default_location' => $d['default_location']]);
            }
            AuditLogger::record('assets', 'allocation_import', null, null, ['rows' => count($checked)]);

            return count($checked);
        });
    }
}
