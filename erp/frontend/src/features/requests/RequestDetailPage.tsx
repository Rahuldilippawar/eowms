import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Star } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { formatDate, formatMoney, formatTime } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import { assignRequest, cancelRequest, closeRequest, commentOnRequest, fetchAssignees, fetchRequest, reopenRequest, setRequestStatus, type WorkRequestDetail } from './api'
import { PriorityLabel, RequestStatusBadge } from './RequestsPage'

const when = (iso: string | null) => (iso ? `${formatDate(iso.slice(0, 10))} ${formatTime(iso)}` : '')
const panel = 'rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900'

function HandlerPanel({ r, onSaved }: { r: WorkRequestDetail; onSaved: (r: WorkRequestDetail) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [status, setStatus] = useState<'pending' | 'in_progress' | 'completed'>(r.status === 'pending' ? 'in_progress' : r.status === 'in_progress' ? 'completed' : 'completed')
  const [remark, setRemark] = useState('')
  const [cost, setCost] = useState(r.cost_incurred?.toString() ?? '')
  const [error, setError] = useState<string | null>(null)
  const assignees = useQuery({ queryKey: ['request-assignees', r.id], queryFn: () => fetchAssignees(r.id), enabled: r.can_assign })
  const save = useMutation({
    mutationFn: () => setRequestStatus(r.id, { status, remark: remark.trim() || null, cost_incurred: cost === '' ? null : Number(cost) }),
    onSuccess: (next) => {
      onSaved(next)
      setRemark('')
      setError(null)
      toast.success(t('form.saved'))
    },
    onError: (e) => setError(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const assign = useMutation({
    mutationFn: (userId: number | null) => assignRequest(r.id, userId),
    onSuccess: (next) => {
      onSaved(next)
      toast.success(t('form.saved'))
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const open = r.status === 'pending' || r.status === 'in_progress'

  return (
    <section aria-labelledby="rq-work" className={panel}>
      <h2 id="rq-work" className="mb-3 font-semibold">
        {t('requests.work')}
      </h2>
      {r.can_assign && open ? (
        <Field id="rq-assignee" label={t('requests.assignTo')}>
          <Select id="rq-assignee" value={r.assignee?.id ?? ''} disabled={assign.isPending} onChange={(e) => assign.mutate(e.target.value ? Number(e.target.value) : null)}>
            <option value="">{t('requests.nobody')}</option>
            {(assignees.data ?? []).map((a) => (
              <option key={a.user_id} value={a.user_id}>
                {[a.name, a.designation].filter(Boolean).join(' — ')}
              </option>
            ))}
            {r.assignee && !(assignees.data ?? []).some((a) => a.user_id === r.assignee?.id) ? <option value={r.assignee.id}>{r.assignee.name}</option> : null}
          </Select>
        </Field>
      ) : null}
      {open || r.status === 'completed' ? (
        <form
          className="mt-3 grid gap-3 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (status === 'completed' && !remark.trim()) return setError(t('requests.remarkRequired'))
            save.mutate()
          }}
        >
          <Field id="rq-status" label={t('requests.setStatus')}>
            <Select id="rq-status" value={status} onChange={(e) => setStatus(e.target.value as typeof status)}>
              {(r.status === 'completed' ? (['completed'] as const) : (['pending', 'in_progress', 'completed'] as const)).map((s) => (
                <option key={s} value={s}>
                  {t(`requests.status.${s}`)}
                </option>
              ))}
            </Select>
          </Field>
          <Field id="rq-cost" label={t('requests.cost')}>
            <Input id="rq-cost" type="number" inputMode="decimal" min={0} step="0.01" value={cost} onChange={(e) => setCost(e.target.value)} />
          </Field>
          <div className="sm:col-span-2">
            <Field id="rq-remark" label={status === 'completed' ? t('requests.whatWasDone') : t('requests.remark')} error={error ?? undefined}>
              <Textarea id="rq-remark" rows={2} maxLength={2000} value={remark} onChange={(e) => setRemark(e.target.value)} />
            </Field>
          </div>
          <Button type="submit" className="sm:col-span-2" disabled={save.isPending}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('requests.update')}
          </Button>
        </form>
      ) : null}
    </section>
  )
}

function RequesterPanel({ r, onSaved }: { r: WorkRequestDetail; onSaved: (r: WorkRequestDetail) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const [rating, setRating] = useState(5)
  const [feedback, setFeedback] = useState('')
  const [error, setError] = useState<string | null>(null)
  const act = useMutation({
    mutationFn: (kind: 'cancel' | 'close' | 'reopen') =>
      kind === 'cancel' ? cancelRequest(r.id, reason.trim()) : kind === 'reopen' ? reopenRequest(r.id, reason.trim()) : closeRequest(r.id, rating, feedback.trim() || null),
    onSuccess: (next) => {
      onSaved(next)
      setReason('')
      setError(null)
      toast.success(t('form.saved'))
    },
    onError: (e) => setError(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const needReason = (kind: 'cancel' | 'reopen') => (reason.trim() ? act.mutate(kind) : setError(t('requests.reasonRequired')))

  if (r.status === 'completed')
    return (
      <section aria-labelledby="rq-close" className={panel}>
        <h2 id="rq-close" className="mb-2 font-semibold">
          {t('requests.rateTitle')}
        </h2>
        <div role="radiogroup" aria-label={t('requests.rating')} className="mb-3 flex gap-1">
          {[1, 2, 3, 4, 5].map((n) => (
            <button key={n} type="button" role="radio" aria-checked={rating === n} aria-label={t('requests.stars', { count: n })} onClick={() => setRating(n)} className="p-1">
              <Star aria-hidden className={cn('size-6', n <= rating ? 'fill-amber-400 text-amber-500' : 'text-slate-300')} />
            </button>
          ))}
        </div>
        <Field id="rq-feedback" label={t('requests.feedback')}>
          <Textarea id="rq-feedback" rows={2} maxLength={1000} value={feedback} onChange={(e) => setFeedback(e.target.value)} />
        </Field>
        {error ? (
          <p role="alert" className="mt-2 text-sm text-red-600">
            {error}
          </p>
        ) : null}
        <div className="mt-3 flex flex-wrap gap-2">
          <Button disabled={act.isPending} onClick={() => act.mutate('close')}>
            {t('requests.closeWithRating')}
          </Button>
        </div>
        {r.can_reopen ? (
          <div className="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
            <Field id="rq-reason" label={t('requests.reopenReason')}>
              <Input id="rq-reason" value={reason} maxLength={1000} onChange={(e) => setReason(e.target.value)} />
            </Field>
            <Button variant="secondary" className="mt-2" disabled={act.isPending} onClick={() => needReason('reopen')}>
              {t('requests.reopen')}
            </Button>
          </div>
        ) : null}
      </section>
    )
  if (r.status === 'pending' || (r.status === 'closed' && r.can_reopen))
    return (
      <section aria-labelledby="rq-mine" className={panel}>
        <h2 id="rq-mine" className="mb-2 font-semibold">
          {r.status === 'pending' ? t('requests.cancelTitle') : t('requests.reopenTitle')}
        </h2>
        <Field id="rq-reason" label={r.status === 'pending' ? t('requests.cancelReason') : t('requests.reopenReason')} error={error ?? undefined}>
          <Input id="rq-reason" value={reason} maxLength={1000} onChange={(e) => setReason(e.target.value)} />
        </Field>
        <Button variant="secondary" className="mt-2" disabled={act.isPending} onClick={() => needReason(r.status === 'pending' ? 'cancel' : 'reopen')}>
          {r.status === 'pending' ? t('requests.cancel') : t('requests.reopen')}
        </Button>
      </section>
    )
  return null
}

export function RequestDetailPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const requestId = Number(id)
  const toast = useToast()
  const queryClient = useQueryClient()
  const q = useQuery({ queryKey: ['request', requestId], queryFn: () => fetchRequest(requestId) })
  const [comment, setComment] = useState('')
  const saved = (r: WorkRequestDetail) => {
    queryClient.setQueryData(['request', r.id], r)
    void queryClient.invalidateQueries({ queryKey: ['requests'] })
  }
  const send = useMutation({
    mutationFn: () => commentOnRequest(requestId, comment.trim()),
    onSuccess: (r) => {
      saved(r)
      setComment('')
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const r = q.data

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/requests" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.requests')}
      </Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm text-slate-500">{r.request_code}</p>
          <h1 className="text-2xl font-semibold">{r.title || r.work_description}</h1>
          <p className="mt-1 flex flex-wrap items-center gap-3 text-sm">
            <RequestStatusBadge status={r.status} />
            <PriorityLabel priority={r.priority} />
            {r.overdue ? <span className="rounded-md bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{t('requests.overdue')}</span> : null}
            {r.escalation_level > 0 ? <span className="text-xs text-red-700">{t('requests.escalated', { level: r.escalation_level })}</span> : null}
          </p>
        </div>
      </div>

      <section className={panel}>
        <p className="whitespace-pre-line">{r.work_description}</p>
        <dl className="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          {[
            [t('requests.category'), [t(`requests.categories.${r.category}`), r.sub_category ? t(`requests.it.${r.sub_category}`) : null].filter(Boolean).join(' · ')],
            [t('requests.toDepartment'), r.department?.name],
            [t('requests.location'), r.location],
            [t('requests.raisedBy'), [r.requester_name, r.requesting_department, r.source === 'public' ? t('requests.publicForm') : null].filter(Boolean).join(' · ')],
            [t('requests.contact'), r.requester_contact],
            [t('requests.raisedOn'), when(r.created_at)],
            [t('requests.dueBy'), when(r.due_at)],
            [t('requests.assignTo'), r.assignee?.name],
            [t('requests.completedBy'), r.completed_by ? `${r.completed_by} · ${when(r.completed_at)}` : null],
            [t('requests.cost'), r.cost_incurred === null ? null : formatMoney(r.cost_incurred)],
            [t('requests.rating'), r.rating ? `${'★'.repeat(r.rating)}${'☆'.repeat(5 - r.rating)}${r.feedback ? ` — ${r.feedback}` : ''}` : null],
          ]
            .filter(([, v]) => v)
            .map(([k, v]) => (
              <div key={k}>
                <dt className="text-slate-500">{k}</dt>
                <dd>{v}</dd>
              </div>
            ))}
        </dl>
        {r.completion_remarks ? <p className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">{r.completion_remarks}</p> : null}
      </section>

      {r.can_handle ? <HandlerPanel key={`${r.id}-${r.status}`} r={r} onSaved={saved} /> : null}
      {r.is_requester ? <RequesterPanel key={`${r.id}-${r.status}-mine`} r={r} onSaved={saved} /> : null}

      <section aria-labelledby="rq-comments" className={panel}>
        <h2 id="rq-comments" className="mb-3 font-semibold">
          {t('requests.comments')}
        </h2>
        {r.comments.length === 0 ? <p className="text-sm text-slate-500">{t('requests.noComments')}</p> : null}
        <ul className="space-y-2">
          {r.comments.map((c) => (
            <li key={c.id} className="rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800">
              <p className="whitespace-pre-line">{c.comment}</p>
              <p className="mt-1 text-xs text-slate-500">{[c.by, when(c.at)].filter(Boolean).join(' · ')}</p>
            </li>
          ))}
        </ul>
        {r.is_requester || r.can_handle ? (
          <form
            className="mt-3 flex flex-wrap items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              if (comment.trim()) send.mutate()
            }}
          >
            <div className="min-w-48 flex-1">
              <Field id="rq-comment" label={t('requests.addComment')}>
                <Input id="rq-comment" value={comment} maxLength={2000} onChange={(e) => setComment(e.target.value)} />
              </Field>
            </div>
            <Button type="submit" disabled={send.isPending || !comment.trim()}>
              {t('requests.post')}
            </Button>
          </form>
        ) : null}
      </section>

      <section aria-labelledby="rq-history" className={panel}>
        <h2 id="rq-history" className="mb-3 font-semibold">
          {t('requests.history')}
        </h2>
        <ol className="space-y-2 text-sm">
          {r.actions.map((a, i) => (
            <li key={i} className="flex flex-wrap gap-x-2">
              <span className="text-slate-500">{when(a.at)}</span>
              <span className="font-medium">{t(`requests.action.${a.type}`, { status: a.new_status ? t(`requests.status.${a.new_status}`) : '' })}</span>
              {a.by ? <span className="text-slate-500">— {a.by}</span> : null}
              {a.remark ? <span className="w-full text-slate-600 dark:text-slate-300">{a.remark}</span> : null}
            </li>
          ))}
        </ol>
        {r.seen_by.length ? <p className="mt-3 text-xs text-slate-500">{t('requests.seenBy', { names: r.seen_by.map((s) => s.name).filter(Boolean).join(', ') })}</p> : null}
      </section>
    </div>
  )
}
