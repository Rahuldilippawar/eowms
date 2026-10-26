import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatMoney, todayYmd } from '@/lib/format'
import { fetchRequestReport, STATUSES } from './api'

export function RequestReportPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [from, setFrom] = useState(() => `${todayYmd().slice(0, 8)}01`)
  const [to, setTo] = useState(todayYmd)
  const q = useQuery({ queryKey: ['request-report', user?.current_organization?.id, from, to], queryFn: () => fetchRequestReport(from, to), enabled: !!from && !!to && from <= to })
  const r = q.data
  const th = 'px-3 py-2 text-left font-medium'
  const td = 'px-3 py-2'

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('requests.report')}</h1>
      <div className="flex flex-wrap items-end gap-3">
        <Field id="rr-from" label={t('vehicles.fromDate')}>
          <Input id="rr-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </Field>
        <Field id="rr-to" label={t('vehicles.toDate')}>
          <Input id="rr-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </Field>
      </div>
      {q.isPending && q.fetchStatus !== 'idle' ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {r ? (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
            {STATUSES.map((s) => (
              <div key={s} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                <p className="text-2xl font-semibold tabular-nums">{r.by_status[s] ?? 0}</p>
                <p className="text-sm text-slate-600 dark:text-slate-400">{t(`requests.status.${s}`)}</p>
              </div>
            ))}
          </div>
          <section className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <table className="w-full text-sm">
              <thead className="text-xs text-slate-500">
                <tr>
                  <th className={th}>{t('requests.toDepartment')}</th>
                  <th className={th}>{t('requests.total')}</th>
                  <th className={th}>{t('requests.done')}</th>
                  <th className={th}>{t('requests.overdue')}</th>
                  <th className={th}>{t('requests.avgTime')}</th>
                  <th className={th}>{t('requests.cost')}</th>
                  <th className={th}>{t('requests.rating')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {r.by_department.map((d) => (
                  <tr key={d.id}>
                    <th scope="row" className={`${td} text-left font-medium`}>
                      {d.name}
                    </th>
                    <td className={td}>{d.total}</td>
                    <td className={td}>{d.done}</td>
                    <td className={`${td} ${d.overdue ? 'text-red-700' : ''}`}>{d.overdue}</td>
                    <td className={td}>{d.avg_hours === null ? '—' : t('requests.hours', { count: d.avg_hours })}</td>
                    <td className={td}>{formatMoney(d.cost)}</td>
                    <td className={td}>{d.avg_rating === null ? '—' : `${d.avg_rating} ★`}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {r.by_department.length === 0 ? <p className="px-4 py-3 text-sm text-slate-500">{t('requests.none')}</p> : null}
          </section>
        </>
      ) : null}
    </div>
  )
}
