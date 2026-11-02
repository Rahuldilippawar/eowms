<?php

namespace Tests\Feature\Api;

use App\Models\CustomField;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePrivate;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use PDO;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The importer against a real copy of the old app's schema (database/schema.sql with its demo data),
 * loaded into its own database on a separate connection, plus rows made for these tests.
 */
class LegacyImportTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private const DB = 'erp_legacy_test';

    private static bool $loaded = false;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->dir = storage_path('framework/testing/backups-'.uniqid());
        config(['erp.backups_path' => $this->dir, 'database.connections.legacy.database' => self::DB]);
        DB::purge('legacy');
        if (! self::$loaded) {
            $this->loadLegacy();
            self::$loaded = true;
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** A separate PDO, so creating the database does not touch the test's transaction. */
    private function loadLegacy(): void
    {
        $c = config('database.connections.mysql');
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('DROP DATABASE IF EXISTS '.self::DB);
        $pdo->exec('CREATE DATABASE '.self::DB.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE '.self::DB);
        // The old installs ran without strict mode (some seed labels are longer than their columns).
        $pdo->exec("SET SESSION sql_mode = ''");
        $schema = preg_replace('/^(CREATE DATABASE|USE) .*$/m', '', (string) file_get_contents(base_path('../../database/schema.sql')));
        $pdo->exec($schema);
        $hash = password_hash('old-secret-1', PASSWORD_BCRYPT);
        $pdo->exec("INSERT INTO users (id, organization_id, campus_id, department_id, employee_code, name, email, password, role, biometric_pin, aadhar_number, bank_account_no, salary, ashram_resident, food_preference, gender, marital_status, employment_type)
            VALUES (900, 1, 1, NULL, 'EMP-900', 'Asha Patil', 'Asha@Example.com', '{$hash}', 'staff', '77', '1234 5678 9012', '998877', 18000, 1, 'Veg', 'Female', 'Married', 'Contractor'),
                   (901, 1, 1, 1, 'EMP-901', 'Ravi Twin', 'ravi@example.com', '\$argon2id\$v=19\$m=65536,t=4,p=1\$abc\$def', 'coordinator', NULL, NULL, NULL, NULL, 0, 'Veg', 'Male', 'Single', 'Salaried'),
                   (902, 1, 1, 2, 'EMP-902', 'Principal Sir', 'principal.sir@example.com', '{$hash}', 'principal', NULL, NULL, NULL, NULL, 0, 'Veg', 'Male', 'Married', 'Salaried'),
                   (903, 1, 1, 2, 'EMP-903', 'Existing Person', 'already@erp.test', '{$hash}', 'staff', NULL, NULL, NULL, NULL, 0, 'Veg', 'Male', 'Single', 'Salaried')");
        $pdo->exec('INSERT INTO user_extra_department_access (user_id, department_id) VALUES (901, 3)');
        $pdo->exec("INSERT INTO employee_family_members (user_id, name, relation, resides_in_ashram) VALUES (900, 'Mira Patil', 'Daughter', 1)");
        $pdo->exec("INSERT INTO staff_achievements (user_id, title, achievement_date, added_by) VALUES (900, 'Best teacher', '2025-01-26', 902)");
        $pdo->exec("INSERT INTO students (organization_id, campus_id, department_id, roll_no, admission_no, name, year_of_study, academic_year, password_hash)
            VALUES (1, 1, 1, '7', 'ADM-7', 'Student One', 2, '2025-26', 'x')");
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_a_dry_run_writes_nothing_and_reports_what_would_happen(): void
    {
        $this->actingAs($this->superAdmin());
        $orgs = Organization::query()->count();
        $this->spa()->getJson('/api/v1/admin/legacy-import')->assertOk()->assertJsonPath('data.source.connected', true)->assertJsonPath('data.source.tables.users', 16);
        $run = $this->spa()->postJson('/api/v1/admin/legacy-import', ['mode' => 'dry_run'])->assertOk()->assertJsonPath('data.status', 'done')->json('data');

        $this->assertSame([2, 16, 16, 1], [$run['report']['organizations']['created'], $run['report']['users']['created'], $run['report']['employees']['created'], $run['report']['students']['created']]);
        $warnings = implode("\n", $run['report']['employees']['warnings']).implode("\n", $run['report']['users']['warnings']);
        $this->assertStringContainsString('#901: password is not bcrypt', $warnings);
        $this->assertStringContainsString('#900: had no department', $warnings);
        $this->assertSame($orgs, Organization::query()->count());
        $this->assertSame(0, DB::table('legacy_id_map')->count());
        $this->spa()->getJson("/api/v1/admin/legacy-import/runs/{$run['id']}")->assertOk()->assertJsonPath('data.mode', 'dry_run');
    }

    public function test_the_import_keeps_passwords_roles_private_details_and_runs_again_safely(): void
    {
        $existing = User::factory()->create(['email' => 'already@erp.test']);
        $admin = $this->superAdmin();
        $this->actingAs($this->userWithRole(Organization::factory()->create(), ['organizations.edit']));
        $this->spa()->postJson('/api/v1/admin/legacy-import', ['mode' => 'dry_run'])->assertForbidden();
        $this->actingAs($admin);
        $this->spa()->postJson('/api/v1/admin/legacy-import', ['mode' => 'import', 'password' => 'password', 'confirmation' => 'import'])->assertStatus(422);
        $run = $this->spa()->postJson('/api/v1/admin/legacy-import', ['mode' => 'import', 'password' => 'password', 'confirmation' => 'IMPORT'])
            ->assertOk()->assertJsonPath('data.status', 'done')->json('data');
        $this->assertStringEndsWith('-pre_import.sql.gz', $run['safety_backup']);
        $this->assertSame(1, $run['report']['users']['matched']);

        // The old password still works; the account joined by e-mail kept its own.
        $asha = User::query()->where('email', 'asha@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('old-secret-1', $asha->password));
        $this->assertTrue(User::query()->where('email', 'ravi@example.com')->value('must_change_password'));
        $this->assertNotSame(User::query()->where('email', 'already@erp.test')->value('password'), DB::table('users')->where('email', 'principal.sir@example.com')->value('password'));
        $this->assertSame($existing->id, Employee::withoutGlobalScopes()->where('name', 'Existing Person')->value('user_id'));

        $e = Employee::withoutGlobalScopes()->where('user_id', $asha->id)->firstOrFail();
        $this->assertSame(['contract', 'female', 'married', '77'], [$e->employment_type, $e->gender, $e->marital_status, $e->biometric_pin]);
        $this->assertSame('NODEPT', Department::withoutGlobalScopes()->find($e->department_id)->code);
        $this->assertSame(['ashram_resident' => true, 'food_preference' => 'Veg'], array_intersect_key($e->custom, array_flip(['ashram_resident', 'food_preference'])));
        $this->assertTrue(CustomField::withoutGlobalScopes()->where(['organization_id' => $e->organization_id, 'key' => 'naam_dharak'])->exists());
        $private = EmployeePrivate::query()->where('employee_id', $e->id)->firstOrFail();
        $this->assertSame('1234 5678 9012', $private->aadhaar_number);
        $this->assertStringNotContainsString('1234', (string) DB::table('employee_private')->where('employee_id', $e->id)->value('aadhaar_number'));
        $this->assertSame(['Mira Patil'], $e->familyMembers()->pluck('name')->all());

        $roles = fn (string $email) => RoleAssignment::withoutGlobalScopes()->whereHas('user', fn ($q) => $q->where('email', $email))->with('role')->get()
            ->map(fn ($a) => [$a->role->key, $a->departments()->count(), $a->institutions()->count()])->all();
        $this->assertSame([['staff', 0, 0]], $roles('asha@example.com'));
        $this->assertSame([['hod', 2, 0]], $roles('ravi@example.com'));
        $this->assertSame([['principal', 0, 1]], $roles('principal.sir@example.com'));
        $this->assertTrue((bool) User::query()->where('email', 'admin@shivajitrust.edu.in')->value('is_super_admin'));
        $this->assertSame('ADM-7', Student::withoutGlobalScopes()->where('name', 'Student One')->value('student_code'));

        // Again: nothing new.
        $counts = [User::query()->count(), Employee::withoutGlobalScopes()->count(), Department::withoutGlobalScopes()->count()];
        $again = $this->spa()->postJson('/api/v1/admin/legacy-import', ['mode' => 'import', 'password' => 'password', 'confirmation' => 'IMPORT'])->json('data.report');
        $this->assertSame([0, 16], [$again['users']['created'], $again['users']['existing']]);
        $this->assertSame($counts, [User::query()->count(), Employee::withoutGlobalScopes()->count(), Department::withoutGlobalScopes()->count()]);
    }
}
