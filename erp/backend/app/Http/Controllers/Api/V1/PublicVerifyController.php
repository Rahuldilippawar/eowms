<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public ID-card check (the QR on the card). Shows only what is printed on
 * the card plus whether the person is still active; no login needed.
 */
class PublicVerifyController extends Controller
{
    public function employee(string $token): JsonResponse
    {
        $employee = $this->find($token)->load(['department', 'institution', 'organization']);

        return response()->json(['data' => [
            'name' => $employee->name,
            'employee_code' => $employee->employee_code,
            'designation' => $employee->designation,
            'department' => $employee->department?->name,
            'institution' => $employee->institution?->name,
            'organization' => $employee->organization->name,
            'is_active' => $employee->is_active && $employee->left_date === null,
            'has_photo' => $employee->photo_path !== null,
        ]]);
    }

    public function photo(string $token): StreamedResponse
    {
        return EmployeeProfileController::streamPhoto($this->find($token));
    }

    private function find(string $token): Employee
    {
        abort_unless(preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $token) === 1, 404);

        return Employee::withoutGlobalScopes()->where('public_token', $token)
            ->whereHas('organization', fn ($q) => $q->where('is_active', true))->firstOrFail();
    }
}
