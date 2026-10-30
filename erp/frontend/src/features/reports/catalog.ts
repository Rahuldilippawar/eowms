import { BarChart3, Building2, CalendarDays, Cctv, ClipboardList, FileText, Hammer, IndianRupee, KeyRound, Archive, Mail, Monitor, Package, PieChart, Printer, RefreshCw, Scale, Wrench, House, type LucideIcon } from 'lucide-react'
import type { NavItem } from '@/components/layout/navigation'

export interface ReportEntry extends Pick<NavItem, 'permission' | 'module' | 'orFlag'> {
  to: string
  /** reports.items.<key>.title / .description */
  key: string
  icon: LucideIcon
  group: 'people' | 'work' | 'money' | 'assets' | 'organization'
}

/** Every report page of the app, for the reports hub (erp/docs/REPORTS.md). */
export const REPORTS: ReportEntry[] = [
  { to: '/attendance', key: 'attendance', icon: CalendarDays, group: 'people', permission: 'attendance.view', module: 'attendance' },
  { to: '/leave-balances', key: 'leaveBalances', icon: Scale, group: 'people', permission: 'leave.view', module: 'leave' },
  { to: '/reports/salary', key: 'salary', icon: IndianRupee, group: 'people', permission: 'employees.sensitive', module: 'attendance' },
  { to: '/performance', key: 'performance', icon: BarChart3, group: 'people', module: 'performance' },
  { to: '/work/print', key: 'workPrint', icon: Printer, group: 'work', permission: 'daily_work.view', module: 'daily_work' },
  { to: '/work/materials', key: 'materials', icon: Package, group: 'work', permission: 'daily_work.view', module: 'daily_work' },
  { to: '/requests/report', key: 'requests', icon: Wrench, group: 'work', permission: 'requests.view', module: 'requests' },
  { to: '/maintenance/report', key: 'maintenance', icon: Hammer, group: 'work', permission: 'maintenance.view', module: 'maintenance' },
  { to: '/letters/register', key: 'letters', icon: Mail, group: 'work', permission: 'letters.view', module: 'letters' },
  { to: '/finance/budgets', key: 'budgets', icon: PieChart, group: 'money', permission: 'finance.view', module: 'finance' },
  { to: '/renewals/costs', key: 'renewalCosts', icon: RefreshCw, group: 'money', permission: 'renewals.view', module: 'renewals' },
  { to: '/residency/analytics', key: 'residency', icon: House, group: 'money', permission: 'residency.view', module: 'residency' },
  { to: '/assets/dead-stock/report', key: 'deadStock', icon: Archive, group: 'assets', permission: 'assets.view', module: 'assets' },
  { to: '/assets/allocations/report', key: 'allocations', icon: KeyRound, group: 'assets', permission: 'assets.view', module: 'assets' },
  { to: '/store/issues', key: 'storeIssues', icon: ClipboardList, group: 'assets', permission: 'store.view', module: 'store' },
  { to: '/it/smartboards', key: 'smartboards', icon: Monitor, group: 'assets', permission: 'it.view', module: 'it', orFlag: 'it_lab_assistant' },
  { to: '/cctv/report', key: 'cctv', icon: Cctv, group: 'assets', permission: 'cctv.view|cctv.check|cctv.manage', module: 'cctv' },
  { to: '/reports/campuses', key: 'campuses', icon: Building2, group: 'organization', permission: 'institutions.view' },
  { to: '/audit', key: 'activity', icon: FileText, group: 'organization', permission: 'audit.view' },
]

export const REPORT_GROUPS = ['people', 'work', 'money', 'assets', 'organization'] as const
