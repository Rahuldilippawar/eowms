<?php

namespace App\Services\Legacy\Steps;

use App\Models\Department;
use App\Services\Legacy\LegacyImport;
use App\Services\Legacy\Step;

/** departments.hod_id → each department's head, once the people are in. */
final class DepartmentHeadsStep implements Step
{
    public function key(): string
    {
        return 'department_heads';
    }

    public function run(LegacyImport $import): void
    {
        foreach ($import->legacy()->table('departments')->whereNotNull('hod_id')->orderBy('id')->get() as $d) {
            $import->read();
            $department = $import->mapped('departments', $d->id);
            $head = $import->mapped('users', $d->hod_id);
            if ($department === null || $head === null) {
                $import->skip('departments', $d->id, 'head_missing');

                continue;
            }
            Department::withoutGlobalScopes()->whereKey($department)->whereNull('head_user_id')->update(['head_user_id' => $head]) ? $import->created() : $import->existing();
        }
    }
}
