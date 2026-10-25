import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { parseApiError } from '@/lib/api'
import { actionErrorMessage } from '@/lib/forms'
import { formatDate } from '@/lib/format'
import { commentTask, fetchTask, taskTransition, type Task, type TaskAction } from './api'
import { OverdueFlag, PriorityLabel, TaskStatusBadge } from './TaskBadges'
import { TaskDialog } from './TaskDialog'

type Transition = Exclude<TaskAction, 'edit' | 'comment'>
const ORDER: Transition[] = ['start', 'submit', 'complete', 'reopen', 'cancel']
/** Reopen and cancel must say why; submit may add a note. */
const NOTE: Record<Transition, 'none' | 'optional' | 'required'> = { start: 'none', submit: 'optional', complete: 'optional', reopen: 'required', cancel: 'required' }

function TransitionButton({ task, action }: { task: Task; action: Transition }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const run = async () => {
    if (NOTE[action] === 'required' && !note.trim()) {
      setError(t('tasks.noteRequired'))
      return
    }
    setBusy(true)
    setError(null)
    try {
      const saved = await taskTransition(task.id, action, note.trim())
      queryClient.setQueryData(['task', task.id], saved)
      void queryClient.invalidateQueries({ queryKey: ['tasks'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      toast.success(t(`tasks.done.${action}`))
      setOpen(false)
      setNote('')
    } catch (e) {
      setError(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      setBusy(false)
    }
  }

  const danger = action === 'cancel'
  return (
    <>
      <Button variant={action === 'reopen' || danger ? 'secondary' : 'primary'} className={danger ? 'text-red-700' : undefined} disabled={busy} onClick={() => (NOTE[action] === 'none' ? void run() : setOpen(true))}>
        {busy && NOTE[action] === 'none' ? <Spinner label={t('common.saving')} /> : t(`tasks.action.${action}`)}
      </Button>
      {NOTE[action] === 'none' && error ? (
        <p role="alert" className="text-sm text-red-600">
          {error}
        </p>
      ) : null}
      <Modal
        open={open}
        onOpenChange={(o) => !busy && setOpen(o)}
        title={t(`tasks.confirm.${action}`)}
        description={`${task.code} — ${task.title}`}
        footer={
          <>
            <Button variant="secondary" disabled={busy} onClick={() => setOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button className={danger ? 'bg-red-600 hover:bg-red-700' : undefined} disabled={busy} onClick={() => void run()}>
              {busy ? <Spinner label={t('common.saving')} /> : t(`tasks.action.${action}`)}
            </Button>
          </>
        }
      >
        <Field id={`tn-${action}`} label={NOTE[action] === 'required' ? t('tasks.reason') : t('tasks.noteOptional')} error={error ?? undefined}>
          <Textarea id={`tn-${action}`} rows={3} maxLength={1000} value={note} aria-invalid={!!error || undefined} onChange={(e) => setNote(e.target.value)} />
        </Field>
      </Modal>
    </>
  )
}

function CommentBox({ task }: { task: Task }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const send = async () => {
    if (!body.trim()) return
    setBusy(true)
    setError(null)
    try {
      queryClient.setQueryData(['task', task.id], await commentTask(task.id, body.trim()))
      setBody('')
    } catch (e) {
      setError(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void send()
      }}
      className="space-y-2"
    >
      <Field id="tc-body" label={t('tasks.addComment')} error={error ?? undefined}>
        <Textarea id="tc-body" rows={2} maxLength={1000} value={body} onChange={(e) => setBody(e.target.value)} />
      </Field>
      <Button type="submit" variant="secondary" disabled={busy || !body.trim()}>
        {busy ? <Spinner label={t('common.saving')} /> : t('tasks.sendComment')}
      </Button>
    </form>
  )
}

export function TaskDetailPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const taskId = Number(id)
  const [editing, setEditing] = useState(false)
  const q = useQuery({ queryKey: ['task', taskId], queryFn: () => fetchTask(taskId), enabled: Number.isInteger(taskId) })
  const when = (iso: string) => new Intl.DateTimeFormat(i18n.language === 'mr' ? 'mr-IN' : 'en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))

  if (q.isPending) return <LoadingState />
  if (q.isError) return parseApiError(q.error).status === 404 ? <EmptyState message={t('tasks.notFound')} /> : <ErrorState onRetry={() => void q.refetch()} />
  const task = q.data

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <Link to="/tasks" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.tasks')}
      </Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-mono text-xs text-slate-500">{task.code}</p>
          <h1 className="text-2xl font-semibold break-words">{task.title}</h1>
          <div className="mt-2 flex flex-wrap items-center gap-3">
            <TaskStatusBadge status={task.status} />
            <PriorityLabel priority={task.priority} />
            {task.is_overdue ? <OverdueFlag /> : null}
          </div>
        </div>
        {task.actions.includes('edit') ? (
          <Button variant="secondary" onClick={() => setEditing(true)}>
            {t('common.edit')}
          </Button>
        ) : null}
      </div>

      <dl className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 text-sm sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900">
        <div>
          <dt className="text-slate-500">{t('tasks.assignee')}</dt>
          <dd className="font-medium">{task.assignee.name}</dd>
        </div>
        <div>
          <dt className="text-slate-500">{t('tasks.assignedBy')}</dt>
          <dd className="font-medium">{task.assigned_by.name}</dd>
        </div>
        <div>
          <dt className="text-slate-500">{t('tasks.dueDate')}</dt>
          <dd className="font-medium">{formatDate(task.due_date)}</dd>
        </div>
        {task.description ? (
          <div className="sm:col-span-3">
            <dt className="text-slate-500">{t('work.details')}</dt>
            <dd className="whitespace-pre-line">{task.description}</dd>
          </div>
        ) : null}
      </dl>

      {ORDER.some((a) => task.actions.includes(a)) ? (
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t('common.actions')}>
          {ORDER.filter((a) => task.actions.includes(a)).map((a) => (
            <TransitionButton key={a} task={task} action={a} />
          ))}
        </div>
      ) : null}

      <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 className="font-semibold">{t('tasks.timeline')}</h2>
        <ol className="space-y-3">
          {(task.updates ?? []).map((u) => (
            <li key={u.id} className="border-l-2 border-slate-200 pl-3 text-sm dark:border-slate-700">
              <p>
                <span className="font-medium">{u.user.name}</span> {t(`tasks.event.${u.type}`)}
              </p>
              {u.body ? <p className="whitespace-pre-line text-slate-700 dark:text-slate-300">{u.body}</p> : null}
              <p className="text-xs text-slate-500">{when(u.created_at)}</p>
            </li>
          ))}
        </ol>
        {task.actions.includes('comment') ? <CommentBox task={task} /> : null}
      </section>
      {task.actions.includes('edit') ? <TaskDialog open={editing} onOpenChange={setEditing} task={task} /> : null}
    </div>
  )
}
