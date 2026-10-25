import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Download, FileText } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { addDays, formatDate, monthYm, todayYmd } from '@/lib/format'
import { cn } from '@/lib/utils'
import { fetchGrid, fetchMovement, gridCsvUrl, movementCsvUrl, type DayCode } from './parityApi'

/** Each code is printed as letters; the tint only helps scanning. */
const TONE: Record<string, string> = {
  P: 'bg-emerald-50 text-emerald-800',
  L: 'bg-amber-50 text-amber-800',
  H: 'bg-orange-50 text-orange-800',
  A: 'bg-red-50 text-red-800',
  LV: 'bg-blue-50 text-blue-800',
  WO: 'bg-slate-100 text-slate-500',
  HO: 'bg-violet-50 text-violet-800',
  '-': 'text-slate-400',
}
export const CODES = ['P', 'L', 'H', 'A', 'LV', 'WO', 'HO', '-'] as const

export function CodeLegend() {
  const { t } = useTranslation()
  return (
    <ul className="flex flex-wrap gap-2 text-xs" aria-label={t('grid.legend')}>
      {CODES.map((c) => (
        <li key={c} className="flex items-center gap-1">
          <span className={cn('inline-flex min-w-7 justify-center rounded px-1 font-mono font-semibold', TONE[c])}>{c}</span>
          {t(`grid.codes.${c === '-' ? 'none' : c}`)}
        </li>
      ))}
    </ul>
  )
}

export function Cell({ code }: { code: DayCode }) {
  return <span className={cn('inline-flex min-w-7 justify-center rounded px-0.5 font-mono text-[11px] font-semibold', code ? TONE[code] : '')}>{code ?? ''}</span>
}

export function GridView() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [month, setMonth] = useState(monthYm())
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const debounced = useDebouncedValue(search.trim())
  const q = useQuery({ queryKey: ['attendance-grid', user?.current_organization?.id, month, debounced, page], queryFn: () => fetchGrid({ month, search: debounced, page }), placeholderData: keepPreviousData })
  const dates = q.data?.data[0] ? Object.keys(q.data.data[0].days) : []

  return (
    <section className="space-y-3">
      <div className="flex flex-wrap items-end gap-3">
        <Field id="g-month" label={t('attendance.month')}>
          <Input
            id="g-month"
            type="month"
            value={month}
            onChange={(e) => {
              setMonth(e.target.value)
              setPage(1)
            }}
          />
        </Field>
        <Field id="g-search" label={t('common.search')}>
          <Input
            id="g-search"
            type="search"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
        </Field>
        {can('attendance.export') ? (
          <a href={gridCsvUrl(month)} className="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
            <Download aria-hidden className="size-4" />
            {t('attendance.downloadCsv')}
          </a>
        ) : null}
      </div>
      <CodeLegend />
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.data.length === 0 ? <EmptyState message={t('attendance.noEmployees')} /> : null}
      {q.data && q.data.data.length > 0 ? (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <table className="text-left text-xs">
            <thead className="bg-slate-50 text-slate-500 dark:bg-slate-800">
              <tr>
                <th className="sticky left-0 bg-slate-50 px-2 py-2 dark:bg-slate-800">{t('performance.employee')}</th>
                {dates.map((d) => (
                  <th key={d} className="px-0.5 py-2 text-center font-mono">
                    {Number(d.slice(8))}
                  </th>
                ))}
                <th className="px-2 py-2 text-right">{t('grid.codes.P')}</th>
                <th className="px-2 py-2 text-right">{t('grid.codes.A')}</th>
                <th className="px-2 py-2 text-right">{t('grid.codes.LV')}</th>
                <th className="px-2 py-2"></th>
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((r) => (
                <tr key={r.employee.id} className="border-t border-slate-100 dark:border-slate-800">
                  <th scope="row" className="sticky left-0 max-w-40 truncate bg-white px-2 py-1.5 font-medium dark:bg-slate-900">
                    {r.employee.name}
                  </th>
                  {dates.map((d) => (
                    <td key={d} className="px-0.5 py-1 text-center">
                      <Cell code={r.days[d]} />
                    </td>
                  ))}
                  <td className="px-2 text-right tabular-nums">{r.totals.present}</td>
                  <td className="px-2 text-right tabular-nums">{r.totals.absent}</td>
                  <td className="px-2 text-right tabular-nums">{r.totals.leave + r.totals.unpaid_leave}</td>
                  <td className="px-2">
                    <Link
                      to={`/attendance/certificate/${r.employee.id}?from=${q.data.from}&to=${q.data.to}`}
                      className="inline-flex text-brand-700"
                      title={t('certificate.title')}
                      aria-label={`${t('certificate.title')}: ${r.employee.name}`}
                    >
                      <FileText aria-hidden className="size-4" />
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {q.data ? <Pagination meta={q.data.meta} onPage={setPage} /> : null}
    </section>
  )
}

export function MovementView() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [to, setTo] = useState(todayYmd())
  const [from, setFrom] = useState(addDays(todayYmd(), -6))
  const [page, setPage] = useState(1)
  const q = useQuery({ queryKey: ['attendance-movement', user?.current_organization?.id, from, to, page], queryFn: () => fetchMovement({ from, to, page }), placeholderData: keepPreviousData })

  return (
    <section className="space-y-3">
      <div className="flex flex-wrap items-end gap-3">
        <Field id="mv-from" label={t('leave.from')}>
          <Input id="mv-from" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
        </Field>
        <Field id="mv-to" label={t('leave.to')}>
          <Input id="mv-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
        </Field>
        {can('attendance.export') ? (
          <a href={movementCsvUrl({ from, to })} className="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
            <Download aria-hidden className="size-4" />
            {t('attendance.downloadCsv')}
          </a>
        ) : null}
      </div>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.data.length === 0 ? <EmptyState message={t('attendance.noRecords')} /> : null}
      {q.data && q.data.data.length > 0 ? (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <table className="w-full min-w-[36rem] text-left text-sm">
            <thead className="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800">
              <tr>
                <th className="px-3 py-2">{t('attendance.date')}</th>
                <th className="px-3 py-2">{t('performance.employee')}</th>
                <th className="px-3 py-2">{t('attendance.checkInTime')}</th>
                <th className="px-3 py-2">{t('attendance.checkOutTime')}</th>
                <th className="px-3 py-2 text-right">{t('grid.hours')}</th>
                <th className="px-3 py-2">{t('attendance.statusLabel')}</th>
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((r, i) => (
                <tr key={`${r.employee.id}-${r.work_date}-${i}`} className="border-t border-slate-100 dark:border-slate-800">
                  <td className="px-3 py-1.5">{formatDate(r.work_date)}</td>
                  <td className="px-3 py-1.5">{r.employee.name}</td>
                  <td className="px-3 py-1.5 font-mono">{r.in ?? '—'}</td>
                  <td className="px-3 py-1.5 font-mono">
                    {r.out ?? '—'}
                    {r.out_next_day ? ` (${t('grid.nextDay')})` : ''}
                  </td>
                  <td className="px-3 py-1.5 text-right tabular-nums">{r.hours ?? '—'}</td>
                  <td className="px-3 py-1.5">
                    {t(`attendance.status.${r.status}`)} · <span className="text-slate-500">{t(`grid.sources.${r.source}`, r.source)}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {q.data ? <Pagination meta={q.data.meta} onPage={setPage} /> : null}
    </section>
  )
}
