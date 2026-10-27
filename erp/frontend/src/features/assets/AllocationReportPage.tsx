import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchAllocationReport } from './api'
import { AssetsNav } from './AssetsNav'
import { ALLOCATION_LINKS } from './navLinks'

export function AllocationReportPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['allocation-report', user?.current_organization?.id], queryFn: fetchAllocationReport })
  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('assets.allocationReport')}</h1>
      <AssetsNav links={ALLOCATION_LINKS} />
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data ? (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
              [t('assets.stats.held'), q.data.held],
              [t('assets.stats.overdue'), q.data.overdue],
              [t('assets.stats.free'), q.data.free_items],
              [t('assets.stats.given30'), q.data.given_last_30_days],
            ].map(([label, value]) => (
              <div key={String(label)} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                <p className="text-lg font-semibold tabular-nums">{value}</p>
                <p className="text-xs text-slate-500">{label}</p>
              </div>
            ))}
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            {[
              [t('assets.byType'), q.data.by_type],
              [t('assets.byHolder'), q.data.by_holder],
            ].map(([title, rows]) => (
              <section key={String(title)} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <h2 className="mb-2 font-semibold">{String(title)}</h2>
                <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                  {(rows as { name: string; count: number }[]).map((r) => (
                    <li key={r.name} className="flex justify-between py-1">
                      <span>{r.name}</span>
                      <span className="tabular-nums">{r.count}</span>
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </div>
        </>
      ) : null}
    </div>
  )
}
