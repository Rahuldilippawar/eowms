<?php

namespace App\Services\Assets;

use App\Models\AssetItemMaster;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** The asset item master from CSV: every row checked first, then all rows or none. */
final class ItemMasterImport
{
    public const COLUMNS = ['item_name', 'category', 'default_unit'];

    public const REQUIRED = ['item_name'];

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>
     */
    public function check(array $rows): array
    {
        $existing = AssetItemMaster::query()->pluck('item_name')->map(fn ($n) => mb_strtolower($n))->flip();
        $seen = [];
        $result = [];
        foreach ($rows as $i => $row) {
            $errors = [];
            $v = Validator::make($row, ['item_name' => ['required', 'max:255'], 'category' => ['nullable', 'max:100'], 'default_unit' => ['nullable', 'max:30']]);
            foreach ($v->errors()->messages() as $field => $messages) {
                $errors[$field] = $messages[0];
            }
            $name = trim($row['item_name'] ?? '');
            $key = mb_strtolower($name);
            if ($name !== '' && (isset($existing[$key]) || isset($seen[$key]))) {
                $errors['item_name'] = __('assets.duplicate_item');
            }
            $seen[$key] = true;
            $result[] = ['line' => $i + 2, 'data' => [
                'item_name' => $name, 'category' => trim($row['category'] ?? '') ?: null, 'default_unit' => trim($row['default_unit'] ?? '') ?: null,
            ], 'errors' => $errors];
        }

        return $result;
    }

    /** @param  list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>  $checked */
    public function import(array $checked): int
    {
        return DB::transaction(function () use ($checked) {
            foreach ($checked as $row) {
                AssetItemMaster::create($row['data']);
            }
            AuditLogger::record('assets', 'item_master_import', null, null, ['rows' => count($checked)]);

            return count($checked);
        });
    }
}
