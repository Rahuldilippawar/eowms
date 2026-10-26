import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { fetchGrievances, GRIEVANCE_STATUSES, submitGrievance, type GrievanceStatus } from './api'

const tone: Record<GrievanceStatus, string> = {
  open: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  in_progress: 'bg-sky-50 text-sky-700 ring-sky-600/20',
  resolved: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  closed: 'bg-slate-100 text-slate-600 ring-slate-500/20',
}

export function GrievanceStatusBadge({ status }: { status: GrievanceStatus }) {
  const { t } = useTranslation()
  return <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tone[status])}>{t(`grievances.status.${status}`)}</span>
}

function NewGrievanceDialog({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [category, setCategory] = useState('')
  const [description, setDescription] = useState('')
  const [anonymous, setAnonymous] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => submitGrievance({ category: category.trim() || null, description: description.trim(), is_anonymous: anonymous }),
    onSuccess: (g) => {
      void queryClient.invalidateQueries({ queryKey: ['grievances'] })
      toast.success(t('grievances.sent'))
      onClose()
      navigate(`/grievances/${g.id}`)
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['category', 'description'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t('grievances.new')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button
            disabled={save.isPending}
            onClick={() => {
              if (description.trim().length < 10) return setErrors({ description: t('grievances.describeMore') })
              save.mutate()
            }}
          >
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('grievances.send')}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {general ? (
          <p role="alert" className="text-sm text-red-600">
            {general}
          </p>
        ) : null}
        <Field id="gv-category" label={t('grievances.category')} error={errors.category}>
          <Input id="gv-category" value={category} maxLength={100} placeholder={t('grievances.categoryHint')} onChange={(e) => setCategory(e.target.value)} />
        </Field>
        <Field id="gv-description" label={t('grievances.description')} error={errors.description}>
          <Textarea id="gv-description" rows={5} maxLength={5000} value={description} onChange={(e) => setDescription(e.target.value)} />
        </Field>
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" checked={anonymous} onChange={(e) => setAnonymous(e.target.checked)} />
          <span>
            {t('grievances.anonymous')}
            <span className="block text-xs text-slate-500">{t('grievances.anonymousHint')}</span>
          </span>
        </label>
      </div>
    </Modal>
  )
}

export function GrievancesPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [params] = useSearchParams()
  const [status, setStatus] = useState(params.get('status') ?? 'all')
  const [mine, setMine] = useState(false)
  const [creating, setCreating] = useState(false)
  const q = useQuery({ queryKey: ['grievances', user?.current_organization?.id, status, mine], queryFn: () => fetchGrievances({ status, mine }) })
  const meta = q.data?.meta

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.grievances')}</h1>
        <Button onClick={() => setCreating(true)}>
          <Plus aria-hidden className="size-4" />
          {t('grievances.new')}
        </Button>
      </div>
      <p className="text-sm text-slate-600 dark:text-slate-400">{meta?.is_handler ? t('grievances.handlerHint') : t('grievances.staffHint')}</p>
      <div className="flex flex-wrap items-end gap-3">
        <Field id="gv-f-status" label={t('attendance.statusLabel')}>
          <Select id="gv-f-status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="all">{t('dashboard.all')}</option>
            <option value="overdue">{t('grievances.overdue')}</option>
            {GRIEVANCE_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`grievances.status.${s}`)}
              </option>
            ))}
          </Select>
        </Field>
        {meta?.is_handler ? (
          <label className="flex min-h-10 items-center gap-2 text-sm">
            <input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} />
            {t('grievances.onlyMine')}
          </label>
        ) : null}
        {meta ? <p className="min-h-10 py-2 text-sm text-slate-600 dark:text-slate-400">{t('grievances.counts', { open: meta.open, overdue: meta.overdue })}</p> : null}
      </div>
      <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('grievances.none')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(q.data?.data ?? []).map((g) => (
            <li key={g.id}>
              <Link to={`/grievances/${g.id}`} className="flex flex-col gap-1 px-4 py-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-slate-800">
                <div className="min-w-0">
                  <p className="truncate font-medium">
                    #{g.id} · {g.category || t('grievances.general')}
                  </p>
                  <p className="truncate text-sm text-slate-500">{g.description}</p>
                  <p className="text-xs text-slate-500">
                    {[g.is_anonymous ? t('grievances.anonymousLabel') : g.submitted_by, g.is_mine ? t('grievances.yours') : null, g.assignee ? t('requests.withName', { name: g.assignee.name }) : null]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-3 text-sm">
                  {g.overdue ? <span className="rounded-md bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{t('grievances.overdue')}</span> : null}
                  <span className="text-slate-600 dark:text-slate-400">{g.created_at ? formatDate(g.created_at.slice(0, 10)) : ''}</span>
                  <GrievanceStatusBadge status={g.status} />
                </div>
              </Link>
            </li>
          ))}
        </ul>
      </section>
      {creating ? <NewGrievanceDialog onClose={() => setCreating(false)} /> : null}
    </div>
  )
}
