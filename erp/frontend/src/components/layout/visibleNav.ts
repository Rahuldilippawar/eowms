import type { CurrentUser } from '@/features/auth/api'
import { NAVIGATION, type NavItem, type NavSection } from './navigation'

/** The navigation entries this user may open (sidebar and command palette). */
export function visibleNavigation(user: CurrentUser | null | undefined, can: (permission: string) => boolean): NavSection[] {
  const shown = (item: NavItem) =>
    (item.superAdminOnly ? !!user?.is_super_admin : !item.permission || item.permission.split('|').some(can) || (!!item.orFlag && !!user?.[item.orFlag])) &&
    !(item.module && user?.disabled_modules?.includes(item.module))
  return NAVIGATION.map((section) => ({ ...section, items: section.items.filter(shown) })).filter((section) => section.items.length > 0)
}
