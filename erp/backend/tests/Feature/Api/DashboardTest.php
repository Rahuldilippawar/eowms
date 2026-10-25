<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Institution;
use App\Models\Organization;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_kpis_come_from_real_data_limited_to_scope_and_hidden_without_permission(): void
    {
        $this->setUpPermissions();
        $org = Organization::factory()->create();
        $college = Institution::factory()->for($org)->create();
        $a = Department::factory()->for($org)->create(['institution_id' => $college->id]);
        $b = Department::factory()->for($org)->create();
        $inA = Employee::factory()->in($a)->count(3)->create();
        Employee::factory()->in($b)->count(2)->create();
        Student::factory()->in($college)->count(4)->create();
        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        AttendanceRecord::create(['organization_id' => $org->id, 'employee_id' => $inA[0]->id, 'department_id' => $a->id, 'work_date' => $today, 'status' => 'present', 'source' => 'self']);
        AttendanceRecord::create(['organization_id' => $org->id, 'employee_id' => $inA[1]->id, 'department_id' => $a->id, 'work_date' => $today, 'status' => 'leave', 'source' => 'leave']);
        // Noise from another organization must never count.
        Employee::factory()->in(Department::factory()->for(Organization::factory())->create())->count(5)->create();

        $this->actingAs($this->userWithRole($org, ['employees.view', 'attendance.view'], PermissionScope::Departments, departmentIds: [$a->id]));
        $kpis = $this->spa()->getJson('/api/v1/dashboard?period=week')->assertOk()->json('data.kpis');

        $this->assertSame(3, $kpis['employees']);
        $this->assertSame(1, $kpis['present_today']);
        $this->assertSame(1, $kpis['on_leave_today']);
        $this->assertSame(1, $kpis['absent_today']);
        $this->assertNull($kpis['students'], 'no students.view → omitted, not zero');
        $this->assertNull($kpis['pending_corrections']);

        $this->actingAs($this->userWithRole($org, ['employees.view', 'students.view']));
        $data = $this->spa()->getJson('/api/v1/dashboard?period=today')->assertOk()->json('data');
        $this->assertSame(5, $data['kpis']['employees']);
        $this->assertSame(4, $data['kpis']['students']);
        $this->assertNull($data['charts']['attendance_trend']);
        $this->assertCount(2, $data['charts']['by_department']);
    }

    public function test_filters_and_custom_range(): void
    {
        $this->setUpPermissions();
        $org = Organization::factory()->create();
        $a = Department::factory()->for($org)->create();
        $b = Department::factory()->for($org)->create();
        Employee::factory()->in($a)->count(2)->create();
        Employee::factory()->in($b)->create();
        $this->actingAs($this->userWithRole($org, ['employees.view', 'attendance.view']));

        $data = $this->spa()->getJson("/api/v1/dashboard?period=custom&from=2026-01-01&to=2026-01-07&department_id={$a->id}")->assertOk()->json('data');
        $this->assertSame(2, $data['kpis']['employees']);
        $this->assertCount(7, $data['charts']['attendance_trend']);
        $this->spa()->getJson('/api/v1/dashboard?period=custom')->assertStatus(422);
    }

    public function test_week_starts_on_monday_in_every_language(): void
    {
        $this->setUpPermissions();
        $org = Organization::factory()->create();
        $this->actingAs($this->userWithRole($org, ['attendance.view']));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25 12:00', 'Asia/Kolkata')); // a Sunday

        try {
            foreach (['en', 'mr'] as $lang) {
                $data = $this->spa()->withHeader('Accept-Language', $lang)->getJson('/api/v1/dashboard?period=week')->assertOk()->json('data');
                $this->assertSame(['2026-10-19', '2026-10-25'], [$data['from'], $data['to']], $lang);
                $this->assertCount(7, $data['charts']['attendance_trend']);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
