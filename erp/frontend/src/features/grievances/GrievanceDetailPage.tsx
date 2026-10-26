import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
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
import { RecipientPicker } from '@/features/letters/LetterBits'
import type { Recipient } from '@/features/letters/api'
import { formatDate, formatTime } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { commentOnGrievance, fetchGrievance, GRIEVANCE_STATUSES, reopenGrievance, updateGrievance, type GrievanceDetail, type GrievanceStatus } from './api'
import { GrievanceStatusBadge } from './GrievancesPage'

const when = (iso: string | null) => (iso ? `${formatDate(iso.slice(0, 10))} ${formatTime(iso)}` : '')
const panel = 'rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900'

function HandlePanel({ g, onSaved }: { g: GrievanceDetail; onSaved: (g: GrievanceDetail) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [status, setStatus] = useState<GrievanceStatus>(g.status)
  const [resolution, setResolution] = useState(g.resolution ?? '')
  const [assignee, setAssignee] = useState<Recipient | null>(g.assignee ? { user_id: g.assignee.id, name: g.assignee.name, designation: null, department: null } : null)
  const [error, setError] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => updateGrievance(g.id, { status, resolution: resolution.trim() || null, ...(g.can_assign ? { assigned_to: assignee?.user_id ?? null } : {}) }),
    onSuccess: (next) => {
      onSaved(next)
      setError(null)
      toast.success(t('form.saved'))
    },
    onError: (e) => setError(actionErrorMessage(e, t('common.actionFailed'))),
  })
  return (
    <section aria-labelledby="gv-handle" className={panel}>
      <h2 id="gv-handle" className="mb-3 font-semibold">
        {t('grievances.handle')}
      </h2>
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault()
          if (status === 'resolved' && !resolution.trim()) return setError(t('grievances.resolutionRequired'))
          save.mutate()
        }}
      >
        <Field id="gv-status" label={t('attendance.statusLabel')}>
          <Select id="gv-status" value={status} onChange={(e) => setStatus(e.target.value as GrievanceStatus)}>
            {GRIEVANCE_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`grievances.status.${s}`)}
              </option>
            ))}
          </Select>
        </Field>
        {g.can_assign ? <RecipientPicker label={t('grievances.assignTo')} value={assignee} onChange={setAssignee} /> : null}
        <Field id="gv-resolution" label={t('grievances.resolution')} error={error ?? undefined}>
          <Textarea id="gv-resolution" rows={3} maxLength={5000} value={resolution} onChange={(e) => setResolution(e.target.value)} />
        </Field>
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
        </Button>
      </form>
    </section>
  )
}

export function GrievanceDetailPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const grievanceId = Number(id)
  const toast = useToast()
  const queryClient = useQueryClient()
  const q = useQuery({ queryKey: ['grievance', grievanceId], queryFn: () => fetchGrievance(grievanceId) })
  const [comment, setComment] = useState('')
  const [reason, setReason] = useState('')
  const saved = (g: GrievanceDetail) => {
    queryClient.setQueryData(['grievance', g.id], g)
    void queryClient.invalidateQueries({ queryKey: ['grievances'] })
  }
  const send = useMutation({
    mutationFn: () => commentOnGrievance(grievanceId, comment.trim()),
    onSuccess: (g) => {
      saved(g)
      setComment('')
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const reopen = useMutation({
    mutationFn: () => reopenGrievance(grievanceId, reason.trim()),
    onSuccess: (g) => {
      saved(g)
      setReason('')
      toast.success(t('grievances.reopened'))
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const g = q.data

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <Link to="/grievances" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.grievances')}
      </Link>
      <div>
        <h1 className="text-2xl font-semibold">
          #{g.id} · {g.category || t('grievances.general')}
        </h1>
        <p className="mt-1 flex flex-wrap items-center gap-3 text-sm">
          <GrievanceStatusBadge status={g.status} />
          {g.overdue ? <span className="rounded-md bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{t('grievances.overdue')}</span> : null}
          <span className="text-slate-500">
            {[g.is_anonymous ? t('grievances.anonymousLabel') : g.submitted_by, when(g.created_at), g.sla_deadline ? t('grievances.resolveBy', { date: formatDate(g.sla_deadline) }) : null]
              .filter(Boolean)
              .join(' · ')}
          </span>
        </p>
      </div>
      <section className={panel}>
        <p className="whitespace-pre-line">{g.description}</p>
        {g.is_mine && g.is_anonymous ? <p className="mt-3 text-xs text-slate-500">{t('grievances.anonymousYours')}</p> : null}
        {g.resolution ? (
          <div className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
            <p className="font-medium">{t('grievances.resolution')}</p>
            <p className="whitespace-pre-line">{g.resolution}</p>
          </div>
        ) : null}
        {g.assignee ? <p className="mt-2 text-sm text-slate-500">{t('grievances.assignedTo', { name: g.assignee.name })}</p> : null}
      </section>
      {g.can_update ? <HandlePanel key={`${g.id}-${g.status}`} g={g} onSaved={saved} /> : null}
      <section aria-labelledby="gv-comments" className={panel}>
        <h2 id="gv-comments" className="mb-3 font-semibold">
          {t('requests.comments')}
        </h2>
        {g.comments.length === 0 ? <p className="text-sm text-slate-500">{t('requests.noComments')}</p> : null}
        <ul className="space-y-2">
          {g.comments.map((c) => (
            <li key={c.id} className={`rounded-lg px-3 py-2 text-sm ${c.author_role === 'handler' ? 'bg-brand-50 dark:bg-slate-800' : 'bg-slate-50 dark:bg-slate-800'}`}>
              <p className="whitespace-pre-line">{c.comment}</p>
              <p className="mt-1 text-xs text-slate-500">{[c.by ?? t(`grievances.role.${c.author_role}`), when(c.at)].filter(Boolean).join(' · ')}</p>
            </li>
          ))}
        </ul>
        {g.can_comment ? (
          <form
            className="mt-3 flex flex-wrap items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              if (comment.trim()) send.mutate()
            }}
          >
            <div className="min-w-48 flex-1">
              <Field id="gv-comment" label={t('requests.addComment')}>
                <Input id="gv-comment" value={comment} maxLength={2000} onChange={(e) => setComment(e.target.value)} />
              </Field>
            </div>
            <Button type="submit" disabled={send.isPending || !comment.trim()}>
              {t('requests.post')}
            </Button>
          </form>
        ) : null}
      </section>
      {g.can_reopen ? (
        <section className={panel}>
          <Field id="gv-reason" label={t('grievances.reopenReason')}>
            <Input id="gv-reason" value={reason} maxLength={2000} onChange={(e) => setReason(e.target.value)} />
          </Field>
          <Button variant="secondary" className="mt-2" disabled={reopen.isPending || !reason.trim()} onClick={() => reopen.mutate()}>
            {t('grievances.reopen')}
          </Button>
        </section>
      ) : null}
    </div>
  )
}
