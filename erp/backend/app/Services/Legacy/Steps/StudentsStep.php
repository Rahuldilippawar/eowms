<?php

namespace App\Services\Legacy\Steps;

use App\Models\Student;
use App\Services\Legacy\LegacyImport;
use App\Services\Legacy\Step;
use Illuminate\Support\Str;

/** students → students (their old portal sign-in moves with the student portal in Phase 14). */
final class StudentsStep implements Step
{
    public function key(): string
    {
        return 'students';
    }

    public function run(LegacyImport $import): void
    {
        if (! $import->legacy()->getSchemaBuilder()->hasTable('students')) {
            return;
        }
        foreach ($import->legacy()->table('students')->orderBy('id')->get() as $s) {
            $import->read();
            if ($import->mapped('students', $s->id) !== null) {
                $import->existing();

                continue;
            }
            $org = $import->mapped('organizations', $s->organization_id);
            $institution = $import->mapped('campuses', $s->campus_id);
            if ($org === null || $institution === null) {
                $import->skip('students', $s->id, 'campus_missing');

                continue;
            }
            $code = Str::limit(trim((string) $s->admission_no), 30, '') ?: 'OLD-'.$s->id;
            if (Student::withoutGlobalScopes()->where(['organization_id' => $org, 'student_code' => $code])->exists()) {
                $import->skip('students', $s->id, 'admission_no_duplicate');

                continue;
            }
            $student = Student::withoutGlobalScopes()->create([
                'organization_id' => $org, 'institution_id' => $institution, 'department_id' => $import->mapped('departments', $s->department_id),
                'student_code' => $code, 'roll_no' => $s->roll_no, 'admission_no' => $s->admission_no, 'name' => $s->name,
                'year_of_study' => $s->year_of_study, 'academic_year' => $s->academic_year, 'mobile' => $s->mobile, 'guardian_phone' => $s->parent_mobile,
                'email' => $s->email, 'is_active' => (bool) $s->is_active,
            ]);
            $import->remember('students', $s->id, 'students', $student->id);
            $import->created();
        }
    }
}
