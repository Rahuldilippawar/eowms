import type { CurrentUser } from '@/features/auth/api'
import { NAVIGATION, type NavItem, type NavSection } from './navigation'

type Gate = Pick<NavItem, 'permission' | 'superAdminOnly' | 'module' | 'orFlag'>

/** Whether this user may open an entry: its permission (or one of `a|b`, or its flag) and its module switched on. */
export function canOpen(item: Gate, user: CurrentUser | null | undefined, can: (permission: string) => boolean): boolean {
  return (
    (item.superAdminOnly ? !!user?.is_super_admin : !item.permission || item.permission.split('|').some(can) || (!!item.orFlag && !!user?.[item.orFlag])) &&
    !(item.module && user?.disabled_modules?.includes(item.module))
  )
}

/** The navigation entries this user may open (sidebar and command palette). */
export function visibleNavigation(user: CurrentUser | null | undefined, can: (permission: string) => boolean): NavSection[] {
  return NAVIGATION.map((section) => ({ ...section, items: section.items.filter((item) => canOpen(item, user, can)) })).filter((section) => section.items.length > 0)
}
