import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { LogIn, LogOut } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { actionErrorMessage } from '@/lib/forms'
import { formatDate, formatTime, monthYm, todayYmd } from '@/lib/format'
import { checkIn, checkOut, fetchMyAttendance, fetchMyCorrections, fetchMyEmployee, requestCorrection, type AttendanceStatus } from './api'
import { StatusBadge } from './StatusBadge'

function CorrectionDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [v, setV] = useState({ work_date: todayYmd(), requested_status: 'present' as AttendanceStatus, requested_check_in: '', requested_check_out: '', reason: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: () => requestCorrection({ ...v, reason: v.reason.trim(), requested_check_in: v.requested_check_in || null, requested_check_out: v.requested_check_out || null }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['my-corrections'] })
      toast.success(t('attendance.correctionSent'))
      onOpenChange(false)
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['work_date', 'requested_status', 'requested_check_in', 'requested_check_out', 'reason'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title={t('attendance.requestCorrection')}
      description={t('attendance.correctionHint')}
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button
            disabled={save.isPending}
            onClick={() => {
              if (!v.reason.trim()) return setErrors({ reason: t('form.required') })
              save.mutate()
            }}
          >
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('attendance.sendRequest')}
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
        <Field id="c-date" label={t('attendance.date')} error={errors.work_date}>
          <Input id="c-date" type="date" max={todayYmd()} value={v.work_date} onChange={(e) => setV({ ...v, work_date: e.target.value })} />
        </Field>
        <Field id="c-status" label={t('attendance.statusLabel')} error={errors.requested_status}>
          <Select id="c-status" value={v.requested_status} onChange={(e) => setV({ ...v, requested_status: e.target.value as AttendanceStatus })}>
            {(['present', 'late', 'half_day', 'absent'] as const).map((s) => (
              <option key={s} value={s}>
                {t(`attendance.status.${s}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="c-in" label={t('attendance.checkInTime')} error={errors.requested_check_in}>
          <Input id="c-in" type="time" value={v.requested_check_in} onChange={(e) => setV({ ...v, requested_check_in: e.target.value })} />
        </Field>
        <Field id="c-out" label={t('attendance.checkOutTime')} error={errors.requested_check_out}>
          <Input id="c-out" type="time" value={v.requested_check_out} onChange={(e) => setV({ ...v, requested_check_out: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="c-reason" label={t('attendance.reason')} error={errors.reason}>
            <Input id="c-reason" value={v.reason} maxLength={500} onChange={(e) => setV({ ...v, reason: e.target.value })} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}

export function MyAttendancePage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const orgKey = user?.current_organization?.id
  const [month, setMonth] = useState(monthYm())
  const [correctionOpen, setCorrectionOpen] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const me = useQuery({ queryKey: ['my-employee', orgKey], queryFn: fetchMyEmployee })
  const records = useQuery({ queryKey: ['my-attendance', orgKey, month], queryFn: () => fetchMyAttendance(month), enabled: !!me.data })
  const corrections = useQuery({ queryKey: ['my-corrections', orgKey], queryFn: fetchMyCorrections, enabled: !!me.data })

  const act = useMutation({
    mutationFn: (kind: 'in' | 'out') => (kind === 'in' ? checkIn() : checkOut()),
    onSuccess: (_r, kind) => {
      setActionError(null)
      void queryClient.invalidateQueries({ queryKey: ['my-attendance'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      toast.success(kind === 'in' ? t('attendance.checkedIn') : t('attendance.checkedOut'))
    },
    onError: (e) => setActionError(actionErrorMessage(e, t('common.actionFailed'))),
  })

  if (me.isPending) return <LoadingState />
  if (me.isError) return <ErrorState onRetry={() => void me.refetch()} />
  if (!me.data) return <EmptyState message={t('attendance.noEmployee')} />

  const today = records.data?.find((r) => r.work_date === todayYmd())
  const open = records.data?.find((r) => r.check_in_at && !r.check_out_at)

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <h1 className="text-2xl font-semibold">{t('nav.myAttendance')}</h1>

      <section className="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-900">
        <div>
          <p className="text-sm text-slate-500">{t('attendance.today')}</p>
          <div className="mt-1 flex items-center gap-2">
            <StatusBadge status={today?.status ?? null} />
            {today?.check_in_at ? <span className="text-sm">{t('attendance.inAt', { time: formatTime(today.check_in_at) })}</span> : null}
            {today?.check_out_at ? <span className="text-sm">{t('attendance.outAt', { time: formatTime(today.check_out_at) })}</span> : null}
          </div>
          {actionError ? (
            <p role="alert" className="mt-2 text-sm text-red-600">
              {actionError}
            </p>
          ) : null}
        </div>
        <div className="flex gap-2">
          {open ? (
            <Button className="min-h-12 px-6 text-base" disabled={act.isPending} onClick={() => act.mutate('out')}>
              <LogOut aria-hidden className="size-5" />
              {t('attendance.checkOut')}
            </Button>
          ) : (
            <Button className="min-h-12 px-6 text-base" disabled={act.isPending || records.isPending || !!today?.check_in_at || today?.status === 'leave'} onClick={() => act.mutate('in')}>
              <LogIn aria-hidden className="size-5" />
              {t('attendance.checkIn')}
            </Button>
          )}
        </div>
      </section>

      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <h2 className="font-semibold">{t('attendance.history')}</h2>
          <Link to="/my/attendance/certificate" className="text-sm font-medium text-brand-700 hover:underline">
            {t('certificate.title')}
          </Link>
          <div className="flex gap-2">
            <Input type="month" aria-label={t('attendance.month')} className="w-44" value={month} max={monthYm()} onChange={(e) => e.target.value && setMonth(e.target.value)} />
            <Button variant="secondary" onClick={() => setCorrectionOpen(true)}>
              {t('attendance.requestCorrection')}
            </Button>
          </div>
        </div>
        {records.isPending ? (
          <LoadingState />
        ) : records.isError ? (
          <ErrorState onRetry={() => void records.refetch()} />
        ) : records.data.length === 0 ? (
          <EmptyState message={t('attendance.noRecords')} />
        ) : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {records.data.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span className="w-32 font-medium">{formatDate(r.work_date)}</span>
                <StatusBadge status={r.status} />
                <span className="text-slate-500">
                  {formatTime(r.check_in_at)} – {formatTime(r.check_out_at)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      {corrections.data && corrections.data.length > 0 ? (
        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <h2 className="mb-3 font-semibold">{t('attendance.myCorrections')}</h2>
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {corrections.data.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span>
                  {formatDate(c.work_date)} → {t(`attendance.status.${c.requested_status}`)}
                </span>
                <span className="text-slate-500">{t(`requests.${c.status}`)}</span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <CorrectionDialog open={correctionOpen} onOpenChange={setCorrectionOpen} />
    </div>
  )
}
