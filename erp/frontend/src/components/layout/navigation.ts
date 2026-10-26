import { BarChart3, Bus, FilePenLine, PieChart, Wrench, CalendarHeart, Fingerprint, IndianRupee, Scale, SlidersHorizontal, UserRound, Building, ClipboardPen, Mail, RefreshCw, ShoppingCart, Wallet, ListChecks, NotebookPen, Building2, CalendarClock, CalendarDays, ClipboardCheck, ClipboardList, GraduationCap, IdCard, KeyRound, LayoutDashboard, Network, Plane, Settings, Users, type LucideIcon } from 'lucide-react'

export interface NavItem {
  to: string
  labelKey: string
  icon: LucideIcon
  /** Shown only when the user holds this permission (the API enforces it regardless). */
  permission?: string
  superAdminOnly?: boolean
  /** Hidden when the organization switched this module off. */
  module?: string
  /** Also shown to someone who drives an active vehicle. */
  orDriver?: boolean
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
      { to: '/my/profile', labelKey: 'nav.myProfile', icon: UserRound },
      { to: '/my/attendance', labelKey: 'nav.myAttendance', icon: CalendarClock, module: 'attendance' },
      { to: '/my/leave', labelKey: 'nav.myLeave', icon: Plane, module: 'leave' },
      { to: '/my/work', labelKey: 'nav.myWork', icon: NotebookPen, module: 'daily_work' },
      { to: '/tasks', labelKey: 'nav.tasks', icon: ListChecks, module: 'tasks' },
      { to: '/letters', labelKey: 'nav.letters', icon: Mail, module: 'letters' },
      // Reporting officers approve without holding leave.approve, so this entry is shown to everyone.
      { to: '/approvals', labelKey: 'nav.approvals', icon: ClipboardCheck },
      { to: '/performance', labelKey: 'nav.performance', icon: BarChart3, module: 'performance' },
    ],
  },
  {
    titleKey: 'nav.people',
    items: [
      { to: '/employees', labelKey: 'nav.employees', icon: IdCard, permission: 'employees.view' },
      { to: '/students', labelKey: 'nav.students', icon: GraduationCap, permission: 'students.view' },
      { to: '/attendance', labelKey: 'nav.teamAttendance', icon: CalendarDays, permission: 'attendance.view', module: 'attendance' },
      { to: '/work', labelKey: 'nav.teamWork', icon: ClipboardPen, permission: 'daily_work.view' },
      { to: '/leave-types', labelKey: 'nav.leaveTypes', icon: ClipboardList, permission: 'leave.manage_types' },
      { to: '/leave-balances', labelKey: 'nav.leaveBalances', icon: Scale, permission: 'leave.view', module: 'leave' },
      { to: '/holidays', labelKey: 'nav.holidays', icon: CalendarHeart, module: 'attendance' },
      { to: '/attendance/devices', labelKey: 'nav.devices', icon: Fingerprint, permission: 'attendance.manage', module: 'attendance' },
      { to: '/reports/salary', labelKey: 'nav.salaryReport', icon: IndianRupee, permission: 'employees.sensitive', module: 'attendance' },
    ],
  },
  {
    titleKey: 'nav.finance',
    items: [
      { to: '/finance/budgets', labelKey: 'nav.budgets', icon: Wallet, permission: 'finance.view', module: 'finance' },
      { to: '/finance/purchases', labelKey: 'nav.purchases', icon: ShoppingCart, permission: 'finance.view', module: 'finance' },
      { to: '/renewals', labelKey: 'nav.renewals', icon: RefreshCw, permission: 'renewals.view', module: 'renewals' },
    ],
  },
  {
    titleKey: 'nav.operations',
    items: [
      { to: '/vehicles', labelKey: 'nav.vehicles', icon: Bus, permission: 'vehicles.view', module: 'vehicles', orDriver: true },
      { to: '/vehicles/corrections', labelKey: 'nav.vehicleCorrections', icon: FilePenLine, permission: 'vehicles.manage', module: 'vehicles' },
      { to: '/maintenance', labelKey: 'nav.maintenance', icon: Wrench, permission: 'maintenance.view', module: 'maintenance' },
      { to: '/maintenance/report', labelKey: 'nav.maintenanceReport', icon: PieChart, permission: 'maintenance.view', module: 'maintenance' },
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
      { to: '/organization/fields', labelKey: 'nav.customFields', icon: SlidersHorizontal, permission: 'organizations.edit' },
      { to: '/admin/organizations', labelKey: 'nav.allOrganizations', icon: Building, superAdminOnly: true },
    ],
  },
]
