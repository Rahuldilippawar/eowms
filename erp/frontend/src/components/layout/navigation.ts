import { LayoutDashboard, type LucideIcon } from 'lucide-react'

export interface NavItem {
  to: string
  labelKey: string
  icon: LucideIcon
}

export interface NavSection {
  titleKey: string
  items: NavItem[]
}

/**
 * Sidebar entries. Only modules that actually exist are listed — later phases
 * add theirs here, filtered by the user's permissions from the API.
 */
export const NAVIGATION: NavSection[] = [
  { titleKey: 'nav.main', items: [{ to: '/', labelKey: 'nav.dashboard', icon: LayoutDashboard }] },
]
