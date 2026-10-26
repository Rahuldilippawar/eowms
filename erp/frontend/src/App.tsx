import type { ReactNode } from 'react'
import { Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/components/layout/AppLayout'
import { OrganizationsAdminPage } from '@/features/admin/OrganizationsAdminPage'
import { OrganizationSettingsPage } from '@/features/admin/OrganizationSettingsPage'
import { RoleEditorPage } from '@/features/admin/RoleEditorPage'
import { RolesPage } from '@/features/admin/RolesPage'
import { UserDetailPage } from '@/features/admin/UserDetailPage'
import { UsersPage } from '@/features/admin/UsersPage'
import { CertificatePage } from '@/features/attendance/CertificatePage'
import { DevicesPage } from '@/features/attendance/DevicesPage'
import { HolidaysPage } from '@/features/attendance/HolidaysPage'
import { SalaryReportPage } from '@/features/attendance/SalaryReportPage'
import { LeaveBalancesPage } from '@/features/leave/Balances'
import { MyAttendancePage } from '@/features/attendance/MyAttendancePage'
import { TeamAttendancePage } from '@/features/attendance/TeamAttendancePage'
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage'
import { LoginPage } from '@/features/auth/LoginPage'
import { RequirePermission } from '@/features/auth/RequirePermission'
import { ResetPasswordPage } from '@/features/auth/ResetPasswordPage'
import { GuestOnly, RequireAuth } from '@/features/auth/RouteGuards'
import { DashboardPage } from '@/features/dashboard/DashboardPage'
import { ApprovalsPage } from '@/features/leave/ApprovalsPage'
import { LeaveTypesPage } from '@/features/leave/LeaveTypesPage'
import { MyLeavePage } from '@/features/leave/MyLeavePage'
import { DepartmentsPage } from '@/features/org/DepartmentsPage'
import { InstitutionsPage } from '@/features/org/InstitutionsPage'
import { EmployeesPage } from '@/features/people/EmployeesPage'
import { StudentImportPage } from '@/features/people/StudentImportPage'
import { StudentsPage } from '@/features/people/StudentsPage'
import { NotificationsPage } from '@/features/notifications/NotificationsPage'
import { PerformancePage } from '@/features/performance/PerformancePage'
import { TaskDetailPage } from '@/features/tasks/TaskDetailPage'
import { TasksPage } from '@/features/tasks/TasksPage'
import { MyWorkPage } from '@/features/work/MyWorkPage'
import { BudgetsPage } from '@/features/finance/BudgetsPage'
import { PurchasesPage } from '@/features/finance/PurchasesPage'
import { LetterDetailPage } from '@/features/letters/LetterDetailPage'
import { RenewalCalendarPage } from '@/features/renewals/RenewalCalendarPage'
import { RenewalCostsPage } from '@/features/renewals/RenewalCostsPage'
import { RenewalDetailPage } from '@/features/renewals/RenewalDetailPage'
import { RenewalImportPage } from '@/features/renewals/RenewalImportPage'
import { RenewalsPage } from '@/features/renewals/RenewalsPage'
import { MaintenanceLocationPage } from '@/features/maintenance/MaintenanceLocationPage'
import { MaintenancePage } from '@/features/maintenance/MaintenancePage'
import { MaintenanceReportPage } from '@/features/maintenance/MaintenanceReportPage'
import { MaintenanceSettingsPage } from '@/features/maintenance/MaintenanceSettingsPage'
import { VehicleCorrectionsPage } from '@/features/vehicles/VehicleCorrectionsPage'
import { VehicleDetailPage } from '@/features/vehicles/VehicleDetailPage'
import { VehicleImportPage } from '@/features/vehicles/VehicleImportPage'
import { VehicleReportPage } from '@/features/vehicles/VehicleReportPage'
import { VehiclesPage } from '@/features/vehicles/VehiclesPage'
import { HostelAttendancePage } from '@/features/hostels/HostelAttendancePage'
import { HostelImportPage } from '@/features/hostels/HostelImportPage'
import { HostelPage } from '@/features/hostels/HostelPage'
import { HostelsPage } from '@/features/hostels/HostelsPage'
import { ResidentPage } from '@/features/hostels/ResidentPage'
import { GrievanceDetailPage } from '@/features/grievances/GrievanceDetailPage'
import { GrievancesPage } from '@/features/grievances/GrievancesPage'
import { PublicRequestPage } from '@/features/requests/PublicRequestPage'
import { RequestDetailPage } from '@/features/requests/RequestDetailPage'
import { RequestReportPage } from '@/features/requests/RequestReportPage'
import { RequestsPage } from '@/features/requests/RequestsPage'
import { TrackRequestPage } from '@/features/requests/TrackRequestPage'
import { LetterFormPage } from '@/features/letters/LetterFormPage'
import { LetterPrintPage } from '@/features/letters/LetterPrintPage'
import { LetterRegisterPage } from '@/features/letters/LetterRegisterPage'
import { LetterSettingsPage } from '@/features/letters/LetterSettingsPage'
import { LettersPage } from '@/features/letters/LettersPage'
import { VerifySignaturePage } from '@/features/letters/VerifySignaturePage'
import { TeamWorkPage } from '@/features/work/TeamWorkPage'
import { WorkImportPage } from '@/features/work/WorkImportPage'
import { CustomFieldsPage } from '@/features/admin/CustomFieldsPage'
import { ChangePasswordPage } from '@/features/auth/ChangePasswordPage'
import { EmployeeImportPage } from '@/features/people/EmployeeImportPage'
import { EmployeeProfilePage } from '@/features/people/EmployeeProfilePage'
import { IdCardsPage } from '@/features/people/IdCardsPage'
import { VerifyEmployeePage } from '@/features/people/VerifyEmployeePage'
import { NotFoundPage } from '@/pages/NotFoundPage'

const guard = (permission: string, page: ReactNode) => <RequirePermission permission={permission}>{page}</RequirePermission>

export function AppRoutes() {
  return (
    <Routes>
      <Route element={<GuestOnly />}>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
      </Route>
      <Route path="/verify/employee/:token" element={<VerifyEmployeePage />} />
      <Route path="/verify/signature/:code" element={<VerifySignaturePage />} />
      <Route path="/request/:org" element={<PublicRequestPage />} />
      <Route path="/request/:org/track" element={<TrackRequestPage />} />
      <Route element={<RequireAuth />}>
        <Route element={<AppLayout />}>
          <Route index element={<DashboardPage />} />
          <Route path="my/attendance" element={<MyAttendancePage />} />
          <Route path="my/leave" element={<MyLeavePage />} />
          <Route path="approvals" element={<ApprovalsPage />} />
          <Route path="my/work" element={<MyWorkPage />} />
          <Route path="tasks" element={<TasksPage />} />
          <Route path="tasks/:id" element={<TaskDetailPage />} />
          <Route path="notifications" element={<NotificationsPage />} />
          <Route path="performance" element={<PerformancePage />} />
          <Route path="work" element={guard('daily_work.view', <TeamWorkPage />)} />
          <Route path="finance/budgets" element={guard('finance.view', <BudgetsPage />)} />
          <Route path="finance/purchases" element={guard('finance.view', <PurchasesPage />)} />
          <Route path="renewals" element={guard('renewals.view', <RenewalsPage />)} />
          <Route path="renewals/calendar" element={guard('renewals.view', <RenewalCalendarPage />)} />
          <Route path="renewals/costs" element={guard('renewals.view', <RenewalCostsPage />)} />
          <Route path="renewals/import" element={guard('renewals.manage', <RenewalImportPage />)} />
          <Route path="renewals/:id" element={guard('renewals.view', <RenewalDetailPage />)} />
          {/* Drivers reach their own vehicle without vehicles.view; the API decides per vehicle. */}
          <Route path="vehicles" element={<VehiclesPage />} />
          <Route path="vehicles/corrections" element={<VehicleCorrectionsPage />} />
          <Route path="vehicles/import" element={guard('vehicles.manage', <VehicleImportPage />)} />
          <Route path="vehicles/:id" element={<VehicleDetailPage />} />
          <Route path="vehicles/:id/report" element={<VehicleReportPage />} />
          <Route path="requests" element={<RequestsPage />} />
          <Route path="requests/report" element={guard('requests.view', <RequestReportPage />)} />
          <Route path="requests/:id" element={<RequestDetailPage />} />
          <Route path="grievances" element={<GrievancesPage />} />
          <Route path="grievances/:id" element={<GrievanceDetailPage />} />
          {/* Hostel staff reach their own hostel without hostels.view; the API decides per hostel. */}
          <Route path="hostels" element={<HostelsPage />} />
          <Route path="hostels/:id" element={<HostelPage />} />
          <Route path="hostels/:id/attendance" element={<HostelAttendancePage />} />
          <Route path="hostels/:id/import" element={<HostelImportPage />} />
          <Route path="hostel-residents/:id" element={<ResidentPage />} />
          <Route path="maintenance" element={guard('maintenance.view', <MaintenancePage />)} />
          <Route path="maintenance/report" element={guard('maintenance.view', <MaintenanceReportPage />)} />
          <Route path="maintenance/settings" element={guard('maintenance.manage', <MaintenanceSettingsPage />)} />
          <Route path="maintenance/locations/:id" element={guard('maintenance.view', <MaintenanceLocationPage />)} />
          <Route path="letters" element={<LettersPage />} />
          <Route path="letters/new" element={<LetterFormPage />} />
          <Route path="letters/register" element={guard('letters.view', <LetterRegisterPage />)} />
          <Route path="letters/settings" element={guard('letters.manage', <LetterSettingsPage />)} />
          <Route path="letters/:id" element={<LetterDetailPage />} />
          <Route path="letters/:id/edit" element={<LetterFormPage />} />
          <Route path="letters/:id/print" element={<LetterPrintPage />} />
          <Route path="work/import" element={guard('daily_work.import', <WorkImportPage />)} />
          <Route path="attendance" element={guard('attendance.view', <TeamAttendancePage />)} />
          <Route path="employees" element={guard('employees.view', <EmployeesPage />)} />
          <Route path="employees/import" element={guard('employees.create', <EmployeeImportPage />)} />
          <Route path="employees/id-cards" element={guard('employees.view', <IdCardsPage />)} />
          <Route path="employees/:id" element={<EmployeeProfilePage />} />
          <Route path="my/profile" element={<EmployeeProfilePage mine />} />
          <Route path="account/password" element={<ChangePasswordPage />} />
          <Route path="organization/fields" element={guard('organizations.edit', <CustomFieldsPage />)} />
          <Route path="students" element={guard('students.view', <StudentsPage />)} />
          <Route path="students/import" element={guard('students.create', <StudentImportPage />)} />
          <Route path="holidays" element={<HolidaysPage />} />
          <Route path="leave-balances" element={guard('leave.view', <LeaveBalancesPage />)} />
          <Route path="attendance/certificate/:id" element={<CertificatePage />} />
          <Route path="my/attendance/certificate" element={<CertificatePage mine />} />
          <Route path="attendance/devices" element={guard('attendance.manage', <DevicesPage />)} />
          <Route path="reports/salary" element={guard('employees.sensitive', <SalaryReportPage />)} />
          <Route path="leave-types" element={guard('leave.manage_types', <LeaveTypesPage />)} />
          <Route path="institutions" element={guard('institutions.view', <InstitutionsPage />)} />
          <Route path="departments" element={guard('departments.view', <DepartmentsPage />)} />
          <Route path="users" element={guard('users.view', <UsersPage />)} />
          <Route path="users/:id" element={guard('users.view', <UserDetailPage />)} />
          <Route path="roles" element={guard('roles.view', <RolesPage />)} />
          <Route path="roles/:id" element={guard('roles.view', <RoleEditorPage />)} />
          <Route path="organization" element={guard('organizations.edit', <OrganizationSettingsPage />)} />
          <Route
            path="admin/organizations"
            element={
              <RequirePermission superAdmin>
                <OrganizationsAdminPage />
              </RequirePermission>
            }
          />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Route>
    </Routes>
  )
}
