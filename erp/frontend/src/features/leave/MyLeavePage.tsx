import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Paperclip } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { fetchMyEmployee } from '@/features/attendance/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { actionErrorMessage } from '@/lib/forms'
import { formatDate, todayYmd } from '@/lib/format'
import { applyLeave, attachmentUrl, cancelLeave, fetchActiveLeaveTypes, fetchMyLeave, type LeaveRequest } from './api'
import { RequestStatus } from './LeaveStatus'

const MAX_FILE = 5 * 1024 * 1024

function ApplyForm() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const types = useQuery({ queryKey: ['leave-types-active', user?.current_organization?.id], queryFn: fetchActiveLeaveTypes })
  const empty = { leave_type_id: 0, start_date: todayYmd(), end_date: todayYmd(), reason: '' }
  const [v, setV] = useState(empty)
  const [file, setFile] = useState<File | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: () => applyLeave({ ...v, reason: v.reason.trim(), attachment: file }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['my-leave'] })
      toast.success(t('leave.applied'))
      setV(empty)
      setFile(null)
      setErrors({})
      setGeneral(null)
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['leave_type_id', 'start_date', 'end_date', 'reason', 'attachment'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  const submit = () => {
    const local: Record<string, string> = {}
    if (!v.leave_type_id) local.leave_type_id = t('form.required')
    if (!v.reason.trim()) local.reason = t('form.required')
    if (v.end_date < v.start_date) local.end_date = t('leave.endBeforeStart')
    if (file && file.size > MAX_FILE) local.attachment = t('leave.fileTooBig')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }

  return (
    <form
      noValidate
      onSubmit={(e) => {
        e.preventDefault()
        submit()
      }}
      className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2 dark:border-slate-800 dark:bg-slate-900"
    >
      <h2 className="font-semibold sm:col-span-2">{t('leave.apply')}</h2>
      {general ? (
        <p role="alert" className="text-sm text-red-600 sm:col-span-2">
          {general}
        </p>
      ) : null}
      <div className="sm:col-span-2">
        <Field id="l-type" label={t('leave.type')} error={errors.leave_type_id}>
          <Select id="l-type" value={v.leave_type_id || ''} aria-invalid={!!errors.leave_type_id || undefined} onChange={(e) => setV({ ...v, leave_type_id: Number(e.target.value) })}>
            <option value="">{t('leave.chooseType')}</option>
            {(types.data ?? []).map((lt) => (
              <option key={lt.id} value={lt.id}>
                {lt.name}
              </option>
            ))}
          </Select>
        </Field>
      </div>
      <Field id="l-from" label={t('leave.from')} error={errors.start_date}>
        <Input id="l-from" type="date" value={v.start_date} onChange={(e) => setV({ ...v, start_date: e.target.value })} />
      </Field>
      <Field id="l-to" label={t('leave.to')} error={errors.end_date}>
        <Input id="l-to" type="date" min={v.start_date} value={v.end_date} onChange={(e) => setV({ ...v, end_date: e.target.value })} />
      </Field>
      <div className="sm:col-span-2">
        <Field id="l-reason" label={t('attendance.reason')} error={errors.reason}>
          <Input id="l-reason" value={v.reason} maxLength={1000} aria-invalid={!!errors.reason || undefined} onChange={(e) => setV({ ...v, reason: e.target.value })} />
        </Field>
      </div>
      <div className="sm:col-span-2">
        <Field id="l-file" label={t('leave.attachmentOptional')} error={errors.attachment}>
          <Input id="l-file" type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>
      </div>
      <div className="sm:col-span-2">
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? <Spinner label={t('common.saving')} /> : t('leave.submit')}
        </Button>
      </div>
    </form>
  )
}

function CancelButton({ leave }: { leave: LeaveRequest }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button variant="ghost" className="min-h-8 px-2 py-1 text-xs" onClick={() => setOpen(true)}>
        {t('leave.cancel')}
      </Button>
      <ConfirmDialog
        open={open}
        onOpenChange={setOpen}
        title={t('leave.cancelTitle')}
        body={t('leave.cancelBody')}
        confirmLabel={t('leave.cancel')}
        danger
        onConfirm={async () => {
          try {
            await cancelLeave(leave.id)
          } catch (e) {
            throw new Error(actionErrorMessage(e, t('common.actionFailed')))
          }
          void queryClient.invalidateQueries({ queryKey: ['my-leave'] })
          toast.success(t('form.saved'))
        }}
      />
    </>
  )
}

export function LeaveList({ rows, actions }: { rows: LeaveRequest[]; actions?: (r: LeaveRequest) => React.ReactNode }) {
  const { t } = useTranslation()
  return (
    <ul className="divide-y divide-slate-100 dark:divide-slate-800">
      {rows.map((r) => (
        <li key={r.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="text-sm">
            <p className="font-medium">
              {r.employee ? `${r.employee.name} — ` : ''}
              {r.leave_type.name}: {formatDate(r.start_date)} – {formatDate(r.end_date)} ({t('leave.days', { count: r.days })})
            </p>
            <p className="text-slate-500">{r.reason}</p>
            {r.decision_remarks ? <p className="text-slate-500">↳ {r.decision_remarks}</p> : null}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <RequestStatus status={r.status} />
            {r.has_attachment ? (
              <a href={attachmentUrl(r.id)} className="inline-flex items-center gap-1 text-xs text-brand-700 hover:underline">
                <Paperclip aria-hidden className="size-3" />
                {r.attachment_name ?? t('leave.attachment')}
              </a>
            ) : null}
            {actions?.(r)}
          </div>
        </li>
      ))}
    </ul>
  )
}

export function MyLeavePage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const orgKey = user?.current_organization?.id
  const me = useQuery({ queryKey: ['my-employee', orgKey], queryFn: fetchMyEmployee })
  const list = useQuery({ queryKey: ['my-leave', orgKey], queryFn: fetchMyLeave, enabled: !!me.data })

  if (me.isPending) return <LoadingState />
  if (me.isError) return <ErrorState onRetry={() => void me.refetch()} />
  if (!me.data) return <EmptyState message={t('attendance.noEmployee')} />

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <h1 className="text-2xl font-semibold">{t('nav.myLeave')}</h1>
      <ApplyForm />
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 className="mb-2 font-semibold">{t('leave.history')}</h2>
        {list.isPending ? (
          <LoadingState />
        ) : list.isError ? (
          <ErrorState onRetry={() => void list.refetch()} />
        ) : list.data.length === 0 ? (
          <EmptyState message={t('leave.none')} />
        ) : (
          <LeaveList rows={list.data} actions={(r) => (r.status === 'requested' ? <CancelButton leave={r} /> : null)} />
        )}
      </section>
    </div>
  )
}
