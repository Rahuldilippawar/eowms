<?php

namespace Database\Seeders;

use App\Enums\InstitutionType;
use App\Models\Department;
use App\Models\Institution;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Access\DefaultRoles;
use App\Support\Access\PermissionCatalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Development / test data only — never run against production.
     * Every account's password is "password".
     */
    public function run(): void
    {
        PermissionCatalog::sync();

        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Dev Admin', 'password' => 'password', 'is_active' => true, 'is_super_admin' => true],
        );

        $trust = $this->organization('Shivaji Education Trust', 'SET', 'SET');
        $college = $this->institution($trust, InstitutionType::College, 'Shivaji Arts College', 'SAC');
        $school = $this->institution($trust, InstitutionType::School, 'Shivaji High School', 'SHS');
        $it = $this->department($trust, null, 'IT', 'IT');
        $this->department($trust, null, 'Accounts', 'ACC');
        $physics = $this->department($trust, $college, 'Physics', 'PHY');
        $this->department($trust, $college, 'Library', 'LIB');
        $this->department($trust, $school, 'Primary Section', 'PRI');

        $hospital = $this->organization('Sanjeevani Hospital Trust', 'SHT', 'SHT');
        $this->department($hospital, null, 'Nursing', 'NUR');

        $this->user('orgadmin@example.com', 'Org Admin (SET)', $trust, 'org_admin');
        $this->user('principal@example.com', 'Principal (SAC)', $trust, 'principal', institutions: [$college->id]);
        $this->user('hod@example.com', 'HOD Physics', $trust, 'hod', departments: [$physics->id]);
        $this->user('staff@example.com', 'IT Staff', $trust, 'staff', departments: [$it->id]);
        $this->user('hospital.admin@example.com', 'Org Admin (SHT)', $hospital, 'org_admin');
    }

    private function organization(string $name, string $short, string $code): Organization
    {
        $organization = Organization::firstOrCreate(['code' => $code], ['name' => $name, 'short_name' => $short]);
        DefaultRoles::createFor($organization);

        return $organization;
    }

    private function institution(Organization $org, InstitutionType $type, string $name, string $code): Institution
    {
        return Institution::firstOrCreate(['organization_id' => $org->id, 'code' => $code], ['type' => $type, 'name' => $name]);
    }

    private function department(Organization $org, ?Institution $institution, string $name, string $code): Department
    {
        return Department::firstOrCreate(
            ['organization_id' => $org->id, 'institution_id' => $institution?->id, 'code' => $code],
            ['name' => $name],
        );
    }

    /**
     * @param  list<int>  $institutions
     * @param  list<int>  $departments
     */
    private function user(string $email, string $name, Organization $org, string $roleKey, array $institutions = [], array $departments = []): void
    {
        $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'is_active' => true]);
        $role = Role::where(['organization_id' => $org->id, 'key' => $roleKey])->firstOrFail();
        $assignment = RoleAssignment::firstOrCreate(['user_id' => $user->id, 'role_id' => $role->id], ['organization_id' => $org->id]);
        $assignment->institutions()->sync($institutions);
        $assignment->departments()->sync($departments);
    }
}
