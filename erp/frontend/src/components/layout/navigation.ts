import { Building, Building2, KeyRound, LayoutDashboard, Network, Settings, Users, type LucideIcon } from 'lucide-react'

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
  { titleKey: 'nav.main', items: [{ to: '/', labelKey: 'nav.dashboard', icon: LayoutDashboard }] },
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
