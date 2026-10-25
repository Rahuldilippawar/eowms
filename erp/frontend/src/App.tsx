import type { ReactNode } from 'react'
import { Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/components/layout/AppLayout'
import { OrganizationsAdminPage } from '@/features/admin/OrganizationsAdminPage'
import { OrganizationSettingsPage } from '@/features/admin/OrganizationSettingsPage'
import { RoleEditorPage } from '@/features/admin/RoleEditorPage'
import { RolesPage } from '@/features/admin/RolesPage'
import { UserDetailPage } from '@/features/admin/UserDetailPage'
import { UsersPage } from '@/features/admin/UsersPage'
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage'
import { LoginPage } from '@/features/auth/LoginPage'
import { RequirePermission } from '@/features/auth/RequirePermission'
import { ResetPasswordPage } from '@/features/auth/ResetPasswordPage'
import { GuestOnly, RequireAuth } from '@/features/auth/RouteGuards'
import { DepartmentsPage } from '@/features/org/DepartmentsPage'
import { InstitutionsPage } from '@/features/org/InstitutionsPage'
import { DashboardPage } from '@/pages/DashboardPage'
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
      <Route element={<RequireAuth />}>
        <Route element={<AppLayout />}>
          <Route index element={<DashboardPage />} />
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
