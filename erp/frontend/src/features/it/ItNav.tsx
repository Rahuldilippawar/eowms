import { useTranslation } from 'react-i18next'
import { NavLink } from 'react-router-dom'
import { cn } from '@/lib/utils'

export function ItNav() {
  const { t } = useTranslation()
  const links: [string, string][] = [
    ['/it', 'it.tabs.dashboard'],
    ['/it/assets', 'it.tabs.assets'],
    ['/it/tickets', 'it.tabs.tickets'],
    ['/it/smartboards', 'it.tabs.smartboards'],
  ]
  return (
    <nav aria-label={t('nav.it')} className="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
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
