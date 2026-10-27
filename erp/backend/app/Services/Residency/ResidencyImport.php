<?php

namespace App\Services\Residency;

use App\Models\Building;
use App\Models\Employee;
use App\Models\MaintenanceLocation;
use App\Models\ResidencyBillShare;
use App\Models\ResidencyImportBatch;
use App\Models\ResidencyOccupant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\Dates;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The old residency import engine: one CSV of buildings, rooms and who lives
 * in them. Rows are checked first; then everything is created in one batch
 * that can be rolled back while nothing else depends on it.
 */
final class ResidencyImport
{
    public const COLUMNS = ['building_name', 'room_name', 'occupant_type', 'name', 'staff_email', 'relation', 'moved_in_date'];

    public const REQUIRED = ['building_name', 'room_name', 'occupant_type', 'name'];

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>
     */
    public function check(array $rows, int $organizationId): array
    {
        $users = User::query()->whereIn('id', Employee::query()->where('is_active', true)->whereNotNull('user_id')->select('user_id'))
            ->pluck('id', 'email')->mapWithKeys(fn ($id, $email) => [mb_strtolower($email) => $id]);
        $housed = ResidencyOccupant::query()->where('occupant_type', 'staff')->where('status', 'active')->whereNotNull('user_id')->pluck('user_id')->flip();
        $staffRooms = [];
        $seenUsers = [];
        $result = [];
        foreach ($rows as $i => $row) {
            $errors = [];
            $data = [];
            foreach (['building_name' => 255, 'room_name' => 255, 'name' => 255, 'relation' => 100] as $f => $max) {
                $data[$f] = trim((string) ($row[$f] ?? '')) ?: null;
                if (in_array($f, self::REQUIRED, true) && $data[$f] === null) {
                    $errors[$f] = __('validation.required', ['attribute' => $f]);
                } elseif ($data[$f] !== null && mb_strlen($data[$f]) > $max) {
                    $errors[$f] = __('validation.max.string', ['attribute' => $f, 'max' => $max]);
                }
            }
            $type = mb_strtolower(trim((string) ($row['occupant_type'] ?? '')));
            $data['occupant_type'] = in_array($type, ['staff', 'family'], true) ? $type : null;
            if ($data['occupant_type'] === null) {
                $errors['occupant_type'] = __('residency.bad_type');
            }
            $data['moved_in_date'] = ($row['moved_in_date'] ?? '') === '' ? null : Dates::parse($row['moved_in_date']);
            if (($row['moved_in_date'] ?? '') !== '' && $data['moved_in_date'] === null) {
                $errors['moved_in_date'] = __('import.bad_date');
            }
            $roomKey = mb_strtolower(($data['building_name'] ?? '').'|'.($data['room_name'] ?? ''));
            $data['user_id'] = null;
            if ($data['occupant_type'] === 'staff') {
                $email = mb_strtolower(trim((string) ($row['staff_email'] ?? '')));
                if ($email !== '') {
                    $data['user_id'] = $users[$email] ?? null;
                    if ($data['user_id'] === null) {
                        $errors['staff_email'] = __('import.employee_not_found');
                    } elseif (isset($housed[$data['user_id']]) || isset($seenUsers[$data['user_id']])) {
                        $errors['staff_email'] = __('residency.already_housed');
                    }
                    $seenUsers[$data['user_id'] ?? 0] = true;
                }
                $staffRooms[$roomKey] = true;
            } elseif ($data['occupant_type'] === 'family' && ! isset($staffRooms[$roomKey]) && ! $this->roomHasStaff($data['building_name'], $data['room_name'])) {
                $errors['occupant_type'] = __('residency.family_needs_staff');
            }
            $result[] = ['line' => $i + 2, 'data' => $data, 'errors' => $errors];
        }

        return $result;
    }

    /** @param  list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>  $checked */
    public function import(array $checked, int $organizationId, ?string $filename, int $userId): ResidencyImportBatch
    {
        return DB::transaction(function () use ($checked, $organizationId, $filename, $userId) {
            $batch = ResidencyImportBatch::create(['organization_id' => $organizationId, 'filename' => $filename ? mb_substr($filename, 0, 255) : null, 'imported_by' => $userId]);
            $buildings = [];
            $rooms = [];
            $heads = [];
            $counts = ['buildings' => 0, 'rooms' => 0, 'occupants' => 0];
            foreach ($checked as $row) {
                $d = $row['data'];
                $bKey = mb_strtolower($d['building_name']);
                $buildings[$bKey] ??= Building::query()->whereRaw('lower(name) = ?', [$bKey])->first()
                    ?? tap(Building::create(['organization_id' => $organizationId, 'name' => $d['building_name'], 'created_by' => $userId, 'residency_import_batch_id' => $batch->id]), function () use (&$counts) {
                        $counts['buildings']++;
                    });
                $building = $buildings[$bKey];
                $rKey = $bKey.'|'.mb_strtolower($d['room_name']);
                $rooms[$rKey] ??= MaintenanceLocation::query()->where('building_id', $building->id)->whereRaw('lower(name) = ?', [mb_strtolower($d['room_name'])])->first()
                    ?? tap(MaintenanceLocation::create([
                        'organization_id' => $organizationId, 'building_id' => $building->id, 'institution_id' => $building->institution_id, 'name' => $d['room_name'],
                        'location_code' => sprintf('RES-%d-%03d', $batch->id, $counts['rooms'] + 1), 'is_residential' => true, 'created_by' => $userId,
                        'residency_import_batch_id' => $batch->id,
                    ]), function () use (&$counts) {
                        $counts['rooms']++;
                    });
                $room = $rooms[$rKey];
                if (! $room->is_residential) {
                    $room->forceFill(['is_residential' => true])->save();
                }
                $headId = null;
                if ($d['occupant_type'] === 'family') {
                    $headId = $heads[$rKey] ?? ResidencyOccupant::query()->where('location_id', $room->id)->where('occupant_type', 'staff')->where('status', 'active')->orderBy('id')->value('id');
                }
                $occupant = ResidencyOccupant::create([
                    'organization_id' => $organizationId, 'location_id' => $room->id, 'import_batch_id' => $batch->id, 'occupant_type' => $d['occupant_type'],
                    'user_id' => $d['user_id'], 'family_of_id' => $headId, 'name' => $d['name'], 'relation' => $d['relation'], 'moved_in_date' => $d['moved_in_date'],
                    'status' => 'active', 'created_by' => $userId,
                ]);
                if ($d['occupant_type'] === 'staff') {
                    $heads[$rKey] ??= $occupant->id;
                }
                $counts['occupants']++;
            }
            $batch->forceFill(['buildings_created' => $counts['buildings'], 'rooms_created' => $counts['rooms'], 'occupants_created' => $counts['occupants']])->save();
            AuditLogger::record('residency', 'import', $batch, null, $counts);

            return $batch;
        });
    }

    /** Undo a batch while nothing else depends on it: no bills on its occupants, nothing else in its rooms. */
    public function rollback(ResidencyImportBatch $batch): void
    {
        if ($batch->status !== 'completed') {
            throw ValidationException::withMessages(['batch' => [__('residency.already_rolled_back')]]);
        }
        $occupants = ResidencyOccupant::query()->where('import_batch_id', $batch->id)->pluck('id');
        if (ResidencyBillShare::query()->whereIn('occupant_id', $occupants->all() ?: [0])->exists()) {
            throw ValidationException::withMessages(['batch' => [__('residency.rollback_has_bills')]]);
        }
        DB::transaction(function () use ($batch, $occupants) {
            ResidencyOccupant::query()->whereIn('family_of_id', $occupants->all() ?: [0])->whereNotIn('id', $occupants->all() ?: [0])->update(['family_of_id' => null]);
            ResidencyOccupant::query()->whereIn('id', $occupants->all() ?: [0])->delete();
            MaintenanceLocation::query()->where('residency_import_batch_id', $batch->id)
                ->whereDoesntHave('residents')->whereDoesntHave('logs')->get()->each->delete();
            Building::query()->where('residency_import_batch_id', $batch->id)->whereDoesntHave('locations')->get()->each->delete();
            $batch->forceFill(['status' => 'rolled_back', 'rolled_back_at' => now()])->save();
            AuditLogger::record('residency', 'import_rollback', $batch);
        });
    }

    private function roomHasStaff(?string $building, ?string $room): bool
    {
        if ($building === null || $room === null) {
            return false;
        }

        return ResidencyOccupant::query()->where('occupant_type', 'staff')->where('status', 'active')
            ->whereHas('location', fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower($room)])->whereHas('building', fn ($b) => $b->whereRaw('lower(name) = ?', [mb_strtolower($building)])))->exists();
    }
}
