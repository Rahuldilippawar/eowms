<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-employee working hours; null columns fall back to the organization rule. Tenancy follows the employee. */
class EmployeeShift extends Model
{
    protected $fillable = ['employee_id', 'work_start', 'work_end', 'late_grace_minutes', 'half_day_below_hours', 'weekly_offs', 'updated_by'];

    protected function casts(): array
    {
        return ['weekly_offs' => 'array', 'late_grace_minutes' => 'integer', 'half_day_below_hours' => 'float'];
    }
}
