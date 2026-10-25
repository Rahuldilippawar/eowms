<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\EmployeePrivate;
use App\Support\Access\EmployeeAccess;
use Illuminate\Http\Request;

/**
 * Everything on the staff profile screen. Private data is included only
 * for the employee themself or an `employees.sensitive` holder in scope,
 * with Aadhaar and bank numbers masked (full values via the reveal endpoint).
 *
 * @mixin Employee
 */
class EmployeeProfileResource extends EmployeeResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $private = $this->private;

        return [
            ...parent::toArray($request),
            ...collect(Employee::PROFILE_FIELDS)->mapWithKeys(fn ($f) => [$f => $this->{$f}])->all(),
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'left_date' => $this->left_date?->toDateString(),
            'custom' => $this->custom ?? (object) [],
            'has_photo' => $this->photo_path !== null,
            'public_token' => $this->when(EmployeeAccess::mayEdit($this->resource) || EmployeeAccess::isSelf($this->resource), $this->public_token),
            'private' => $this->when(EmployeeAccess::maySeePrivate($this->resource), fn () => [
                'aadhaar_masked' => EmployeePrivate::mask($private?->aadhaar_number),
                'bank_account_masked' => EmployeePrivate::mask($private?->bank_account_no),
                ...collect(EmployeePrivate::FIELDS)->reject(fn ($f) => in_array($f, ['aadhaar_number', 'bank_account_no'], true))->mapWithKeys(fn ($f) => [$f => $private?->{$f}])->all(),
                'monthly_salary' => $private?->monthly_salary === null ? null : (float) $private->monthly_salary,
            ]),
            'documents' => $this->documents->map(fn ($d) => [
                'id' => $d->id, 'document_type' => $d->document_type, 'title' => $d->title, 'original_name' => $d->original_name,
                'size_bytes' => $d->size_bytes, 'uploaded_by' => $d->uploader?->name, 'created_at' => $d->created_at?->toIso8601String(),
            ])->values(),
            'family_members' => $this->familyMembers->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'relation' => $m->relation, 'date_of_birth' => $m->date_of_birth?->toDateString(),
                'phone' => $m->phone, 'lives_on_campus' => $m->lives_on_campus,
            ])->values(),
            'achievements' => $this->achievements->map(fn ($a) => [
                'id' => $a->id, 'title' => $a->title, 'description' => $a->description, 'achieved_on' => $a->achieved_on?->toDateString(), 'added_by' => $a->adder?->name,
            ])->values(),
            'can' => [
                'edit' => EmployeeAccess::mayEdit($this->resource),
                'edit_private' => EmployeeAccess::reaches($this->resource, 'employees.sensitive'),
                'self' => EmployeeAccess::isSelf($this->resource),
            ],
        ];
    }
}
