import { useTranslation } from 'react-i18next'
import { NavLink } from 'react-router-dom'
import { useAuth } from '@/features/auth/AuthProvider'
import { cn } from '@/lib/utils'

/** Links between the estate office's residency pages. */
export function ResidencyNav() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const links: [string, string][] = [
    ['/residency', 'residency.tabs.rooms'],
    ['/residency/requests', 'residency.tabs.requests'],
    ['/residency/bills', 'residency.tabs.bills'],
    ['/residency/directory', 'residency.tabs.directory'],
    ['/residency/analytics', 'residency.tabs.analytics'],
    ...(can('residency.manage') ? ([['/residency/import', 'residency.tabs.import']] as [string, string][]) : []),
  ]
  return (
    <nav aria-label={t('nav.residency')} className="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
      {links.map(([to, key]) => (
        <NavLink
          key={to}
          to={to}
          end
          className={({ isActive }) =>
            cn('inline-flex min-h-9 items-center rounded-md px-3 text-sm font-medium', isActive ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')
          }
        >
          {t(key)}
        </NavLink>
      ))}
    </nav>
  )
}
