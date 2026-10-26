<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Institution;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class StudentsAndWorkParityTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Institution $college;

    private Institution $school;

    private Department $dept;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        Storage::fake('local');
        $this->org = Organization::factory()->create();
        $this->college = Institution::factory()->for($this->org)->create(['code' => 'COL']);
        $this->school = Institution::factory()->for($this->org)->create(['code' => 'SCH']);
        $this->dept = Department::factory()->for($this->org)->create(['institution_id' => $this->college->id, 'code' => 'PHY']);
        $this->user = $this->userWithRole($this->org, []);
        $this->employee = Employee::factory()->in($this->dept)->create(['user_id' => $this->user->id, 'employee_code' => 'EMP-1']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 18:00', 'Asia/Kolkata'));
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-10 18:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_student_fields_filters_and_export(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['students.view', 'students.create', 'students.edit', 'students.export']));
        $id = $this->spa()->postJson('/api/v1/students', [
            'student_code' => 'S-1', 'name' => 'Asha', 'institution_id' => $this->college->id, 'roll_no' => '12', 'admission_no' => 'ADM-9',
            'year_of_study' => 2, 'academic_year' => '2026-27', 'mobile' => '9800000001', 'email' => 'asha@example.com',
        ])->assertCreated()->assertJsonPath('data.roll_no', '12')->assertJsonPath('data.academic_year', '2026-27')->json('data.id');
        $this->spa()->postJson('/api/v1/students', ['student_code' => 'S-2', 'name' => 'B', 'institution_id' => $this->college->id, 'academic_year' => '2026'])
            ->assertStatus(422)->assertJsonValidationErrors('academic_year');

        $this->spa()->getJson('/api/v1/students?search=ADM-9')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->spa()->getJson('/api/v1/students?academic_year=2025-26')->assertOk()->assertJsonCount(0, 'data');
        $csv = $this->spa()->get('/api/v1/students/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('S-1,Asha,COL,,,,12,ADM-9,2,2026-27', $csv);

        $this->actingAs($this->userWithRole($this->org, ['students.view']));
        $this->spa()->get('/api/v1/students/export')->assertForbidden();
    }

    public function test_student_import_checks_every_row_and_scope(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['students.view', 'students.create'], PermissionScope::Institutions, institutionIds: [$this->college->id]));
        Student::factory()->create(['organization_id' => $this->org->id, 'institution_id' => $this->college->id, 'student_code' => 'OLD-1']);
        $header = 'student_code,name,institution_code,department_code,roll_no,year_of_study,academic_year,date_of_birth';

        $bad = UploadedFile::fake()->createWithContent('s.csv', "{$header}\nOLD-1,A,COL,,,,,\nN-1,B,SCH,,,,,\nN-2,C,COL,NOPE,,,,\nN-3,D,COL,,,x,26-27,31/02/2008\n");
        $preview = $this->spa()->post('/api/v1/students/import/preview', ['file' => $bad], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(4, $preview->json('data.with_errors'));
        $rows = collect($preview->json('data.rows'));
        $this->assertArrayHasKey('student_code', $rows[0]['errors']);
        $this->assertArrayHasKey('institution_code', $rows[1]['errors']); // outside the caller's scope
        $this->assertArrayHasKey('department_code', $rows[2]['errors']);
        $this->assertEqualsCanonicalizing(['year_of_study', 'academic_year', 'date_of_birth'], array_keys($rows[3]['errors']));
        $this->spa()->post('/api/v1/students/import', ['file' => $bad], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(1, Student::query()->count());

        $good = UploadedFile::fake()->createWithContent('s.csv', "{$header}\nN-1,Ravi,col,phy,7,1,2026-27,15/04/2008\nN-2,Sita,COL,,8,1,2026-27,\n");
        $this->spa()->post('/api/v1/students/import', ['file' => $good], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.imported', 2);
        $ravi = Student::query()->where('student_code', 'N-1')->firstOrFail();
        $this->assertSame([$this->dept->id, '7', 1, '2008-04-15'], [$ravi->department_id, $ravi->roll_no, $ravi->year_of_study, $ravi->date_of_birth->toDateString()]);
    }

    public function test_work_entry_team_costs_and_photos(): void
    {
        $mate = Employee::factory()->in($this->dept)->create(['user_id' => $this->userWithRole($this->org, [])->id]);
        $inactive = Employee::factory()->in($this->dept)->create(['is_active' => false]);
        $this->actingAs($this->user);
        $this->spa()->getJson('/api/v1/me/work/colleagues?search='.urlencode(mb_substr($mate->name, 0, 4)))->assertOk()->assertJsonFragment(['id' => $mate->id]);
        $this->assertNotContains($inactive->id, collect($this->spa()->getJson('/api/v1/me/work/colleagues?search='.$inactive->employee_code)->json('data'))->pluck('id'));
        $base = ['work_date' => '2026-06-10', 'title' => 'Pipe repair', 'status' => 'completed', 'requested_by' => 'Principal office', 'estimated_cost' => 400, 'actual_cost' => 500];

        $this->spa()->postJson('/api/v1/me/work', $base + ['team_employee_ids' => [$this->employee->id]])->assertStatus(422)->assertJsonValidationErrors('team_employee_ids');
        $this->spa()->postJson('/api/v1/me/work', $base + ['team_employee_ids' => [$inactive->id]])->assertStatus(422)->assertJsonValidationErrors('team_employee_ids');
        $id = $this->spa()->postJson('/api/v1/me/work', $base + ['team_employee_ids' => [$mate->id]])->assertCreated()
            ->assertJsonPath('data.team.0.id', $mate->id)->assertJsonPath('data.actual_cost', 500)->assertJsonPath('data.requested_by', 'Principal office')
            ->json('data.id');
        // A member who has left since may stay on the entry.
        $mate->forceFill(['is_active' => false])->save();
        $this->spa()->putJson("/api/v1/me/work/{$id}", $base + ['team_employee_ids' => [$mate->id]])->assertOk();
        $mate->forceFill(['is_active' => true])->save();
        // Leaving the team out of an update keeps it.
        $this->spa()->putJson("/api/v1/me/work/{$id}", ['title' => 'Pipe repair (main)'] + $base)->assertOk()->assertJsonPath('data.title', 'Pipe repair (main)')->assertJsonCount(1, 'data.team');

        $photo = UploadedFile::fake()->image('before.jpg', 400, 300);
        $this->spa()->post("/api/v1/me/work/{$id}/photos/before", ['photo' => $photo], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_before_photo', true);
        $this->spa()->post("/api/v1/me/work/{$id}/photos/during", ['photo' => $photo], ['Accept' => 'application/json'])->assertNotFound();
        $this->spa()->post("/api/v1/me/work/{$id}/photos/after", ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->spa()->get("/api/v1/work/{$id}/photos/before")->assertOk();
        $path = WorkEntry::query()->findOrFail($id)->before_photo_path;
        Storage::disk('local')->assertExists($path);

        // The team mate may see the photo; an unrelated colleague may not; a manager in scope may.
        $this->actingAs($mate->user);
        $this->spa()->get("/api/v1/work/{$id}/photos/before")->assertOk();
        $this->actingAs($this->userWithRole($this->org, []));
        $this->spa()->get("/api/v1/work/{$id}/photos/before")->assertNotFound();
        $this->actingAs($this->userWithRole($this->org, ['daily_work.view', 'daily_work.export'], PermissionScope::Departments, departmentIds: [$this->dept->id]));
        $this->spa()->get("/api/v1/work/{$id}/photos/before")->assertOk();
        $this->assertStringContainsString('"Principal office",'.$mate->employee_code.',400.00,500.00', $this->spa()->get('/api/v1/work/export')->streamedContent());

        // Removing the photo deletes the file.
        $this->actingAs($this->user);
        $this->spa()->deleteJson("/api/v1/me/work/{$id}/photos/before")->assertOk()->assertJsonPath('data.has_before_photo', false);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_old_work_records_import(): void
    {
        $other = Department::factory()->for($this->org)->create(['institution_id' => $this->school->id]);
        $outside = Employee::factory()->in($other)->create(['employee_code' => 'EMP-OUT']);
        $mate = Employee::factory()->in($this->dept)->create(['employee_code' => 'EMP-2']);
        $this->actingAs($this->userWithRole($this->org, ['daily_work.import', 'daily_work.view'], PermissionScope::Institutions, institutionIds: [$this->college->id]));
        $header = 'date,employee_code,title,description,hours,status,requested_by,team_codes,estimated_cost,actual_cost';

        $bad = UploadedFile::fake()->createWithContent('w.csv', "{$header}\n2026-06-20,EMP-1,Future,,,,,,,\n2026-01-05,EMP-OUT,Elsewhere,,,,,,,\n2026-01-05,EMP-1,Team,,,,,NOPE,,\n2026-01-05,EMP-1,Bad,,3.3,done,,,-1,\n");
        $rows = collect($this->spa()->post('/api/v1/work/import/preview', ['file' => $bad], ['Accept' => 'application/json'])->assertOk()->json('data.rows'));
        $this->assertArrayHasKey('date', $rows[0]['errors']);
        $this->assertArrayHasKey('employee_code', $rows[1]['errors']);
        $this->assertArrayHasKey('team_codes', $rows[2]['errors']);
        $this->assertEqualsCanonicalizing(['hours', 'status', 'estimated_cost'], array_keys($rows[3]['errors']));
        $this->spa()->post('/api/v1/work/import', ['file' => $bad], ['Accept' => 'application/json'])->assertStatus(422);

        // Old dates are fine: the edit window does not apply to imports.
        $good = UploadedFile::fake()->createWithContent('w.csv', "{$header}\n15/01/2025,emp-1,Cleaning,Classrooms,2,,Office,\"EMP-2, EMP-1\",,300\n");
        $this->spa()->post('/api/v1/work/import', ['file' => $good], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.imported', 1);
        $entry = WorkEntry::query()->with('team')->firstOrFail();
        $this->assertSame(['2025-01-15', 'completed', true, [$mate->id], $this->dept->id], [
            $entry->work_date->toDateString(), $entry->status, $entry->is_imported, $entry->team->pluck('id')->all(), $entry->department_id,
        ]);
        $this->assertFalse(WorkEntry::query()->where('employee_id', $outside->id)->exists());

        $this->actingAs($this->userWithRole($this->org, ['daily_work.view']));
        $this->spa()->get('/api/v1/work/import/template')->assertForbidden();
    }
}
