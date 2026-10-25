<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Employee;
use App\Support\Queries\PeopleQueries;

/** Self-service endpoints act on the signed-in user's employee record in the current organization. */
trait ResolvesMyEmployee
{
    private function employeeOrFail(): Employee
    {
        $employee = PeopleQueries::myEmployee();
        abort_if($employee === null, 404, __('attendance.no_employee'));

        return $employee;
    }

    private function activeEmployeeOrFail(): Employee
    {
        $employee = $this->employeeOrFail();
        abort_unless($employee->is_active, 403, __('auth.inactive'));

        return $employee;
    }
}
