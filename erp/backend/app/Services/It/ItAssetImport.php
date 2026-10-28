<?php

namespace App\Services\It;

use App\Models\ItAsset;
use App\Models\ItLab;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\Dates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** IT assets from CSV (the old IT import): missing labs are created; every row checked first, then all rows or none. */
final class ItAssetImport
{
    public const COLUMNS = ['lab_name', 'location', 'capacity', 'workstation_no', 'asset_type', 'brand', 'model', 'serial_no',
        'purchase_date', 'purchase_cost', 'warranty_expiry', 'os_license', 'license_expiry'];

    public const REQUIRED = ['lab_name', 'asset_type'];

    public function __construct(private readonly ItBook $book) {}

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>
     */
    public function check(array $rows): array
    {
        $result = [];
        foreach ($rows as $i => $row) {
            $errors = [];
            $row['asset_type'] = mb_strtolower(trim($row['asset_type'] ?? ''));
            $v = Validator::make($row, [
                'lab_name' => ['required', 'max:255'],
                'location' => ['nullable', 'max:255'],
                'capacity' => ['nullable', 'integer', 'min:0', 'max:10000'],
                'workstation_no' => ['nullable', 'max:30'],
                'asset_type' => ['required', 'in:'.implode(',', ItAsset::TYPES)],
                'brand' => ['nullable', 'max:100'],
                'model' => ['nullable', 'max:100'],
                'serial_no' => ['nullable', 'max:150'],
                'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
                'os_license' => ['nullable', 'max:150'],
            ]);
            foreach ($v->errors()->messages() as $field => $messages) {
                $errors[$field] = $messages[0];
            }
            $data = [];
            foreach (['lab_name', 'location', 'workstation_no', 'asset_type', 'brand', 'model', 'serial_no', 'os_license'] as $f) {
                $data[$f] = trim($row[$f] ?? '') ?: null;
            }
            $data['capacity'] = ($row['capacity'] ?? '') === '' ? null : (int) $row['capacity'];
            $data['purchase_cost'] = ($row['purchase_cost'] ?? '') === '' ? null : $row['purchase_cost'];
            foreach (['purchase_date', 'warranty_expiry', 'license_expiry'] as $f) {
                $data[$f] = ($row[$f] ?? '') === '' ? null : Dates::parse($row[$f]);
                if (($row[$f] ?? '') !== '' && $data[$f] === null) {
                    $errors[$f] = __('import.bad_date');
                }
            }
            $result[] = ['line' => $i + 2, 'data' => $data, 'errors' => $errors];
        }

        return $result;
    }

    /**
     * @param  list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>  $checked
     * @return array{assets: int, labs: int}
     */
    public function import(array $checked, User $actor): array
    {
        return DB::transaction(function () use ($checked, $actor) {
            $labs = ItLab::query()->get()->keyBy(fn ($l) => mb_strtolower($l->name));
            $created = 0;
            foreach ($checked as $row) {
                $d = $row['data'];
                $key = mb_strtolower($d['lab_name']);
                if (! isset($labs[$key])) {
                    $labs[$key] = ItLab::create(['name' => $d['lab_name'], 'location' => $d['location'], 'capacity' => $d['capacity']]);
                    $created++;
                }
                $this->book->create($labs[$key], array_diff_key($d, array_flip(['lab_name', 'location', 'capacity'])), $actor->id);
            }
            AuditLogger::record('it', 'import', null, null, ['assets' => count($checked), 'labs' => $created]);

            return ['assets' => count($checked), 'labs' => $created];
        });
    }
}
