import { Building, Building2, CalendarClock, CalendarDays, ClipboardCheck, ClipboardList, GraduationCap, IdCard, KeyRound, LayoutDashboard, Network, Plane, Settings, Users, type LucideIcon } from 'lucide-react'

export interface NavItem {
  to: string
  labelKey: string
  icon: LucideIcon
  /** Shown only when the user holds this permission (the API enforces it regardless). */
  permission?: string
  superAdminOnly?: boolean
}

export interface NavSection {
  titleKey: string
  items: NavItem[]
}

/** Sidebar entries — only modules that actually exist are listed. */
export const NAVIGATION: NavSection[] = [
  {
    titleKey: 'nav.main',
    items: [
      { to: '/', labelKey: 'nav.dashboard', icon: LayoutDashboard },
      { to: '/my/attendance', labelKey: 'nav.myAttendance', icon: CalendarClock },
      { to: '/my/leave', labelKey: 'nav.myLeave', icon: Plane },
      // Reporting officers approve without holding leave.approve, so this entry is shown to everyone.
      { to: '/approvals', labelKey: 'nav.approvals', icon: ClipboardCheck },
    ],
  },
  {
    titleKey: 'nav.people',
    items: [
      { to: '/employees', labelKey: 'nav.employees', icon: IdCard, permission: 'employees.view' },
      { to: '/students', labelKey: 'nav.students', icon: GraduationCap, permission: 'students.view' },
      { to: '/attendance', labelKey: 'nav.teamAttendance', icon: CalendarDays, permission: 'attendance.view' },
      { to: '/leave-types', labelKey: 'nav.leaveTypes', icon: ClipboardList, permission: 'leave.manage_types' },
    ],
  },
  {
    titleKey: 'nav.structure',
    items: [
      { to: '/institutions', labelKey: 'nav.institutions', icon: Building2, permission: 'institutions.view' },
      { to: '/departments', labelKey: 'nav.departments', icon: Network, permission: 'departments.view' },
    ],
  },
  {
    titleKey: 'nav.administration',
    items: [
      { to: '/users', labelKey: 'nav.users', icon: Users, permission: 'users.view' },
      { to: '/roles', labelKey: 'nav.roles', icon: KeyRound, permission: 'roles.view' },
      { to: '/organization', labelKey: 'nav.organizationSettings', icon: Settings, permission: 'organizations.edit' },
      { to: '/admin/organizations', labelKey: 'nav.allOrganizations', icon: Building, superAdminOnly: true },
    ],
  },
]
