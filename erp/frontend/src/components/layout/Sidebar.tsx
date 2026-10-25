import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/features/auth/AuthProvider'
import { cn } from '@/lib/utils'
import { NAVIGATION } from './navigation'

export function SidebarNav({ onNavigate }: { onNavigate?: () => void }) {
  const { t } = useTranslation()
  const { can, user } = useAuth()
  const sections = NAVIGATION.map((section) => ({
    ...section,
    items: section.items.filter(
      (item) => (item.superAdminOnly ? !!user?.is_super_admin : !item.permission || can(item.permission)) && !(item.module && user?.disabled_modules?.includes(item.module)),
    ),
  })).filter((section) => section.items.length > 0)

  return (
    <nav aria-label={t('nav.main')} className="space-y-6 px-3 py-4">
      {sections.map((section) => (
        <div key={section.titleKey}>
          <p className="px-3 pb-2 text-xs font-semibold tracking-wide text-slate-400 uppercase">{t(section.titleKey)}</p>
          <ul className="space-y-1">
            {section.items.map((item) => (
              <li key={item.to}>
                <NavLink
                  to={item.to}
                  end={item.to === '/' || item.to === '/organization' || item.to === '/employees'}
                  onClick={onNavigate}
                  className={({ isActive }) =>
                    cn(
                      'flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium',
                      isActive
                        ? 'bg-brand-50 text-brand-800 dark:bg-brand-800/30 dark:text-brand-100'
                        : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
                    )
                  }
                >
                  <item.icon aria-hidden className="size-4" />
                  {t(item.labelKey)}
                </NavLink>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </nav>
  )
}
