import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchAnalytics } from './api'
import { ResidencyNav } from './ResidencyNav'

export function ResidencyAnalyticsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['residency-analytics', user?.current_organization?.id], queryFn: fetchAnalytics })

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('residency.analyticsTitle')}</h1>
      <ResidencyNav />
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data ? (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
              [t('residency.stats.occupancy'), `${q.data.occupancy_rate}%`],
              [t('residency.stats.rooms'), `${q.data.occupied_rooms} / ${q.data.rooms}`],
              [t('residency.stats.staffHoused'), String(q.data.staff_housed)],
              [t('residency.stats.occupants'), String(q.data.occupants)],
              [t('residency.stats.waiting'), String(q.data.waiting)],
              [t('residency.stats.guests'), String(q.data.guests_now)],
              [t('residency.stats.tenure'), q.data.avg_tenure_days === null ? '—' : t('residency.days', { count: q.data.avg_tenure_days })],
            ].map(([label, value]) => (
              <div key={label} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                <p className="text-lg font-semibold tabular-nums">{value}</p>
                <p className="text-xs text-slate-500">{label}</p>
              </div>
            ))}
          </div>
          <section aria-labelledby="ra-buildings" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="ra-buildings" className="mb-3 font-semibold">
              {t('residency.byBuilding')}
            </h2>
            <ul className="space-y-3">
              {q.data.by_building.map((b) => {
                const pct = b.rooms ? Math.round((b.occupied / b.rooms) * 100) : 0
                return (
                  <li key={b.id} className="text-sm">
                    <div className="flex justify-between">
                      <span className="font-medium">{b.name}</span>
                      <span className="tabular-nums">{t('residency.occupiedOf', { occupied: b.occupied, rooms: b.rooms })}</span>
                    </div>
                    <div className="mt-1 h-2 rounded-full bg-slate-100 dark:bg-slate-800" role="presentation">
                      <div className="h-2 rounded-full bg-brand-700" style={{ width: `${pct}%` }} />
                    </div>
                  </li>
                )
              })}
            </ul>
          </section>
        </>
      ) : null}
    </div>
  )
}
