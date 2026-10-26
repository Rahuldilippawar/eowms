import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, CheckCircle2, FileText, Paperclip, Printer, ShieldAlert } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { PriorityLabel } from '@/features/tasks/TaskBadges'
import { parseApiError } from '@/lib/api'
import { actionErrorMessage } from '@/lib/forms'
import { formatDate, formatMoney, formatTime, todayYmd } from '@/lib/format'
import { cn } from '@/lib/utils'
import {
  attachmentUrl,
  attachToLetter,
  dispatchLetter,
  fetchLetter,
  reassignLetter,
  remarkLetter,
  returnLetter,
  sendLetter,
  signLetter,
  type DispatchMode,
  type LetterDetail,
  type Recipient,
} from './api'
import { LetterStatusBadge, RecipientPicker } from './LetterBits'

type DialogKind = 'submit' | 'forward' | 'return' | 'sign' | 'dispatch' | 'reassign'

const when = (iso: string | null | undefined) => (iso ? `${formatDate(iso.slice(0, 10))} ${formatTime(iso)}` : '—')

function useLetterUpdate(letter: LetterDetail) {
  const queryClient = useQueryClient()
  return (saved: LetterDetail) => {
    queryClient.setQueryData(['letter', letter.id], saved)
    void queryClient.invalidateQueries({ queryKey: ['letters'] })
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  }
}

/** One dialog per move; each asks only for what that move needs. */
function ActionDialog({ letter, kind, onClose }: { letter: LetterDetail; kind: DialogKind; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const update = useLetterUpdate(letter)
  const [remark, setRemark] = useState('')
  const [person, setPerson] = useState<Recipient | null>(null)
  const [password, setPassword] = useState('')
  const [amount, setAmount] = useState(letter.requested_amount?.toString() ?? '')
  const [mode, setMode] = useState<DispatchMode>(letter.dispatch_mode ?? 'post')
  const [tracking, setTracking] = useState(letter.dispatch_tracking_no ?? '')
  const [date, setDate] = useState(letter.dispatch_date ?? todayYmd())
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const byWorkflow = letter.approval_workflow_id !== null && (kind === 'submit' || kind === 'forward')
  const nextStep = byWorkflow ? letter.steps.find((s) => s.step_order > (kind === 'submit' ? 0 : letter.current_step_order)) : undefined
  const needsPerson = (kind === 'submit' || kind === 'forward' || kind === 'reassign') && !byWorkflow

  const run = async () => {
    if (needsPerson && !person) return setError(t('letters.choosePersonFirst'))
    if (kind === 'return' && !remark.trim()) return setError(t('tasks.noteRequired'))
    if (kind === 'sign' && !password) return setError(t('letters.passwordRequired'))
    setBusy(true)
    setError(null)
    try {
      const saved =
        kind === 'submit' || kind === 'forward'
          ? await sendLetter(letter.id, kind, person?.user_id ?? null, remark.trim())
          : kind === 'return'
            ? await returnLetter(letter.id, remark.trim())
            : kind === 'reassign'
              ? await reassignLetter(letter.id, person?.user_id ?? 0, remark.trim())
              : kind === 'dispatch'
                ? await dispatchLetter(letter.id, { dispatch_mode: mode, dispatch_tracking_no: tracking.trim() || null, dispatch_date: date || null })
                : await signLetter(letter.id, { password, remark: remark.trim() || null, approved_amount: letter.is_budget_expense && amount !== '' ? Number(amount) : null })
      update(saved)
      toast.success(t(`letters.done.${kind}`))
      onClose()
    } catch (e) {
      const { status, errors } = parseApiError(e)
      setError(status === 422 && errors ? Object.values(errors)[0]?.[0] ?? t('common.actionFailed') : actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onOpenChange={(o) => !o && !busy && onClose()}
      title={t(`letters.confirm.${kind}`)}
      description={`${letter.file_number} — ${letter.subject}`}
      footer={
        <>
          <Button variant="secondary" disabled={busy} onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={busy} onClick={() => void run()}>
            {busy ? <Spinner label={t('common.saving')} /> : t(`letters.action.${kind}`)}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error ? (
          <p role="alert" className="text-sm text-red-600">
            {error}
          </p>
        ) : null}
        {byWorkflow ? (
          <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800">
            {nextStep ? t('letters.nextStep', { step: nextStep.name, name: nextStep.approver?.name ?? t('letters.nobodySet') }) : t('letters.noNextStep')}
          </p>
        ) : null}
        {needsPerson ? <RecipientPicker label={t('letters.sendTo')} value={person} onChange={setPerson} /> : null}
        {kind === 'dispatch' ? (
          <div className="grid gap-3 sm:grid-cols-3">
            <Field id="ld-mode" label={t('letters.dispatchMode')}>
              <Select id="ld-mode" value={mode} onChange={(e) => setMode(e.target.value as DispatchMode)}>
                {(['post', 'courier', 'speed_post', 'email', 'hand', 'other'] as const).map((m) => (
                  <option key={m} value={m}>
                    {t(`letters.dispatch.${m}`)}
                  </option>
                ))}
              </Select>
            </Field>
            <Field id="ld-track" label={t('letters.trackingNo')}>
              <Input id="ld-track" value={tracking} maxLength={100} onChange={(e) => setTracking(e.target.value)} />
            </Field>
            <Field id="ld-date" label={t('attendance.date')}>
              <Input id="ld-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            </Field>
          </div>
        ) : null}
        {kind === 'sign' ? (
          <>
            <p className="text-sm text-slate-600 dark:text-slate-400">{t('letters.signHint')}</p>
            {letter.is_budget_expense ? (
              <Field id="ls-amount" label={t('letters.approvedAmount')}>
                <Input id="ls-amount" type="number" inputMode="decimal" min={0} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
              </Field>
            ) : null}
            <Field id="ls-password" label={t('auth.password')}>
              <Input id="ls-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
            </Field>
          </>
        ) : null}
        {kind !== 'dispatch' ? (
          <Field id={`lr-${kind}`} label={kind === 'return' ? t('tasks.reason') : t('tasks.noteOptional')}>
            <Textarea id={`lr-${kind}`} rows={3} maxLength={2000} value={remark} onChange={(e) => setRemark(e.target.value)} />
          </Field>
        ) : null}
      </div>
    </Modal>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
      <h2 className="mb-3 font-semibold">{title}</h2>
      {children}
    </section>
  )
}

function RemarkBox({ letter }: { letter: LetterDetail }) {
  const { t } = useTranslation()
  const toast = useToast()
  const update = useLetterUpdate(letter)
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  return (
    <form
      className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end"
      onSubmit={async (e) => {
        e.preventDefault()
        if (!body.trim()) return
        setBusy(true)
        try {
          update(await remarkLetter(letter.id, body.trim()))
          setBody('')
        } catch (err) {
          toast.error(actionErrorMessage(err, t('common.actionFailed')))
        } finally {
          setBusy(false)
        }
      }}
    >
      <div className="flex-1">
        <Field id="lt-remark" label={t('letters.addRemark')}>
          <Textarea id="lt-remark" rows={2} maxLength={2000} value={body} onChange={(e) => setBody(e.target.value)} />
        </Field>
      </div>
      <Button type="submit" variant="secondary" disabled={busy || !body.trim()}>
        {t('letters.addRemarkButton')}
      </Button>
    </form>
  )
}

function Attachments({ letter }: { letter: LetterDetail }) {
  const { t } = useTranslation()
  const toast = useToast()
  const update = useLetterUpdate(letter)
  const [busy, setBusy] = useState(false)
  const canAttach = letter.actions.includes('attach')
  if (!letter.attachments.length && !canAttach) return null
  return (
    <Section title={t('letters.attachments')}>
      {letter.attachments.length ? (
        <ul className="space-y-1 text-sm">
          {letter.attachments.map((a) => (
            <li key={a.id} className="flex items-center gap-2">
              <Paperclip aria-hidden className="size-4 text-slate-400" />
              <a href={attachmentUrl(letter.id, a.id)} className="text-brand-700 hover:underline">
                {a.name}
              </a>
              <span className="text-xs text-slate-500">{Math.max(1, Math.round(a.size / 1024))} KB</span>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-slate-500">{t('letters.noAttachments')}</p>
      )}
      {canAttach ? (
        <div className="mt-3 flex items-center gap-3">
          <label htmlFor="lt-attach" className="text-sm font-medium">
            {t('letters.attachFile')}
          </label>
          <input
            id="lt-attach"
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
            className="text-sm"
            disabled={busy}
            onChange={async (e) => {
              const f = e.target.files?.[0]
              e.target.value = ''
              if (!f) return
              setBusy(true)
              try {
                update(await attachToLetter(letter.id, f))
                toast.success(t('letters.attached'))
              } catch (err) {
                toast.error(actionErrorMessage(err, t('common.actionFailed')))
              } finally {
                setBusy(false)
              }
            }}
          />
          {busy ? <Spinner label={t('common.saving')} /> : null}
        </div>
      ) : null}
    </Section>
  )
}

export function LetterDetailPage() {
  const { t } = useTranslation()
  const { id = '0' } = useParams()
  const q = useQuery({ queryKey: ['letter', Number(id)], queryFn: () => fetchLetter(Number(id)), retry: (n, e) => parseApiError(e).status !== 404 && n < 2 })
  const [dialog, setDialog] = useState<DialogKind | null>(null)

  if (q.isPending) return <LoadingState />
  if (q.isError) return parseApiError(q.error).status === 404 ? <EmptyState message={t('letters.notFound')} /> : <ErrorState onRetry={() => void q.refetch()} />
  const l = q.data
  const buttons = (['submit', 'forward', 'return', 'sign', 'dispatch', 'reassign'] as const).filter((a) => l.actions.includes(a))

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <Link to="/letters" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline">
          <ArrowLeft aria-hidden className="size-4" />
          {t('nav.letters')}
        </Link>
        <div className="flex gap-4">
          {l.actions.includes('edit') ? (
            <Link to={`/letters/${l.id}/edit`} className="text-sm font-medium text-brand-700 hover:underline">
              {t('common.edit')}
            </Link>
          ) : null}
          <Link to={`/letters/${l.id}/print`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
            <Printer aria-hidden className="size-4" />
            {t('letters.print')}
          </Link>
        </div>
      </div>

      <header className="space-y-2">
        <p className="font-mono text-sm text-slate-500">
          {l.file_number}
          {l.outward_no ? ` · ${t('letters.outwardShort')} ${l.outward_no}` : ''}
        </p>
        <h1 className="text-2xl font-semibold">{l.subject}</h1>
        <div className="flex flex-wrap items-center gap-3 text-sm">
          <LetterStatusBadge status={l.status} />
          <PriorityLabel priority={l.priority} />
          {l.category ? <span className="text-slate-600 dark:text-slate-400">{l.category.name}</span> : null}
          {l.holder ? <span className="text-slate-600 dark:text-slate-400">{t('letters.withSince', { name: l.holder.name, date: when(l.holder_since) })}</span> : null}
        </div>
      </header>

      {buttons.length ? (
        <div role="group" aria-label={t('common.actions')} className="flex flex-wrap gap-2">
          {buttons.map((a) => (
            <Button key={a} variant={a === 'sign' || a === 'submit' || a === 'forward' ? 'primary' : 'secondary'} onClick={() => setDialog(a)}>
              {t(`letters.action.${a}`)}
            </Button>
          ))}
        </div>
      ) : null}

      <Section title={t('letters.details')}>
        <dl className="grid gap-3 text-sm sm:grid-cols-3">
          <div>
            <dt className="text-slate-500">{t('letters.createdBy')}</dt>
            <dd className="font-medium">
              {l.creator?.name ?? '—'}
              {l.department ? ` · ${l.department.name}` : ''}
            </dd>
          </div>
          <div>
            <dt className="text-slate-500">{t('letters.referenceNo')}</dt>
            <dd className="font-medium">{l.reference_no ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-slate-500">{t('letters.createdOn')}</dt>
            <dd className="font-medium">{when(l.created_at)}</dd>
          </div>
          {l.is_budget_expense ? (
            <div>
              <dt className="text-slate-500">{t('letters.budgetExpense')}</dt>
              <dd className="font-medium">
                {t(`letters.budget.${l.budget_category ?? 'other'}`)} · {t('letters.requested')} {formatMoney(l.requested_amount)}
                {l.approved_amount !== null ? ` · ${t('letters.approved')} ${formatMoney(l.approved_amount)}` : ''}
              </dd>
            </div>
          ) : null}
          {l.dispatch_mode ? (
            <div>
              <dt className="text-slate-500">{t('letters.dispatched')}</dt>
              <dd className="font-medium">{[t(`letters.dispatch.${l.dispatch_mode}`), l.dispatch_tracking_no, formatDate(l.dispatch_date)].filter(Boolean).join(' · ')}</dd>
            </div>
          ) : null}
          {l.inward_numbers.length ? (
            <div className="sm:col-span-3">
              <dt className="text-slate-500">{t('letters.inwardNumbers')}</dt>
              <dd className="font-medium">{l.inward_numbers.map((n) => `${n.inward_no} (${n.department ?? '—'})`).join(', ')}</dd>
            </div>
          ) : null}
          {l.description ? (
            <div className="sm:col-span-3">
              <dt className="text-slate-500">{t('letters.description')}</dt>
              <dd className="whitespace-pre-line">{l.description}</dd>
            </div>
          ) : null}
        </dl>
      </Section>

      {l.workflow ? (
        <Section title={t('letters.workflowNamed', { name: l.workflow.name })}>
          <ol className="space-y-2 text-sm">
            {l.steps.map((s) => {
              const done = l.status === 'completed' || s.step_order < l.current_step_order
              const now = l.status !== 'completed' && l.status !== 'draft' && s.step_order === l.current_step_order
              return (
                <li key={s.step_order} className={cn('flex items-center gap-2', now ? 'font-semibold' : done ? 'text-slate-500' : '')}>
                  <span className={cn('flex size-6 shrink-0 items-center justify-center rounded-full text-xs', done ? 'bg-emerald-100 text-emerald-800' : now ? 'bg-brand-700 text-white' : 'bg-slate-100 text-slate-600 dark:bg-slate-800')}>
                    {done ? <CheckCircle2 aria-hidden className="size-4" /> : s.step_order}
                  </span>
                  {s.name} — {s.approver?.name ?? t('letters.nobodySet')}
                  {now ? <span className="sr-only"> ({t('letters.currentStep')})</span> : null}
                </li>
              )
            })}
          </ol>
        </Section>
      ) : null}

      <Attachments letter={l} />

      {l.signatures.length ? (
        <Section title={t('letters.signatures')}>
          <ul className="space-y-2 text-sm">
            {l.signatures.map((s) => (
              <li key={s.verification_code} className="flex flex-wrap items-center gap-2">
                {s.intact ? <CheckCircle2 aria-hidden className="size-4 text-emerald-700" /> : <ShieldAlert aria-hidden className="size-4 text-red-700" />}
                <span className="font-medium">{s.signer_name}</span>
                {s.signer_designation ? <span className="text-slate-500">{s.signer_designation}</span> : null}
                <span className="text-slate-500">{when(s.signed_at)}</span>
                <Link to={`/verify/signature/${s.verification_code}`} className="font-mono text-xs text-brand-700 hover:underline">
                  #{s.verification_code}
                </Link>
                {!s.intact ? <span className="font-medium text-red-700">{t('letters.changedAfterSigning')}</span> : null}
              </li>
            ))}
          </ul>
        </Section>
      ) : null}

      <Section title={t('letters.movements')}>
        <ol className="space-y-3 text-sm">
          {l.movements.map((m) => (
            <li key={m.id} className="flex gap-3">
              <FileText aria-hidden className="mt-0.5 size-4 shrink-0 text-slate-400" />
              <div>
                <p>
                  <span className="font-medium">{m.from?.name ?? '—'}</span> {t(`letters.movement.${m.type}`, { name: m.to?.name ?? '' })}
                </p>
                {m.remark ? <p className="whitespace-pre-line text-slate-600 dark:text-slate-400">{m.remark}</p> : null}
                <p className="text-xs text-slate-500">{when(m.created_at)}</p>
              </div>
            </li>
          ))}
        </ol>
        {l.actions.includes('remark') ? <RemarkBox letter={l} /> : null}
      </Section>

      {dialog ? <ActionDialog letter={l} kind={dialog} onClose={() => setDialog(null)} /> : null}
    </div>
  )
}
