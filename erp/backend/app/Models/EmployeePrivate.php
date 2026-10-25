<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private HR data, shown only with `employees.sensitive` (erp/docs/PEOPLE.md).
 * Aadhaar and bank account numbers are encrypted at rest with the app key.
 * Tenancy follows the employee.
 */
class EmployeePrivate extends Model
{
    protected $table = 'employee_private';

    public const FIELDS = ['aadhaar_number', 'ration_card', 'bank_account_no', 'ifsc_code', 'bank_name', 'bank_branch', 'pf_applicable', 'uan_no', 'esic_applicable', 'esic_no', 'pt_applicable', 'monthly_salary'];

    protected $fillable = [...self::FIELDS, 'employee_id', 'updated_by'];

    protected $hidden = ['aadhaar_number', 'bank_account_no'];

    protected function casts(): array
    {
        return [
            'aadhaar_number' => 'encrypted', 'bank_account_no' => 'encrypted', 'pf_applicable' => 'boolean',
            'esic_applicable' => 'boolean', 'pt_applicable' => 'boolean', 'monthly_salary' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** "XXXX-XXXX-1234": enough to recognise the number without exposing it. */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $digits = preg_replace('/\s+/', '', $value);

        return str_repeat('•', max(0, mb_strlen($digits) - 4)).mb_substr($digits, -4);
    }
}
