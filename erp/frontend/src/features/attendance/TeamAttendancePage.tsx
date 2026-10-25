import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatTime, todayYmd } from '@/lib/format'
import { cn } from '@/lib/utils'
import { fetchDay, fetchSummary, markAttendance, summaryCsvUrl, type AttendanceStatus, type DayRow } from './api'
import { StatusBadge } from './StatusBadge'

const STATUSES: AttendanceStatus[] = ['present', 'late', 'half_day', 'absent', 'leave']

function toHm(iso: string | null | undefined) {
  if (!iso) return ''
  const d = new Date(iso)
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

function MarkDialog({ row, date, onClose }: { row: DayRow | null; date: string; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [v, setV] = useState({ status: (row?.record?.status ?? 'present') as AttendanceStatus, check_in: toHm(row?.record?.check_in_at), check_out: toHm(row?.record?.check_out_at), remarks: row?.record?.remarks ?? '' })
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => markAttendance({ employee_id: row!.employee.id, date, status: v.status, check_in: v.check_in || null, check_out: v.check_out || null, remarks: v.remarks.trim() || null }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['attendance-day'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => setGeneral(splitServerErrors(e, [], t('common.actionFailed')).general),
  })

  return (
    <Modal
      open={row !== null}
      onOpenChange={(o) => !o && onClose()}
      title={t('attendance.markFor', { name: row?.employee.name ?? '' })}
      description={date}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={() => save.mutate()}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <div className="sm:col-span-2">
          <Field id="m-status" label={t('attendance.statusLabel')}>
            <Select id="m-status" value={v.status} onChange={(e) => setV({ ...v, status: e.target.value as AttendanceStatus })}>
              {STATUSES.map((s) => (
                <option key={s} value={s}>
                  {t(`attendance.status.${s}`)}
                </option>
              ))}
            </Select>
          </Field>
        </div>
        <Field id="m-in" label={t('attendance.checkInTime')}>
          <Input id="m-in" type="time" value={v.check_in} onChange={(e) => setV({ ...v, check_in: e.target.value })} />
        </Field>
        <Field id="m-out" label={t('attendance.checkOutTime')}>
          <Input id="m-out" type="time" value={v.check_out} onChange={(e) => setV({ ...v, check_out: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="m-remarks" label={t('requests.remarks')}>
            <Input id="m-remarks" value={v.remarks} maxLength={500} onChange={(e) => setV({ ...v, remarks: e.target.value })} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}

function DayView() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [date, setDate] = useState(todayYmd())
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<DayRow | null>(null)
  const debounced = useDebouncedValue(search.trim())
  const query = useQuery({
    queryKey: ['attendance-day', user?.current_organization?.id, date, debounced, page],
    queryFn: () => fetchDay({ date, search: debounced, page }),
    placeholderData: keepPreviousData,
  })
  const canMark = can('attendance.create') || can('attendance.edit')

  return (
    <section className="space-y-3">
      <div className="flex flex-col gap-2 sm:flex-row">
        <Input type="date" aria-label={t('attendance.date')} className="sm:w-48" value={date} max={todayYmd()} onChange={(e) => e.target.value && (setDate(e.target.value), setPage(1))} />
        <Input type="search" aria-label={t('common.search')} placeholder={t('common.search')} className="sm:w-72" value={search} onChange={(e) => (setSearch(e.target.value), setPage(1))} />
      </div>
      <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        {query.isPending ? (
          <LoadingState />
        ) : query.isError ? (
          <ErrorState onRetry={() => void query.refetch()} />
        ) : query.data.data.length === 0 ? (
          <EmptyState message={t('attendance.noEmployees')} />
        ) : (
          <>
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {query.data.data.map((row) => (
                <li key={row.employee.id} className="flex flex-col gap-2 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                  <div className="text-sm">
                    <p className="font-medium">{row.employee.name}</p>
                    <p className="text-slate-500">
                      {row.employee.employee_code} · {row.employee.department}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-3 text-sm">
                    <StatusBadge status={row.record?.status ?? null} />
                    <span className="text-slate-500">
                      {formatTime(row.record?.check_in_at)} – {formatTime(row.record?.check_out_at)}
                    </span>
                    {canMark ? (
                      <Button variant="ghost" className="min-h-8 px-2 py-1 text-xs" aria-label={t('attendance.markFor', { name: row.employee.name })} onClick={() => setEditing(row)}>
                        {row.record ? t('common.edit') : t('attendance.mark')}
                      </Button>
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
            <Pagination meta={query.data.meta} onPage={setPage} />
          </>
        )}
      </div>
      {editing ? <MarkDialog key={`${editing.employee.id}-${date}`} row={editing} date={date} onClose={() => setEditing(null)} /> : null}
    </section>
  )
}

function ReportView() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const firstOfMonth = `${todayYmd().slice(0, 8)}01`
  const [p, setP] = useState({ from: firstOfMonth, to: todayYmd(), group_by: 'department' as 'employee' | 'department' | 'institution' })
  const query = useQuery({ queryKey: ['attendance-summary', user?.current_organization?.id, p], queryFn: () => fetchSummary(p), enabled: p.from <= p.to })

  return (
    <section className="space-y-3">
      <div className="grid gap-2 sm:grid-cols-4">
        <Field id="r-from" label={t('leave.from')}>
          <Input id="r-from" type="date" value={p.from} onChange={(e) => setP({ ...p, from: e.target.value })} />
        </Field>
        <Field id="r-to" label={t('leave.to')}>
          <Input id="r-to" type="date" value={p.to} onChange={(e) => setP({ ...p, to: e.target.value })} />
        </Field>
        <Field id="r-group" label={t('attendance.groupBy')}>
          <Select id="r-group" value={p.group_by} onChange={(e) => setP({ ...p, group_by: e.target.value as typeof p.group_by })}>
            <option value="employee">{t('attendance.groups.employee')}</option>
            <option value="department">{t('attendance.groups.department')}</option>
            <option value="institution">{t('attendance.groups.institution')}</option>
          </Select>
        </Field>
        {can('attendance.export') ? (
          <div className="flex items-end">
            <Button asChild variant="secondary" className="w-full">
              <a href={summaryCsvUrl(p)}>
                <Download aria-hidden className="size-4" />
                {t('attendance.downloadCsv')}
              </a>
            </Button>
          </div>
        ) : null}
      </div>
      <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        {query.isPending ? (
          <LoadingState />
        ) : query.isError ? (
          <ErrorState onRetry={() => void query.refetch()} />
        ) : query.data.length === 0 ? (
          <EmptyState message={t('attendance.noRecords')} />
        ) : (
          <table className="w-full min-w-[32rem] text-left text-sm">
            <thead className="border-b border-slate-200 text-xs text-slate-500 uppercase dark:border-slate-800">
              <tr>
                <th scope="col" className="px-2 py-2">
                  {t(`attendance.groups.${p.group_by}`)}
                </th>
                {STATUSES.map((s) => (
                  <th key={s} scope="col" className="px-2 py-2 text-right">
                    {t(`attendance.status.${s}`)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {query.data.map((r) => (
                <tr key={`${r.id}`}>
                  <td className="px-2 py-2">{r.label ?? t('org.central')}</td>
                  {STATUSES.map((s) => (
                    <td key={s} className="px-2 py-2 text-right tabular-nums">
                      {r[s]}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </section>
  )
}

export function TeamAttendancePage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<'day' | 'report'>('day')
  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('nav.teamAttendance')}</h1>
      <div role="tablist" className="inline-flex rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
        {(['day', 'report'] as const).map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={tab === k}
            onClick={() => setTab(k)}
            className={cn('min-h-9 rounded-md px-4 text-sm font-medium', tab === k ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}
          >
            {t(`attendance.tabs.${k}`)}
          </button>
        ))}
      </div>
      {tab === 'day' ? <DayView /> : <ReportView />}
    </div>
  )
}
