import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { fetchMyEmployee } from '@/features/attendance/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { actionErrorMessage } from '@/lib/forms'
import { addDays, formatDate, todayYmd } from '@/lib/format'
import { fetchTeamWork, reviewWork, workCsvUrl, type WorkEntry } from './api'
import { WorkEntryCard } from './MyWorkPage'

function ReviewButton({ entry }: { entry: WorkEntry }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [remarks, setRemarks] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const confirm = async () => {
    setBusy(true)
    setError(null)
    try {
      await reviewWork(entry.id, remarks.trim())
      void queryClient.invalidateQueries({ queryKey: ['team-work'] })
      toast.success(t('work.reviewedToast'))
      setOpen(false)
      setRemarks('')
    } catch (e) {
      setError(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <Button variant="secondary" className="min-h-8 px-3 py-1 text-xs" aria-label={`${t('work.review')}: ${entry.title}`} onClick={() => setOpen(true)}>
        {t('work.review')}
      </Button>
      <Modal
        open={open}
        onOpenChange={(o) => !busy && setOpen(o)}
        title={t('work.reviewTitle')}
        description={`${entry.employee?.name ?? ''} — ${entry.title}`}
        footer={
          <>
            <Button variant="secondary" disabled={busy} onClick={() => setOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button disabled={busy} onClick={() => void confirm()}>
              {busy ? <Spinner label={t('common.saving')} /> : t('work.markReviewed')}
            </Button>
          </>
        }
      >
        <Field id="rv-remarks" label={t('requests.remarks')}>
          <Textarea id="rv-remarks" rows={2} maxLength={500} value={remarks} onChange={(e) => setRemarks(e.target.value)} />
        </Field>
        {error ? (
          <p role="alert" className="mt-2 text-sm text-red-600">
            {error}
          </p>
        ) : null}
      </Modal>
    </>
  )
}

export function TeamWorkPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [to, setTo] = useState(todayYmd())
  const [from, setFrom] = useState(addDays(todayYmd(), -6))
  const [search, setSearch] = useState('')
  const [reviewed, setReviewed] = useState<'' | '0' | '1'>('')
  const [page, setPage] = useState(1)
  const debounced = useDebouncedValue(search.trim())
  const params = { from, to, search: debounced, reviewed: reviewed || undefined, page }
  const q = useQuery({ queryKey: ['team-work', user?.current_organization?.id, params], queryFn: () => fetchTeamWork(params), placeholderData: keepPreviousData })
  // Own entries are never reviewed by oneself (the API refuses it too).
  const me = useQuery({ queryKey: ['my-employee', user?.current_organization?.id], queryFn: fetchMyEmployee })
  const reset = () => setPage(1)

  const byDate = new Map<string, WorkEntry[]>()
  for (const e of q.data?.data ?? []) byDate.set(e.work_date, [...(byDate.get(e.work_date) ?? []), e])

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.teamWork')}</h1>
        <div className="flex flex-wrap items-center gap-4">
          {can('daily_work.import') ? (
            <Link to="/work/import" className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
              <Upload aria-hidden className="size-4" />
              {t('work.importTitle')}
            </Link>
          ) : null}
          {can('daily_work.export') ? (
            <a href={workCsvUrl({ from, to, search: debounced, reviewed })} className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
              <Download aria-hidden className="size-4" />
              {t('attendance.downloadCsv')}
            </a>
          ) : null}
        </div>
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <Field id="tw-from" label={t('leave.from')}>
          <Input
            id="tw-from"
            type="date"
            value={from}
            max={to}
            onChange={(e) => {
              setFrom(e.target.value)
              reset()
            }}
          />
        </Field>
        <Field id="tw-to" label={t('leave.to')}>
          <Input
            id="tw-to"
            type="date"
            value={to}
            min={from}
            onChange={(e) => {
              setTo(e.target.value)
              reset()
            }}
          />
        </Field>
        <Field id="tw-reviewed" label={t('work.reviewState')}>
          <Select
            id="tw-reviewed"
            value={reviewed}
            onChange={(e) => {
              setReviewed(e.target.value as '' | '0' | '1')
              reset()
            }}
          >
            <option value="">{t('dashboard.all')}</option>
            <option value="0">{t('work.notReviewed')}</option>
            <option value="1">{t('work.reviewed')}</option>
          </Select>
        </Field>
        <div className="min-w-48 flex-1">
          <Field id="tw-search" label={t('common.search')}>
            <Input
              id="tw-search"
              type="search"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                reset()
              }}
            />
          </Field>
        </div>
      </div>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('work.none')} /> : null}
        {[...byDate.entries()].map(([date, entries]) => (
          <div key={date} className="border-t border-slate-100 pt-3 first:border-t-0 first:pt-0 dark:border-slate-800">
            <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300">{formatDate(date)}</h2>
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {entries.map((e) => (
                <WorkEntryCard key={e.id} entry={e} showEmployee actions={!e.reviewed_at && can('daily_work.review') && e.employee && e.employee.id !== me.data?.id ? <ReviewButton entry={e} /> : null} />
              ))}
            </ul>
          </div>
        ))}
        {q.data ? <Pagination meta={q.data.meta} onPage={setPage} /> : null}
      </section>
    </div>
  )
}
