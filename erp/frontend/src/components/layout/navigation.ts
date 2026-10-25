import { Building2, LayoutDashboard, Network, type LucideIcon } from 'lucide-react'

export interface NavItem {
  to: string
  labelKey: string
  icon: LucideIcon
  /** Shown only when the user holds this permission (the API enforces it regardless). */
  permission?: string
}

export interface NavSection {
  titleKey: string
  items: NavItem[]
}

/** Sidebar entries — only modules that actually exist are listed. */
export const NAVIGATION: NavSection[] = [
  { titleKey: 'nav.main', items: [{ to: '/', labelKey: 'nav.dashboard', icon: LayoutDashboard }] },
  {
    titleKey: 'nav.structure',
    items: [
      { to: '/institutions', labelKey: 'nav.institutions', icon: Building2, permission: 'institutions.view' },
      { to: '/departments', labelKey: 'nav.departments', icon: Network, permission: 'departments.view' },
    ],
  },
]
