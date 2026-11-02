<?php

namespace App\Services\Legacy\Steps;

use App\Models\Department;
use App\Services\Legacy\LegacyImport;
use App\Services\Legacy\Step;
use Illuminate\Support\Str;

/** departments → departments (the Marathi name when the old app had one). Heads come after the people. */
final class DepartmentsStep implements Step
{
    public function key(): string
    {
        return 'departments';
    }

    public function run(LegacyImport $import): void
    {
        foreach ($import->legacy()->table('departments')->orderBy('id')->get() as $d) {
            $import->read();
            if ($import->mapped('departments', $d->id) !== null) {
                $import->existing();

                continue;
            }
            $org = $import->mapped('organizations', $d->organization_id);
            if ($org === null) {
                $import->skip('departments', $d->id, 'organization_missing');

                continue;
            }
            $institution = $import->mapped('campuses', $d->campus_id);
            $code = Str::limit((string) $d->code, 30, '') ?: 'D'.$d->id;
            for ($i = 2; Department::withoutGlobalScopes()->where(['organization_id' => $org, 'institution_id' => $institution, 'code' => $code])->exists(); $i++) {
                $code = Str::limit((string) $d->code, 26, '').'-'.$i;
            }
            $department = Department::withoutGlobalScopes()->create([
                'organization_id' => $org, 'institution_id' => $institution, 'name' => trim((string) ($d->name_local ?: $d->name)),
                'code' => $code, 'is_active' => (bool) $d->is_active,
            ]);
            $import->remember('departments', $d->id, 'departments', $department->id);
            $import->created();
        }
    }
}
