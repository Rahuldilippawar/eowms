import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatMoney, todayYmd } from '@/lib/format'
import { fetchMaintenanceReport } from './api'
import { WorkTypeBadge } from './MaintenancePage'

/** April 1 of the current financial year. */
function fyStart(today: string): string {
  const [y, m] = today.split('-').map(Number)
  return `${m >= 4 ? y : y - 1}-04-01`
}

export function MaintenanceReportPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [from, setFrom] = useState(() => fyStart(todayYmd()))
  const [to, setTo] = useState(todayYmd)
  const q = useQuery({ queryKey: ['maintenance-report', user?.current_organization?.id, from, to], queryFn: () => fetchMaintenanceReport(from, to), enabled: !!from && !!to && from <= to })
  const r = q.data
  const th = 'px-3 py-2 text-left font-medium'
  const td = 'px-3 py-2'

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('nav.maintenanceReport')}</h1>
      <div className="flex flex-wrap items-end gap-3">
        <Field id="mr-from" label={t('vehicles.fromDate')}>
          <Input id="mr-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </Field>
        <Field id="mr-to" label={t('vehicles.toDate')}>
          <Input id="mr-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </Field>
      </div>
      {q.isPending && q.fetchStatus !== 'idle' ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {r ? (
        <>
          <div className="grid gap-3 sm:grid-cols-4">
            {r.by_work_type.map((w) => (
              <div key={w.work_type} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                <WorkTypeBadge type={w.work_type} />
                <p className="mt-1 text-lg font-semibold tabular-nums">{t('maintenance.entries', { count: w.entries })}</p>
                <p className="text-xs text-slate-500">{formatMoney(w.cost)}</p>
              </div>
            ))}
          </div>
          <section className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <h2 className="px-4 pt-3 font-semibold">{t('maintenance.byDepartment')}</h2>
            <table className="w-full text-sm">
              <thead className="text-xs text-slate-500">
                <tr>
                  <th className={th}>{t('people.department')}</th>
                  <th className={th}>{t('maintenance.entriesLabel')}</th>
                  <th className={th}>{t('finance.total')}</th>
                  <th className={th}>{t('finance.paid')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {r.by_department.map((d) => (
                  <tr key={d.id}>
                    <th scope="row" className={`${td} text-left font-medium`}>
                      {d.name}
                    </th>
                    <td className={td}>{d.entries}</td>
                    <td className={td}>{formatMoney(d.cost)}</td>
                    <td className={td}>{formatMoney(d.paid)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {r.by_department.length === 0 ? <p className="px-4 pb-3 text-sm text-slate-500">{t('maintenance.noEntries')}</p> : null}
          </section>
          <section className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <h2 className="px-4 pt-3 font-semibold">{t('maintenance.busiestLocations')}</h2>
            <table className="w-full text-sm">
              <thead className="text-xs text-slate-500">
                <tr>
                  <th className={th}>{t('maintenance.location')}</th>
                  <th className={th}>{t('maintenance.entriesLabel')}</th>
                  <th className={th}>{t('finance.total')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {r.by_location.map((l) => (
                  <tr key={l.id}>
                    <th scope="row" className={`${td} text-left font-medium`}>
                      {l.name} <span className="font-normal text-slate-500">({l.location_code})</span>
                    </th>
                    <td className={td}>{l.entries}</td>
                    <td className={td}>{formatMoney(l.cost)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        </>
      ) : null}
    </div>
  )
}
