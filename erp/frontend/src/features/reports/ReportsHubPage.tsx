import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState } from '@/components/data/ListStates'
import { canOpen } from '@/components/layout/visibleNav'
import { useAuth } from '@/features/auth/AuthProvider'
import { REPORT_GROUPS, REPORTS } from './catalog'

export function ReportsHubPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const mine = REPORTS.filter((r) => canOpen(r, user, can))
  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('nav.reports')}</h1>
        <p className="mt-1 text-sm text-slate-500">{t('reports.hubHint')}</p>
      </div>
      {mine.length === 0 ? <EmptyState message={t('reports.none')} /> : null}
      {REPORT_GROUPS.map((g) => {
        const items = mine.filter((r) => r.group === g)
        if (items.length === 0) return null
        return (
          <section key={g} aria-labelledby={`rg-${g}`} className="space-y-3">
            <h2 id={`rg-${g}`} className="text-sm font-semibold tracking-wide text-slate-500 uppercase">
              {t(`reports.groups.${g}`)}
            </h2>
            <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {items.map((r) => (
                <li key={r.to}>
                  <Link
                    to={r.to}
                    className="flex h-full gap-3 rounded-xl border border-slate-200 bg-white p-4 hover:border-brand-600 dark:border-slate-800 dark:bg-slate-900"
                  >
                    <r.icon aria-hidden className="mt-0.5 size-5 shrink-0 text-brand-700" />
                    <span>
                      <span className="block font-medium">{t(`reports.items.${r.key}.title`)}</span>
                      <span className="block text-sm text-slate-500">{t(`reports.items.${r.key}.description`)}</span>
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        )
      })}
    </div>
  )
}
