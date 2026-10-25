import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { fetchMyEmployee } from '@/features/attendance/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { todayYmd } from '@/lib/format'
import { fetchMyPerformance, fetchPerformance, performanceCsvUrl, type PerformanceRow } from './api'

const rate = (r: PerformanceRow) => (r.tasks.on_time_rate === null ? '—' : `${r.tasks.on_time_rate}%`)

function Stat({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
      <p className="text-2xl font-semibold tabular-nums">{value}</p>
      <p className="text-sm text-slate-500">{label}</p>
      {hint ? <p className="mt-1 text-xs text-slate-500">{hint}</p> : null}
    </div>
  )
}

export function PerformancePage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const orgKey = user?.current_organization?.id
  const [from, setFrom] = useState(`${todayYmd().slice(0, 8)}01`)
  const [to, setTo] = useState(todayYmd())
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const debounced = useDebouncedValue(search.trim())
  const me = useQuery({ queryKey: ['my-employee', orgKey], queryFn: fetchMyEmployee })
  const mine = useQuery({ queryKey: ['my-performance', orgKey, from, to], queryFn: () => fetchMyPerformance({ from, to }), enabled: !!me.data })
  const team = useQuery({
    queryKey: ['performance', orgKey, from, to, debounced, page],
    queryFn: () => fetchPerformance({ from, to, search: debounced, page }),
    enabled: can('performance.view'),
    placeholderData: keepPreviousData,
  })
  const m = mine.data

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <h1 className="text-2xl font-semibold">{t('nav.performance')}</h1>
      <div className="flex flex-wrap items-end gap-3">
        <Field id="pf-from" label={t('leave.from')}>
          <Input
            id="pf-from"
            type="date"
            value={from}
            max={to}
            onChange={(e) => {
              setFrom(e.target.value)
              setPage(1)
            }}
          />
        </Field>
        <Field id="pf-to" label={t('leave.to')}>
          <Input
            id="pf-to"
            type="date"
            value={to}
            min={from}
            onChange={(e) => {
              setTo(e.target.value)
              setPage(1)
            }}
          />
        </Field>
      </div>

      {me.data ? (
        <section aria-labelledby="pf-mine" className="space-y-3">
          <h2 id="pf-mine" className="font-semibold">
            {t('performance.mine')}
          </h2>
          {mine.isPending ? <LoadingState /> : null}
          {mine.isError ? <ErrorState onRetry={() => void mine.refetch()} /> : null}
          {m ? (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
              <Stat label={t('performance.daysPresent')} value={m.attendance.present} hint={t('performance.lateHint', { count: m.attendance.late })} />
              <Stat label={t('performance.leaveDays')} value={m.attendance.leave} />
              <Stat label={t('performance.workHours')} value={m.work.hours} hint={t('performance.daysLogged', { count: m.work.days_logged })} />
              <Stat label={t('performance.tasksAssigned')} value={m.tasks.assigned} />
              <Stat label={t('performance.tasksCompleted')} value={m.tasks.completed} hint={t('performance.overdueHint', { count: m.tasks.overdue })} />
              <Stat label={t('performance.onTimeRate')} value={rate(m)} />
            </div>
          ) : null}
        </section>
      ) : null}

      {can('performance.view') ? (
        <section aria-labelledby="pf-team" className="space-y-3">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <h2 id="pf-team" className="font-semibold">
              {t('performance.team')}
            </h2>
            <div className="flex flex-wrap items-end gap-3">
              <Field id="pf-search" label={t('common.search')}>
                <Input
                  id="pf-search"
                  type="search"
                  value={search}
                  onChange={(e) => {
                    setSearch(e.target.value)
                    setPage(1)
                  }}
                />
              </Field>
              {can('performance.export') ? (
                <a href={performanceCsvUrl({ from, to })} className="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
                  <Download aria-hidden className="size-4" />
                  {t('attendance.downloadCsv')}
                </a>
              ) : null}
            </div>
          </div>
          {team.isPending ? <LoadingState /> : null}
          {team.isError ? <ErrorState onRetry={() => void team.refetch()} /> : null}
          {team.data && team.data.data.length === 0 ? <EmptyState message={t('people.noEmployees')} /> : null}
          {team.data && team.data.data.length > 0 ? (
            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
              <table className="w-full min-w-[48rem] text-left text-sm">
                <thead className="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800">
                  <tr>
                    <th className="px-3 py-2">{t('performance.employee')}</th>
                    <th className="px-3 py-2 text-right">{t('performance.daysPresent')}</th>
                    <th className="px-3 py-2 text-right">{t('attendance.status.late')}</th>
                    <th className="px-3 py-2 text-right">{t('performance.leaveDays')}</th>
                    <th className="px-3 py-2 text-right">{t('attendance.status.absent')}</th>
                    <th className="px-3 py-2 text-right">{t('performance.workHours')}</th>
                    <th className="px-3 py-2 text-right">{t('performance.tasksAssigned')}</th>
                    <th className="px-3 py-2 text-right">{t('performance.tasksCompleted')}</th>
                    <th className="px-3 py-2 text-right">{t('tasks.overdue')}</th>
                    <th className="px-3 py-2 text-right">{t('performance.onTimeRate')}</th>
                  </tr>
                </thead>
                <tbody>
                  {team.data.data.map((r) => (
                    <tr key={r.employee.id} className="border-t border-slate-100 dark:border-slate-800">
                      <td className="px-3 py-2">
                        <p className="font-medium">{r.employee.name}</p>
                        <p className="text-xs text-slate-500">
                          {r.employee.employee_code}
                          {r.employee.department ? ` · ${r.employee.department}` : ''}
                        </p>
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums">{r.attendance.present}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{r.attendance.late}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{r.attendance.leave}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{r.attendance.absent}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{r.work.hours}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{r.tasks.assigned}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{r.tasks.completed}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{r.tasks.overdue}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{rate(r)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
          {team.data ? <Pagination meta={team.data.meta} onPage={setPage} /> : null}
          <p className="text-xs text-slate-500">{t('performance.note')}</p>
        </section>
      ) : null}
      {!me.data && !can('performance.view') && !me.isPending ? <EmptyState message={t('attendance.noEmployee')} /> : null}
    </div>
  )
}
