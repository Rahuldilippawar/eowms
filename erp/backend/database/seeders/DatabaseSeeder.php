<?php

namespace Database\Seeders;

use App\Enums\InstitutionType;
use App\Models\ApprovalWorkflow;
use App\Models\Building;
use App\Models\CustomField;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelResident;
use App\Models\HostelRoom;
use App\Models\HostelStaff;
use App\Models\Institution;
use App\Models\LetterCategory;
use App\Models\MaintenanceLocation;
use App\Models\Organization;
use App\Models\RenewalItem;
use App\Models\ResidencyOccupant;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Access\DefaultRoles;
use App\Support\Access\PermissionCatalog;
use App\Support\Numbering\NumberSequence;
use Carbon\CarbonImmutable;
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
        $transport = $this->department($trust, null, 'Transport', 'TRP');
        $maintenance = $this->department($trust, null, 'Maintenance', 'MNT');
        $hostelDept = $this->department($trust, null, 'Hostel', 'HOS');

        $hospital = $this->organization('Sanjeevani Hospital Trust', 'SHT', 'SHT');
        $this->department($hospital, null, 'Nursing', 'NUR');

        $admin = $this->user('orgadmin@example.com', 'Org Admin (SET)', $trust, 'org_admin');
        $principal = $this->user('principal@example.com', 'Principal (SAC)', $trust, 'principal', institutions: [$college->id]);
        $hod = $this->user('hod@example.com', 'HOD Physics', $trust, 'hod', departments: [$physics->id]);
        $staff = $this->user('staff@example.com', 'IT Staff', $trust, 'staff', departments: [$it->id]);
        $this->user('hospital.admin@example.com', 'Org Admin (SHT)', $hospital, 'org_admin');
        $teacher = $this->user('teacher@example.com', 'Physics Teacher', $trust, 'staff', departments: [$physics->id]);
        $driver = $this->user('driver@example.com', 'Bus Driver', $trust, 'staff', departments: [$transport->id]);
        $warden = $this->user('warden@example.com', 'Hostel Warden', $trust, 'staff', departments: [$hostelDept->id]);

        // People records (linked to the accounts above where they log in).
        $ePrincipal = $this->employee($trust, $principal, 'EMP-001', 'Principal (SAC)', 'Principal', $college->departments()->where('code', 'LIB')->first() ?? $physics);
        $eHod = $this->employee($trust, $hod, 'EMP-002', 'HOD Physics', 'Head of Department', $physics, $ePrincipal);
        $eTeacher = $this->employee($trust, $teacher, 'EMP-003', 'Physics Teacher', 'Assistant Professor', $physics, $eHod);
        $this->employee($trust, $staff, 'EMP-004', 'IT Staff', 'System Administrator', $it);
        $this->employee($trust, $admin, 'EMP-005', 'Org Admin (SET)', 'Administrator', $it);
        $this->employee($trust, $driver, 'EMP-006', 'Bus Driver', 'Driver', $transport);
        $this->employee($trust, $warden, 'EMP-007', 'Hostel Warden', 'Warden', $hostelDept);
        $eTeacher->fill(['gender' => 'female', 'date_of_birth' => '1991-07-12', 'blood_group' => 'B+', 'employment_type' => 'permanent', 'teaching_mode' => 'teaching'])->save();
        // Organization-defined fields, as the old app's community fields became.
        foreach ([['ashram_resident', 'आश्रमात राहतात', 'checkbox', null], ['naam_dharak', 'नामधारक', 'checkbox', null], ['food_preference', 'आहार', 'select', ['शाकाहारी', 'सात्विक']]] as $i => [$key, $label, $type, $options]) {
            CustomField::firstOrCreate(['organization_id' => $trust->id, 'entity' => 'employee', 'key' => $key], ['label' => $label, 'type' => $type, 'options' => $options, 'sort_order' => $i]);
        }

        foreach ([['2026-01-26', 'प्रजासत्ताक दिन'], ['2026-08-15', 'स्वातंत्र्य दिन'], ['2026-10-02', 'गांधी जयंती'], ['2026-11-09', 'दिवाळी']] as [$date, $name]) {
            Holiday::firstOrCreate(['organization_id' => $trust->id, 'institution_id' => null, 'holiday_date' => $date], ['name' => $name]);
        }

        // Correspondence: the usual categories, the HOD heads Physics, and a two-step approval.
        foreach (LetterCategory::DEFAULTS as $name) {
            LetterCategory::firstOrCreate(['organization_id' => $trust->id, 'name' => $name]);
        }
        $physics->forceFill(['head_user_id' => $hod->id])->save();
        $workflow = ApprovalWorkflow::firstOrCreate(['organization_id' => $trust->id, 'module' => 'letters', 'name' => 'विभाग-प्रमुख → प्राचार्य']);
        if (! $workflow->steps()->exists()) {
            $workflow->steps()->createMany([
                ['step_order' => 1, 'name' => 'विभाग-प्रमुखाकडे', 'approver_type' => 'origin_department_head'],
                ['step_order' => 2, 'name' => 'प्राचार्यांकडे (अंतिम मंजुरी)', 'approver_type' => 'user', 'user_id' => $principal->id],
            ]);
        }

        // A budget for Physics in the current financial year.
        [$fy] = NumberSequence::financialYear(CarbonImmutable::now('Asia/Kolkata'));
        DepartmentBudget::firstOrCreate(['organization_id' => $trust->id, 'department_id' => $physics->id, 'fy_start_year' => $fy], ['allocated_amount' => 200000]);

        // Two renewals: one due soon, one later in the year.
        $today = CarbonImmutable::now('Asia/Kolkata')->startOfDay();
        foreach ([[$it, 'Software License', null, 'Antivirus (50 users)', 20, 12000], [$physics, 'College Affiliation', 'AICTE', 'AICTE approval', 200, 25000]] as [$dept, $type, $body, $name, $days, $cost]) {
            RenewalItem::firstOrCreate(['organization_id' => $trust->id, 'item_name' => $name], [
                'institution_id' => $dept->institution_id, 'department_id' => $dept->id, 'item_type' => $type, 'regulatory_body' => $body,
                'expiry_date' => $today->addDays($days)->toDateString(), 'renewal_cost' => $cost, 'created_by' => $admin->id,
            ]);
        }

        // A college bus driven by the seeded driver, and a maintenance register with two rooms.
        Vehicle::firstOrCreate(['organization_id' => $trust->id, 'vehicle_number' => 'MH12AB1234'], [
            'department_id' => $transport->id, 'vehicle_type' => 'Bus', 'model' => 'Tata Starbus', 'passenger_capacity' => 40,
            'driver_user_id' => $driver->id, 'fuel_type' => 'diesel', 'current_odometer' => 45200, 'created_by' => $admin->id,
        ]);
        $maintenance->forceFill(['has_maintenance_register' => true])->save();
        $main = Building::firstOrCreate(['organization_id' => $trust->id, 'name' => 'Main Building'], ['institution_id' => $college->id]);
        foreach ([['Physics Lab', 'MB-101', 1], ['Staff Room', 'MB-002', 0]] as [$name, $code, $floor]) {
            MaintenanceLocation::firstOrCreate(['organization_id' => $trust->id, 'location_code' => $code], [
                'institution_id' => $college->id, 'building_id' => $main->id, 'name' => $name, 'floor' => $floor,
            ]);
        }

        // Staff quarters: the teacher lives in Q-101 with family; Q-102 is free.
        $quarters = Building::firstOrCreate(['organization_id' => $trust->id, 'name' => 'Staff Quarters A'], ['institution_id' => $college->id]);
        $q101 = MaintenanceLocation::firstOrCreate(['organization_id' => $trust->id, 'location_code' => 'SQ-101'], [
            'institution_id' => $college->id, 'building_id' => $quarters->id, 'name' => 'Q-101', 'capacity' => 4, 'is_residential' => true,
        ]);
        MaintenanceLocation::firstOrCreate(['organization_id' => $trust->id, 'location_code' => 'SQ-102'], [
            'institution_id' => $college->id, 'building_id' => $quarters->id, 'name' => 'Q-102', 'capacity' => 4, 'is_residential' => true,
        ]);
        $home = ResidencyOccupant::firstOrCreate(['organization_id' => $trust->id, 'user_id' => $teacher->id, 'status' => 'active'], [
            'location_id' => $q101->id, 'occupant_type' => 'staff', 'name' => 'Physics Teacher', 'moved_in_date' => '2026-06-01',
        ]);
        ResidencyOccupant::firstOrCreate(['organization_id' => $trust->id, 'family_of_id' => $home->id, 'name' => 'Asha'], [
            'location_id' => $q101->id, 'occupant_type' => 'family', 'relation' => 'Mother', 'age' => 62, 'moved_in_date' => '2026-06-01', 'status' => 'active',
        ]);

        // A boys' hostel looked after by the warden, with two rooms and two residents.
        $hostel = Hostel::firstOrCreate(['organization_id' => $trust->id, 'name' => 'Boys Hostel A'], ['department_id' => $hostelDept->id, 'gender' => 'boys', 'capacity' => 8, 'created_by' => $admin->id]);
        HostelStaff::firstOrCreate(['hostel_id' => $hostel->id, 'user_id' => $warden->id], ['role' => 'warden']);
        $rooms = collect(['101', '102'])->map(fn ($n) => HostelRoom::firstOrCreate(['hostel_id' => $hostel->id, 'room_number' => $n], ['capacity' => 4]));
        foreach ([['Suraj Kamble', 'ZP School No. 2', '7th', 'Thane'], ['Rohit Pawar', 'ZP School No. 2', '6th', 'Palghar']] as $i => [$name, $school, $class, $district]) {
            $resident = HostelResident::firstOrCreate(['hostel_id' => $hostel->id, 'name' => $name], [
                'school_name' => $school, 'class_grade' => $class, 'district' => $district, 'gender' => 'male', 'admission_date' => '2026-06-15', 'guardian_phone' => '98765432'.($i + 10),
            ]);
            HostelAllocation::firstOrCreate(['resident_id' => $resident->id, 'vacated_on' => null], ['room_id' => $rooms[0]->id, 'bed_label' => 'B'.($i + 1), 'allocated_on' => '2026-06-15']);
        }

        // A sample task from the HOD to the teacher.
        $task = Task::firstOrCreate(['organization_id' => $trust->id, 'title' => 'Prepare the physics lab inventory'], [
            'description' => 'List equipment that needs repair before the practical exams.', 'priority' => 'high',
            'due_date' => now('Asia/Kolkata')->addDays(3)->toDateString(), 'assignee_employee_id' => $eTeacher->id,
            'institution_id' => $physics->institution_id, 'department_id' => $physics->id, 'assigned_by' => $hod->id, 'status' => 'open',
        ]);
        if ($task->code === null) {
            $task->forceFill(['code' => sprintf('TSK-%05d', $task->id)])->save();
            TaskUpdate::create(['task_id' => $task->id, 'user_id' => $hod->id, 'type' => 'created', 'to_status' => 'open']);
        }
        foreach ([['STU-001', 'Asha Patil', '11'], ['STU-002', 'Rohan Jadhav', '12']] as [$code, $name, $class]) {
            Student::firstOrCreate(['organization_id' => $trust->id, 'student_code' => $code], ['institution_id' => $college->id, 'department_id' => $physics->id, 'name' => $name, 'class_name' => $class]);
        }
    }

    private function employee(Organization $org, ?User $user, string $code, string $name, string $designation, Department $department, ?Employee $officer = null): Employee
    {
        return Employee::firstOrCreate(['organization_id' => $org->id, 'employee_code' => $code], [
            'user_id' => $user?->id, 'name' => $name, 'designation' => $designation,
            'department_id' => $department->id, 'institution_id' => $department->institution_id,
            'reporting_officer_id' => $officer?->id, 'is_active' => true,
        ]);
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
    private function user(string $email, string $name, Organization $org, string $roleKey, array $institutions = [], array $departments = []): User
    {
        $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'is_active' => true]);
        $role = Role::where(['organization_id' => $org->id, 'key' => $roleKey])->firstOrFail();
        $assignment = RoleAssignment::firstOrCreate(['user_id' => $user->id, 'role_id' => $role->id], ['organization_id' => $org->id]);
        $assignment->institutions()->sync($institutions);
        $assignment->departments()->sync($departments);

        return $user;
    }
}
