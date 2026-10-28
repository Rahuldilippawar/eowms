import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { fetchCctvReport } from './api'
import { CctvNav } from './CctvNav'

export function CctvReportPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [period, setPeriod] = useState<'weekly' | 'monthly'>('weekly')
  const q = useQuery({ queryKey: ['cctv-report', user?.current_organization?.id, period], queryFn: () => fetchCctvReport(period), placeholderData: keepPreviousData })
  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('cctv.reportTitle')}</h1>
      <CctvNav />
      <div role="tablist" className="inline-flex rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
        {(['weekly', 'monthly'] as const).map((p) => (
          <button
            key={p}
            role="tab"
            aria-selected={period === p}
            onClick={() => setPeriod(p)}
            className={cn('min-h-9 rounded-md px-4 text-sm font-medium', period === p ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}
          >
            {t(`cctv.period.${p}`)}
          </button>
        ))}
      </div>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data ? (
        <>
          <p className="text-sm text-slate-500">{t('cctv.between', { from: formatDate(q.data.from), to: formatDate(q.data.to) })}</p>
          {q.data.dvrs.length === 0 ? <EmptyState message={t('cctv.noDvrs')} /> : null}
          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            {q.data.dvrs.length ? (
              <table className="w-full text-sm">
                <thead className="text-left text-slate-500">
                  <tr>
                    <th className="px-3 py-2 font-medium">{t('cctv.dvrName')}</th>
                    <th className="px-3 py-2 text-right font-medium">{t('cctv.daysChecked')}</th>
                    <th className="px-3 py-2 text-right font-medium">{t('cctv.backups')}</th>
                    <th className="hidden px-3 py-2 text-right font-medium sm:table-cell">{t('cctv.maxStorage')}</th>
                    <th className="px-3 py-2 text-right font-medium">{t('cctv.cameraResults')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {q.data.dvrs.map((r) => (
                    <tr key={r.dvr.id}>
                      <td className="px-3 py-2">
                        <Link to={`/cctv/dvrs/${r.dvr.id}`} className="font-medium text-brand-700 hover:underline">
                          {r.dvr.name}
                        </Link>
                      </td>
                      <td className={cn('px-3 py-2 text-right tabular-nums', r.compliance < 80 && 'font-semibold text-red-700')}>
                        {r.checkups} / {q.data.days} ({r.compliance}%)
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums">{r.backups}</td>
                      <td className="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{r.max_storage === null ? '—' : `${r.max_storage}%`}</td>
                      <td className="px-3 py-2 text-right text-xs">{t('cctv.results', { working: r.working, down: r.not_working, attention: r.needs_attention })}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : null}
          </div>
        </>
      ) : null}
    </div>
  )
}
