import { useQuery } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate, todayYmd } from '@/lib/format'
import { CAMPUS_METRICS, campusCsvUrl, fetchCampusComparison } from './api'
import { PrintButton } from './PrintButton'

/** Campuses side by side: one column per campus, one row per figure (erp/docs/REPORTS.md). */
export function CampusComparisonPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [p, setP] = useState(() => ({ from: `${todayYmd().slice(0, 7)}-01`, to: todayYmd() }))
  const q = useQuery({ queryKey: ['campus-comparison', user?.current_organization?.id, p], queryFn: () => fetchCampusComparison(p), enabled: p.from <= p.to })
  const d = q.data
  const rows = CAMPUS_METRICS.filter((m) => d?.metrics.includes(m) || (m === 'attendance_percent' && d?.metrics.includes('present_today')) || (m === 'work_completion_percent' && d?.metrics.includes('work_entries')))
  const percent = (m: string) => m.endsWith('_percent')
  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-end gap-3 print:hidden">
        <Field id="cc-from" label={t('audit.from')}>
          <Input id="cc-from" type="date" value={p.from} max={p.to} onChange={(e) => setP({ ...p, from: e.target.value })} />
        </Field>
        <Field id="cc-to" label={t('audit.to')}>
          <Input id="cc-to" type="date" value={p.to} min={p.from} onChange={(e) => setP({ ...p, to: e.target.value })} />
        </Field>
        <a href={campusCsvUrl(p)} className="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
          <Download aria-hidden className="size-4" />
          {t('attendance.downloadCsv')}
        </a>
        <PrintButton />
      </div>
      <header>
        <p className="text-sm text-slate-500">{user?.current_organization?.name}</p>
        <h1 className="text-2xl font-semibold">{t('reports.items.campuses.title')}</h1>
        {d ? <p className="text-sm text-slate-600">{t('reports.campusPeriod', { from: formatDate(d.from), to: formatDate(d.to), today: formatDate(d.today) })}</p> : null}
      </header>
      {q.isPending && p.from <= p.to ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {d && d.campuses.length === 0 ? <EmptyState message={t('reports.noCampuses')} /> : null}
      {d && d.campuses.length > 0 ? (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white print:overflow-visible print:border-0 dark:border-slate-800 dark:bg-slate-900">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left dark:border-slate-800">
                <th scope="col" className="px-3 py-2 font-medium text-slate-500">
                  <span className="sr-only">{t('reports.figure')}</span>
                </th>
                {d.campuses.map((c) => (
                  <th key={c.id} scope="col" className="px-3 py-2 text-right font-semibold">
                    {c.name}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {rows.map((m) => {
                const values = d.campuses.map((c) => c[m] ?? null)
                const best = Math.max(...values.map((v) => v ?? -1))
                return (
                  <tr key={m}>
                    <th scope="row" className="px-3 py-2 text-left font-medium">
                      {t(`reports.metrics.${m}`)}
                    </th>
                    {values.map((v, i) => (
                      <td key={d.campuses[i].id} className={percent(m) && v !== null && v === best && values.length > 1 ? 'px-3 py-2 text-right font-semibold tabular-nums text-brand-700' : 'px-3 py-2 text-right tabular-nums'}>
                        {v === null ? '—' : percent(m) ? `${v}%` : v}
                      </td>
                    ))}
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      ) : null}
    </div>
  )
}
