<?php

namespace App\Services\Cctv;

use App\Models\CctvCamera;
use App\Models\CctvDvr;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\Dates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Recorders and their cameras from CSV (the old CCTV import): one row per camera; missing recorders are created. */
final class CctvImport
{
    public const COLUMNS = ['dvr_name', 'dvr_location', 'channel_capacity', 'storage_capacity_tb', 'installed_date', 'camera_name', 'camera_location', 'camera_type'];

    public const REQUIRED = ['dvr_name', 'camera_name'];

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>
     */
    public function check(array $rows): array
    {
        $existing = CctvCamera::query()->with('dvr:id,name')->get()->map(fn ($c) => mb_strtolower($c->dvr->name.'|'.$c->name))->flip();
        $seen = [];
        $result = [];
        foreach ($rows as $i => $row) {
            $row['camera_type'] = mb_strtolower(trim($row['camera_type'] ?? '')) ?: 'dome';
            $errors = [];
            $v = Validator::make($row, [
                'dvr_name' => ['required', 'max:255'],
                'dvr_location' => ['nullable', 'max:255'],
                'channel_capacity' => ['nullable', 'integer', 'min:1', 'max:512'],
                'storage_capacity_tb' => ['nullable', 'numeric', 'min:0', 'max:9999'],
                'camera_name' => ['required', 'max:255'],
                'camera_location' => ['nullable', 'max:255'],
                'camera_type' => ['in:'.implode(',', CctvCamera::TYPES)],
            ]);
            foreach ($v->errors()->messages() as $field => $messages) {
                $errors[$field] = $messages[0];
            }
            $installed = ($row['installed_date'] ?? '') === '' ? null : Dates::parse($row['installed_date']);
            if (($row['installed_date'] ?? '') !== '' && $installed === null) {
                $errors['installed_date'] = __('import.bad_date');
            }
            $key = mb_strtolower(trim($row['dvr_name'] ?? '').'|'.trim($row['camera_name'] ?? ''));
            if (isset($existing[$key]) || isset($seen[$key])) {
                $errors['camera_name'] = __('cctv.duplicate_camera');
            }
            $seen[$key] = true;
            $result[] = ['line' => $i + 2, 'data' => [
                'dvr_name' => trim($row['dvr_name'] ?? ''), 'dvr_location' => trim($row['dvr_location'] ?? '') ?: null,
                'channel_capacity' => ($row['channel_capacity'] ?? '') === '' ? null : (int) $row['channel_capacity'],
                'storage_tb' => ($row['storage_capacity_tb'] ?? '') === '' ? null : $row['storage_capacity_tb'], 'installed_date' => $installed,
                'camera_name' => trim($row['camera_name'] ?? ''), 'camera_location' => trim($row['camera_location'] ?? '') ?: null, 'camera_type' => $row['camera_type'],
            ], 'errors' => $errors];
        }

        return $result;
    }

    /**
     * @param  list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>  $checked
     * @return array{cameras: int, dvrs: int}
     */
    public function import(array $checked): array
    {
        return DB::transaction(function () use ($checked) {
            $dvrs = CctvDvr::query()->get()->keyBy(fn ($d) => mb_strtolower($d->name));
            $created = 0;
            foreach ($checked as $row) {
                $d = $row['data'];
                $key = mb_strtolower($d['dvr_name']);
                if (! isset($dvrs[$key])) {
                    $dvrs[$key] = CctvDvr::create(['name' => $d['dvr_name'], 'location' => $d['dvr_location'], 'channel_capacity' => $d['channel_capacity'],
                        'storage_tb' => $d['storage_tb'], 'installed_date' => $d['installed_date']]);
                    $created++;
                }
                CctvCamera::create(['dvr_id' => $dvrs[$key]->id, 'name' => $d['camera_name'], 'location' => $d['camera_location'], 'camera_type' => $d['camera_type']]);
            }
            AuditLogger::record('cctv', 'import', null, null, ['cameras' => count($checked), 'dvrs' => $created]);

            return ['cameras' => count($checked), 'dvrs' => $created];
        });
    }
}
