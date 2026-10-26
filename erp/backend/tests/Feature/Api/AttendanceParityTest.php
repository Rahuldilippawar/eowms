<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePrivate;
use App\Models\Holiday;
use App\Models\Institution;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class AttendanceParityTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Institution $college;

    private Department $dept;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->college = Institution::factory()->for($this->org)->create(['code' => 'COL']);
        $this->dept = Department::factory()->for($this->org)->create(['institution_id' => $this->college->id]);
        $this->user = $this->userWithRole($this->org, []);
        $this->employee = Employee::factory()->in($this->dept)->create(['user_id' => $this->user->id, 'biometric_pin' => '101']);
        $this->at('2026-03-10 12:00'); // Tuesday
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $local): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($local, 'Asia/Kolkata'));
        Carbon::setTestNow(CarbonImmutable::parse($local, 'Asia/Kolkata'));
    }

    private function manager(PermissionScope $scope = PermissionScope::Organization, array $extra = []): User
    {
        return $this->userWithRole($this->org, ['attendance.manage', 'attendance.view', 'attendance.export', 'employees.view', ...$extra], $scope, institutionIds: [$this->college->id]);
    }

    public function test_holidays_scope_and_import(): void
    {
        $principal = $this->manager(PermissionScope::Institutions);
        $this->actingAs($principal);
        $this->spa()->postJson('/api/v1/holidays', ['holiday_date' => '2026-03-20', 'name' => 'Founders day', 'institution_id' => $this->college->id])->assertCreated();
        // Organization-wide holidays need organization scope.
        $this->spa()->postJson('/api/v1/holidays', ['holiday_date' => '2026-03-21', 'name' => 'All'])->assertStatus(422)->assertJsonValidationErrors('institution_id');
        $this->spa()->postJson('/api/v1/holidays', ['holiday_date' => '2026-03-20', 'name' => 'Again', 'institution_id' => $this->college->id])->assertStatus(422);

        $this->actingAs($this->manager());
        $csv = UploadedFile::fake()->createWithContent('h.csv', "holiday_date,name,institution_code\n26/03/2026,Holi,\n2026-03-20,Dup,COL\n");
        $this->spa()->post('/api/v1/holidays/import', ['file' => $csv], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped', 1);
        $bad = UploadedFile::fake()->createWithContent('h.csv', "holiday_date,name,institution_code\n31/02/2026,X,\n2026-04-01,Y,NOPE\n");
        $this->spa()->post('/api/v1/holidays/import', ['file' => $bad], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['line_2', 'line_3']);

        $this->actingAs($this->user);
        $this->spa()->getJson('/api/v1/holidays?year=2026')->assertOk()->assertJsonCount(2, 'data');
        $this->spa()->postJson('/api/v1/holidays', ['holiday_date' => '2026-05-01', 'name' => 'X'])->assertForbidden();
    }

    public function test_leave_counts_working_days_and_respects_balance(): void
    {
        $casual = LeaveType::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'CL', 'name' => 'Casual', 'days_per_year' => 3, 'is_paid' => true, 'is_active' => true]);
        $lwp = LeaveType::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'LWP', 'name' => 'Without pay', 'days_per_year' => 0, 'is_paid' => false, 'is_active' => true]);
        Holiday::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'holiday_date' => '2026-03-17', 'name' => 'Festival']);
        $this->actingAs($this->user);
        $apply = fn ($type, $s, $e) => $this->spa()->postJson('/api/v1/me/leave', ['leave_type_id' => $type->id, 'start_date' => $s, 'end_date' => $e, 'reason' => 'x']);

        // Fri 13 → Tue 17: Sat 14 works, Sun 15 is the weekly off, Tue 17 a holiday → 3 days.
        $apply($casual, '2026-03-13', '2026-03-17')->assertCreated()->assertJsonPath('data.days', 3);
        $apply($casual, '2026-03-24', '2026-03-24')->assertStatus(422)->assertJsonValidationErrors('leave_type_id');
        $apply($casual, '2026-03-22', '2026-03-22')->assertStatus(422)->assertJsonValidationErrors('start_date');
        $apply($lwp, '2026-03-24', '2026-03-28')->assertCreated()->assertJsonPath('data.days', 5);

        $balances = collect($this->spa()->getJson('/api/v1/me/leave/balances?year=2026')->assertOk()->json('data'))->keyBy('leave_type.code');
        $this->assertSame([3, 0, 3, 0], [$balances['CL']['allocated'], $balances['CL']['used'], $balances['CL']['pending'], $balances['CL']['remaining']]);
        $this->assertNull($balances['LWP']['remaining']);

        // An allocation raises the limit.
        $hr = $this->userWithRole($this->org, ['leave.view', 'leave.balances']);
        $this->actingAs($hr);
        $this->spa()->putJson('/api/v1/leave-balances', ['employee_id' => $this->employee->id, 'leave_type_id' => $casual->id, 'year' => 2026, 'days' => 5])
            ->assertOk()->assertJsonPath('data.remaining', 2);
        $this->spa()->getJson('/api/v1/leave-balances?year=2026')->assertOk()->assertJsonPath('data.0.employee.id', $this->employee->id);
        $this->actingAs($this->user);
        $apply($casual, '2026-03-24', '2026-03-24')->assertStatus(422); // overlaps the LWP request
        $apply($casual, '2026-03-30', '2026-03-30')->assertCreated();
    }

    public function test_employee_shift_decides_late_and_weekly_off(): void
    {
        $this->actingAs($this->manager());
        $this->spa()->putJson("/api/v1/employees/{$this->employee->id}/attendance-settings", ['work_start' => '07:00', 'late_grace_minutes' => 0, 'weekly_offs' => [2]])
            ->assertOk()->assertJsonPath('data.effective.work_start', '07:00')->assertJsonPath('data.effective.weekly_offs', [2]);
        $other = Employee::factory()->in($this->dept)->create(['biometric_pin' => '102']);
        $this->spa()->putJson("/api/v1/employees/{$other->id}/attendance-settings", ['biometric_pin' => '101'])->assertStatus(422)->assertJsonValidationErrors('biometric_pin');

        $this->actingAs($this->user);
        $this->at('2026-03-11 08:00');
        $this->spa()->postJson('/api/v1/me/attendance/check-in')->assertCreated()->assertJsonPath('data.status', 'late');

        $this->actingAs($this->manager());
        $this->spa()->putJson('/api/v1/organization/attendance-rules', ['timezone' => 'Asia/Kolkata', 'work_start' => '10:00', 'work_end' => '18:00', 'late_grace_minutes' => 10, 'half_day_below_hours' => 4, 'weekly_offs' => [], 'max_shift_hours' => 16, 'daily_work_edit_days' => 5])
            ->assertForbidden();
        $this->actingAs($this->userWithRole($this->org, ['organizations.edit']));
        $this->spa()->putJson('/api/v1/organization/attendance-rules', ['timezone' => 'Asia/Kolkata', 'work_start' => '10:00', 'work_end' => '18:00', 'late_grace_minutes' => 10, 'half_day_below_hours' => 4, 'weekly_offs' => [], 'max_shift_hours' => 16, 'daily_work_edit_days' => 5])
            ->assertOk()->assertJsonPath('data.weekly_offs', [])->assertJsonPath('data.daily_work_edit_days', 5);
        $this->spa()->putJson('/api/v1/organization/attendance-rules', ['timezone' => 'Mars/Base'])->assertStatus(422);
    }

    public function test_grid_certificate_and_salary(): void
    {
        Holiday::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'holiday_date' => '2026-03-03', 'name' => 'Festival']);
        foreach ([['2026-03-02', 'present'], ['2026-03-04', 'late'], ['2026-03-05', 'half_day'], ['2026-03-06', 'absent']] as [$d, $s]) {
            AttendanceRecord::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'employee_id' => $this->employee->id, 'work_date' => $d, 'status' => $s, 'source' => 'manual']);
        }
        EmployeePrivate::create(['employee_id' => $this->employee->id, 'monthly_salary' => 31000]);

        $this->actingAs($this->manager());
        $row = $this->spa()->getJson('/api/v1/attendance/grid?month=2026-03')->assertOk()->json('data.0');
        $this->assertSame(['P', 'HO', 'L', 'H', 'A', '-', 'WO'], array_values(array_slice($row['days'], 1, 7)));
        $this->assertNull($row['days']['2026-03-11']);
        $this->assertSame(2, $row['totals']['present']);
        $csv = $this->spa()->get('/api/v1/attendance/grid?month=2026-03&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString($this->employee->employee_code, $csv);

        $this->spa()->getJson("/api/v1/attendance/certificate/{$this->employee->id}?from=2026-03-01&to=2026-03-10")->assertOk()->assertJsonPath('data.totals.holiday', 1);
        $this->spa()->getJson('/api/v1/reports/salary?month=2026-03')->assertForbidden();

        $this->actingAs($this->manager(extra: ['employees.sensitive']));
        $pay = $this->spa()->getJson('/api/v1/reports/salary?month=2026-03')->assertOk()->json('data.0');
        // 31 days − absent 1 − unmarked (3rd? no: 1, 7, 9, 10 are unmarked working days) − half 0.5
        $this->assertSame(31, $pay['days_in_month']);
        $this->assertEqualsWithDelta(31 - 1 - $pay['totals']['unmarked'] - 0.5, $pay['pay_days'], 0.001);
        $this->assertEqualsWithDelta(round(31000 * $pay['pay_days'] / 31, 2), $pay['pay'], 0.01);

        $this->actingAs($this->user);
        $this->spa()->getJson('/api/v1/me/attendance/certificate?from=2026-03-01&to=2026-03-10')->assertOk();
        $other = Employee::factory()->in($this->dept)->create();
        $this->spa()->getJson("/api/v1/attendance/certificate/{$other->id}?from=2026-03-01&to=2026-03-10")->assertNotFound();
    }

    public function test_biometric_push_from_registered_devices_only(): void
    {
        $device = BiometricDevice::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'serial_number' => 'ZK-0001', 'name' => 'Gate']);
        $this->app['auth']->forgetGuards();

        $this->get('/iclock/cdata?SN=ZK-0001&options=all')->assertOk()->assertSee('GET OPTION FROM: ZK-0001', false)->assertSee('TimeZone=5.5', false);
        $body = "101\t2026-03-10 09:20:00\t0\t1\n101\t2026-03-10 17:45:10\t1\t1\n101\t2026-03-10 09:20:00\t0\t1\n999\t2026-03-10 10:00:00\t0\t1\ngarbage\n";
        $this->call('POST', '/iclock/cdata?SN=ZK-0001&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)->assertOk()->assertSee('OK: 4');
        $record = AttendanceRecord::withoutGlobalScopes()->where('employee_id', $this->employee->id)->sole();
        $this->assertSame(['present', 'biometric'], [$record->status->value, $record->source]);
        $this->assertSame('2026-03-10 12:15:10', $record->check_out_at->toDateTimeString());
        $this->assertDatabaseHas('biometric_punches', ['pin' => '999', 'result' => 'unknown_pin']);
        $this->assertNotNull($device->fresh()->last_seen_at);

        // Unknown or switched-off machines are ignored.
        $this->call('POST', '/iclock/cdata?SN=EVIL-1&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "101\t2026-03-11 09:00:00")->assertOk()->assertSee('OK');
        $this->assertSame(1, AttendanceRecord::withoutGlobalScopes()->count());

        $this->actingAs($this->manager());
        $this->spa()->getJson('/api/v1/biometric-punches?result=unknown_pin')->assertOk()->assertJsonCount(1, 'data');
        $this->spa()->postJson('/api/v1/biometric-devices', ['name' => 'X', 'serial_number' => 'ZK-0001'])->assertStatus(422);
    }

    public function test_daily_jobs(): void
    {
        $boss = $this->userWithRole($this->org, []);
        $bossEmployee = Employee::factory()->in($this->dept)->create(['user_id' => $boss->id]);
        $this->employee->forceFill(['reporting_officer_id' => $bossEmployee->id, 'date_of_birth' => '1990-03-10', 'joining_date' => '2020-03-10'])->save();
        $onHoliday = Employee::factory()->in($this->dept)->create();

        // Monday 9th: no record → absent; nobody is marked on Sunday 8th.
        $this->artisan('erp:mark-absent', ['--date' => '2026-03-09'])->assertSuccessful();
        $this->artisan('erp:mark-absent', ['--date' => '2026-03-09'])->assertSuccessful();
        $this->artisan('erp:mark-absent', ['--date' => '2026-03-08'])->assertSuccessful();
        $this->assertSame(3, AttendanceRecord::withoutGlobalScopes()->where(['work_date' => '2026-03-09', 'status' => 'absent'])->count());
        $this->assertSame(0, AttendanceRecord::withoutGlobalScopes()->where('work_date', '2026-03-08')->count());
        $this->assertNotNull($onHoliday);

        AttendanceRecord::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'employee_id' => $this->employee->id, 'work_date' => '2026-03-07', 'status' => 'absent', 'source' => 'manual']);
        $this->artisan('erp:attendance-alerts')->assertSuccessful();
        $this->artisan('erp:attendance-alerts')->assertSuccessful();
        $this->assertSame(1, UserNotification::withoutGlobalScopes()->where(['user_id' => $boss->id, 'type' => 'attendance.pattern_absent'])->count());

        $this->artisan('erp:celebrations')->assertSuccessful();
        $this->artisan('erp:celebrations')->assertSuccessful();
        $this->assertSame(['celebration.anniversary_self', 'celebration.birthday_self'], UserNotification::withoutGlobalScopes()->where('user_id', $this->user->id)->orderBy('type')->pluck('type')->all());
        $this->assertSame(2, UserNotification::withoutGlobalScopes()->where('user_id', $boss->id)->whereIn('type', ['celebration.birthday', 'celebration.anniversary'])->count());
    }
}
