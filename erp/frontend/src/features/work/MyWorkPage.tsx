import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { fetchMyEmployee } from '@/features/attendance/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { addDays, formatDate, todayYmd } from '@/lib/format'
import { CustomFieldInputs } from '@/features/people/CustomFieldInputs'
import type { CustomValues } from '@/features/people/profileApi'
import { deleteWork, fetchMyOpenTasks, fetchMyWork, saveWork, type WorkEntry, type WorkInput, type WorkStatus } from './api'
import { WorkStatusBadge } from './WorkStatusBadge'

const FIELDS = ['custom', 'work_date', 'title', 'description', 'hours', 'status', 'task_id'] as const
const blank = (date: string): Form => ({ work_date: date, title: '', description: '', hours: '', status: 'completed', task_id: '', custom: {} })

interface Form {
  work_date: string
  title: string
  description: string
  hours: string
  status: WorkStatus
  task_id: string
  custom: CustomValues
}

function WorkForm({ editing, onDone, today, editDays }: { editing: WorkEntry | null; onDone: () => void; today: string; editDays: number }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const tasks = useQuery({ queryKey: ['my-open-tasks', user?.current_organization?.id], queryFn: fetchMyOpenTasks })
  const [v, setV] = useState<Form>(() =>
    editing
      ? { work_date: editing.work_date, title: editing.title, description: editing.description ?? '', hours: editing.hours?.toString() ?? '', status: editing.status, task_id: editing.task?.id.toString() ?? '', custom: editing.custom ?? {} }
      : blank(today),
  )
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: () => {
      const input: WorkInput = {
        work_date: v.work_date,
        title: v.title.trim(),
        description: v.description.trim() || null,
        hours: v.hours === '' ? null : Number(v.hours),
        status: v.status,
        task_id: v.task_id ? Number(v.task_id) : null,
        custom: v.custom,
      }
      return saveWork(editing?.id ?? null, input)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['my-work'] })
      toast.success(t('form.saved'))
      setV(blank(v.work_date))
      setErrors({})
      setGeneral(null)
      onDone()
    },
    onError: (e) => {
      const r = splitServerErrors(e, FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  const submit = () => {
    const local: Record<string, string> = {}
    if (!v.title.trim()) local.title = t('form.required')
    if (!v.work_date) local.work_date = t('form.required')
    const h = Number(v.hours)
    if (v.hours !== '' && (Number.isNaN(h) || h < 0.25 || h > 24 || Math.round(h * 4) !== h * 4)) local.hours = t('work.hoursRule')
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
      <h2 className="font-semibold sm:col-span-2">{editing ? t('work.editEntry') : t('work.addEntry')}</h2>
      {general ? (
        <p role="alert" className="text-sm text-red-600 sm:col-span-2">
          {general}
        </p>
      ) : null}
      <div className="sm:col-span-2">
        <Field id="w-title" label={t('work.whatDidYouDo')} error={errors.title}>
          <Input id="w-title" value={v.title} maxLength={255} aria-invalid={!!errors.title || undefined} onChange={(e) => setV({ ...v, title: e.target.value })} />
        </Field>
      </div>
      <Field id="w-date" label={t('attendance.date')} error={errors.work_date}>
        <Input id="w-date" type="date" value={v.work_date} min={addDays(today, -editDays)} max={today} onChange={(e) => setV({ ...v, work_date: e.target.value })} />
      </Field>
      <Field id="w-status" label={t('attendance.statusLabel')} error={errors.status}>
        <Select id="w-status" value={v.status} onChange={(e) => setV({ ...v, status: e.target.value as WorkStatus })}>
          {(['completed', 'in_progress', 'pending'] as const).map((s) => (
            <option key={s} value={s}>
              {t(`work.status.${s}`)}
            </option>
          ))}
        </Select>
      </Field>
      <Field id="w-hours" label={t('work.hours')} error={errors.hours}>
        <Input id="w-hours" type="number" inputMode="decimal" min={0.25} max={24} step={0.25} value={v.hours} aria-invalid={!!errors.hours || undefined} onChange={(e) => setV({ ...v, hours: e.target.value })} />
      </Field>
      <Field id="w-task" label={t('work.linkedTask')} error={errors.task_id}>
        <Select id="w-task" value={v.task_id} onChange={(e) => setV({ ...v, task_id: e.target.value })}>
          <option value="">{t('work.noTask')}</option>
          {(tasks.data ?? []).map((task) => (
            <option key={task.id} value={task.id}>
              {task.code} — {task.title}
            </option>
          ))}
          {editing?.task && !(tasks.data ?? []).some((x) => x.id === editing.task?.id) ? (
            <option value={editing.task.id}>
              {editing.task.code} — {editing.task.title}
            </option>
          ) : null}
        </Select>
      </Field>
      <div className="sm:col-span-2">
        <Field id="w-desc" label={t('work.details')} error={errors.description}>
          <Textarea id="w-desc" value={v.description} maxLength={5000} onChange={(e) => setV({ ...v, description: e.target.value })} />
        </Field>
      </div>
      <CustomFieldInputs entity="work_entry" values={v.custom} onChange={(custom) => setV({ ...v, custom })} errors={errors} />
      <div className="flex gap-2 sm:col-span-2">
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
        </Button>
        {editing ? (
          <Button type="button" variant="secondary" onClick={onDone}>
            {t('common.cancel')}
          </Button>
        ) : null}
      </div>
    </form>
  )
}

export function WorkEntryCard({ entry, actions, showEmployee = false }: { entry: WorkEntry; actions?: React.ReactNode; showEmployee?: boolean }) {
  const { t } = useTranslation()
  return (
    <li className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0 text-sm">
        <p className="font-medium">
          {showEmployee && entry.employee ? <span className="text-slate-500">{entry.employee.name} · </span> : null}
          {entry.title}
        </p>
        {entry.description ? <p className="whitespace-pre-line text-slate-600 dark:text-slate-400">{entry.description}</p> : null}
        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
          <WorkStatusBadge status={entry.status} />
          {entry.hours !== null ? <span>{t('work.hoursCount', { count: entry.hours })}</span> : null}
          {entry.task ? (
            <Link to={`/tasks/${entry.task.id}`} className="text-brand-700 hover:underline">
              {entry.task.code}
            </Link>
          ) : null}
          {entry.reviewed_at ? (
            <span className="inline-flex items-center gap-1 text-emerald-700">
              <CheckCircle2 aria-hidden className="size-3.5" />
              {t('work.reviewedBy', { name: entry.reviewer?.name ?? '' })}
              {entry.review_remarks ? `: ${entry.review_remarks}` : ''}
            </span>
          ) : null}
        </div>
      </div>
      {actions ? <div className="flex shrink-0 gap-2">{actions}</div> : null}
    </li>
  )
}

export function MyWorkPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const orgKey = user?.current_organization?.id
  const [to, setTo] = useState(todayYmd())
  const [from, setFrom] = useState(addDays(todayYmd(), -6))
  const [editing, setEditing] = useState<WorkEntry | null>(null)
  const [deleting, setDeleting] = useState<WorkEntry | null>(null)
  const me = useQuery({ queryKey: ['my-employee', orgKey], queryFn: fetchMyEmployee })
  const list = useQuery({ queryKey: ['my-work', orgKey, from, to], queryFn: () => fetchMyWork({ from, to }), enabled: !!me.data })

  if (me.isPending) return <LoadingState />
  if (me.isError) return <ErrorState onRetry={() => void me.refetch()} />
  if (!me.data) return <EmptyState message={t('attendance.noEmployee')} />

  const today = list.data?.meta.today ?? todayYmd()
  const byDate = new Map<string, WorkEntry[]>()
  for (const e of list.data?.data ?? []) byDate.set(e.work_date, [...(byDate.get(e.work_date) ?? []), e])

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <h1 className="text-2xl font-semibold">{t('nav.myWork')}</h1>
      <WorkForm key={editing?.id ?? 'new'} editing={editing} onDone={() => setEditing(null)} today={today} editDays={list.data?.meta.edit_days ?? 3} />
      <p className="text-xs text-slate-500">{t('work.editWindow', { count: list.data?.meta.edit_days ?? 3 })}</p>

      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
          <h2 className="font-semibold">{t('work.history')}</h2>
          <div className="flex gap-2">
            <Field id="wh-from" label={t('leave.from')}>
              <Input id="wh-from" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
            </Field>
            <Field id="wh-to" label={t('leave.to')}>
              <Input id="wh-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
            </Field>
          </div>
        </div>
        {list.isPending ? <LoadingState /> : null}
        {list.isError ? <ErrorState onRetry={() => void list.refetch()} /> : null}
        {list.data && list.data.data.length === 0 ? <EmptyState message={t('work.none')} /> : null}
        {[...byDate.entries()].map(([date, entries]) => (
          <div key={date} className="border-t border-slate-100 pt-3 first:border-t-0 first:pt-0 dark:border-slate-800">
            <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300">
              {formatDate(date)} · {t('work.hoursCount', { count: entries.reduce<number>((s, e) => s + (e.hours ?? 0), 0) })}
            </h3>
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {entries.map((e) => (
                <WorkEntryCard
                  key={e.id}
                  entry={e}
                  actions={
                    e.can_edit ? (
                      <>
                        <Button variant="ghost" className="min-h-8 px-2 py-1 text-xs" aria-label={t('common.editNamed', { name: e.title })} onClick={() => setEditing(e)}>
                          {t('common.edit')}
                        </Button>
                        <Button variant="ghost" className="min-h-8 px-2 py-1 text-xs text-red-700" aria-label={t('work.deleteNamed', { name: e.title })} onClick={() => setDeleting(e)}>
                          {t('common.delete')}
                        </Button>
                      </>
                    ) : null
                  }
                />
              ))}
            </ul>
          </div>
        ))}
      </section>
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(o) => !o && setDeleting(null)}
        title={t('work.deleteTitle')}
        body={deleting?.title ?? ''}
        confirmLabel={t('common.delete')}
        danger
        onConfirm={async () => {
          if (!deleting) return
          await deleteWork(deleting.id)
          void queryClient.invalidateQueries({ queryKey: ['my-work'] })
          toast.success(t('work.deleted'))
          if (editing?.id === deleting.id) setEditing(null)
          setDeleting(null)
        }}
      />
    </div>
  )
}
