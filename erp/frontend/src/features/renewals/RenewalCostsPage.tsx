import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatMoney } from '@/lib/format'
import { fetchRenewalCosts } from './api'

/** Renewal cost by year and item type (past cycles and current ones), and what was paid each year. */
export function RenewalCostsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['renewal-costs', user?.current_organization?.id], queryFn: fetchRenewalCosts })

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const years = [...new Set(q.data.by_year_type.map((r) => r.year))]

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('renewals.costs')}</h1>
      <p className="text-sm text-slate-600 dark:text-slate-400">{t('renewals.costsHint')}</p>
      {years.length === 0 ? <EmptyState message={t('renewals.noCosts')} /> : null}
      {years.map((y) => {
        const rows = q.data.by_year_type.filter((r) => r.year === y)
        const paid = q.data.paid_by_year.find((p) => p.year === y)
        return (
          <section key={y} className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 className="mb-2 flex flex-wrap justify-between gap-2 font-semibold">
              <span>{y}</span>
              <span className="text-sm font-normal text-slate-600 dark:text-slate-400">
                {t('renewals.yearTotal', { total: formatMoney(rows.reduce((s, r) => s + r.total, 0)), paid: formatMoney(paid?.total ?? 0) })}
              </span>
            </h2>
            <table className="w-full text-left text-sm">
              <thead className="text-xs text-slate-500">
                <tr>
                  <th className="py-1">{t('renewals.itemType')}</th>
                  <th className="py-1 text-right">{t('renewals.cycles')}</th>
                  <th className="py-1 text-right">{t('renewals.cost')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.item_type} className="border-t border-slate-100 dark:border-slate-800">
                    <td className="py-1">{r.item_type}</td>
                    <td className="py-1 text-right tabular-nums">{r.count}</td>
                    <td className="py-1 text-right tabular-nums">{formatMoney(r.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        )
      })}
    </div>
  )
}
