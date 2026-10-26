import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BarChart3, Download, Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
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
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { CATEGORIES, createRequest, fetchRequestDepartments, fetchRequests, IT_SUB_CATEGORIES, PRIORITIES, requestsCsvUrl, STATUSES, type Category, type Priority, type RequestStatus, type WorkRequest } from './api'

const statusTone: Record<RequestStatus, string> = {
  pending: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  in_progress: 'bg-sky-50 text-sky-700 ring-sky-600/20',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  closed: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  cancelled: 'bg-slate-100 text-slate-500 ring-slate-500/20',
}
const priorityTone: Record<Priority, string> = { low: 'text-slate-500', normal: 'text-slate-600', high: 'text-amber-700', urgent: 'text-red-700 font-semibold' }

export function RequestStatusBadge({ status }: { status: RequestStatus }) {
  const { t } = useTranslation()
  return <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', statusTone[status])}>{t(`requests.status.${status}`)}</span>
}

export function PriorityLabel({ priority }: { priority: Priority }) {
  const { t } = useTranslation()
  return <span className={cn('text-xs', priorityTone[priority])}>{t(`requests.priority.${priority}`)}</span>
}

const FIELDS = ['category', 'sub_category', 'department_id', 'title', 'work_description', 'location', 'priority', 'requester_contact'] as const

function NewRequestDialog({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const departments = useQuery({ queryKey: ['request-departments', user?.current_organization?.id], queryFn: fetchRequestDepartments })
  const [v, setV] = useState({ category: 'general' as Category, sub_category: '', department_id: '', title: '', work_description: '', location: '', priority: 'normal' as Priority, requester_contact: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () =>
      createRequest({
        category: v.category,
        sub_category: v.category === 'it' ? v.sub_category || null : null,
        department_id: v.department_id ? Number(v.department_id) : null,
        title: v.title.trim(),
        work_description: v.work_description.trim(),
        location: v.location.trim() || null,
        priority: v.priority,
        requester_contact: v.requester_contact.trim() || null,
      }),
    onSuccess: (r) => {
      void queryClient.invalidateQueries({ queryKey: ['requests'] })
      toast.success(t('requests.raised', { code: r.request_code }))
      onClose()
      navigate(`/requests/${r.id}`)
    },
    onError: (e) => {
      const r = splitServerErrors(e, FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const submit = () => {
    const local: Record<string, string> = {}
    for (const k of ['title', 'work_description'] as const) if (!v[k].trim()) local[k] = t('form.required')
    if (v.category !== 'it' && !v.department_id) local.department_id = t('form.required')
    if (v.category === 'it' && !v.sub_category) local.sub_category = t('form.required')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t('requests.new')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={submit}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('requests.send')}
          </Button>
        </>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <Field id="rq-category" label={t('requests.category')} error={errors.category}>
          <Select id="rq-category" value={v.category} onChange={(e) => setV({ ...v, category: e.target.value as Category })}>
            {CATEGORIES.map((c) => (
              <option key={c} value={c}>
                {t(`requests.categories.${c}`)}
              </option>
            ))}
          </Select>
        </Field>
        {v.category === 'it' ? (
          <Field id="rq-sub" label={t('requests.itKind')} error={errors.sub_category}>
            <Select id="rq-sub" value={v.sub_category} onChange={set('sub_category')}>
              <option value="">—</option>
              {IT_SUB_CATEGORIES.map((c) => (
                <option key={c} value={c}>
                  {t(`requests.it.${c}`)}
                </option>
              ))}
            </Select>
          </Field>
        ) : null}
        <Field id="rq-dept" label={v.category === 'it' ? t('requests.toDepartmentOptional') : t('requests.toDepartment')} error={errors.department_id}>
          <Select id="rq-dept" value={v.department_id} onChange={set('department_id')}>
            <option value="">—</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="rq-priority" label={t('requests.priorityLabel')} error={errors.priority}>
          <Select id="rq-priority" value={v.priority} onChange={(e) => setV({ ...v, priority: e.target.value as Priority })}>
            {PRIORITIES.map((p) => (
              <option key={p} value={p}>
                {t(`requests.priority.${p}`)} — {t(`requests.sla.${p}`)}
              </option>
            ))}
          </Select>
        </Field>
        <div className="sm:col-span-2">
          <Field id="rq-title" label={t('requests.title')} error={errors.title}>
            <Input id="rq-title" value={v.title} maxLength={255} placeholder={t('requests.titleHint')} onChange={set('title')} />
          </Field>
        </div>
        <div className="sm:col-span-2">
          <Field id="rq-desc" label={t('requests.details')} error={errors.work_description}>
            <Textarea id="rq-desc" rows={3} maxLength={5000} value={v.work_description} onChange={set('work_description')} />
          </Field>
        </div>
        <Field id="rq-location" label={t('requests.location')} error={errors.location}>
          <Input id="rq-location" value={v.location} maxLength={255} placeholder={t('requests.locationHint')} onChange={set('location')} />
        </Field>
        <Field id="rq-contact" label={t('requests.contact')} error={errors.requester_contact}>
          <Input id="rq-contact" type="tel" value={v.requester_contact} maxLength={100} placeholder={t('requests.contactHint')} onChange={set('requester_contact')} />
        </Field>
      </div>
    </Modal>
  )
}

export function RequestsPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [params, setParams] = useSearchParams()
  const [tab, setTab] = useState<'queue' | 'mine'>(params.get('tab') === 'mine' || !can('requests.view') ? 'mine' : 'queue')
  const [status, setStatus] = useState(params.get('status') ?? '')
  const [category, setCategory] = useState('')
  const [priority, setPriority] = useState('')
  const [assignedToMe, setAssignedToMe] = useState(false)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const debounced = useDebouncedValue(search.trim())
  const query = { status, category, priority, assigned: assignedToMe ? 'me' : '', mine: tab === 'mine', search: debounced, page }
  const q = useQuery({ queryKey: ['requests', user?.current_organization?.id, query], queryFn: () => fetchRequests(query), placeholderData: keepPreviousData })
  const meta = q.data?.meta
  const pickStatus = (s: string) => {
    setStatus(s)
    setPage(1)
    setParams(s ? { status: s } : {}, { replace: true })
  }

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.requests')}</h1>
        <div className="flex flex-wrap items-center gap-4">
          {can('requests.view') ? (
            <Link to="/requests/report" className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
              <BarChart3 aria-hidden className="size-4" />
              {t('requests.report')}
            </Link>
          ) : null}
          {meta?.can_export ? (
            <a href={requestsCsvUrl} className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
              <Download aria-hidden className="size-4" />
              {t('attendance.downloadCsv')}
            </a>
          ) : null}
          <Button onClick={() => setCreating(true)}>
            <Plus aria-hidden className="size-4" />
            {t('requests.new')}
          </Button>
        </div>
      </div>

      <div role="tablist" className="inline-flex flex-wrap rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
        {(['queue', 'mine'] as const).map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={tab === k}
            onClick={() => {
              setTab(k)
              setPage(1)
            }}
            className={cn('min-h-9 rounded-md px-4 text-sm font-medium', tab === k ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}
          >
            {t(`requests.tabs.${k}`)}
          </button>
        ))}
      </div>

      {tab === 'queue' && meta ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {(['pending', 'in_progress', 'overdue', 'completed'] as const).map((s) => (
            <button
              key={s}
              type="button"
              aria-pressed={status === s}
              onClick={() => pickStatus(status === s ? '' : s)}
              className={cn('rounded-xl border bg-white p-3 text-left dark:bg-slate-900', status === s ? 'border-brand-600 ring-2 ring-brand-600/30' : 'border-slate-200 dark:border-slate-800')}
            >
              <p className={cn('text-2xl font-semibold tabular-nums', s === 'overdue' && meta.counts.overdue > 0 ? 'text-red-700' : '')}>{meta.counts[s]}</p>
              <p className="text-sm text-slate-600 dark:text-slate-400">{s === 'overdue' ? t('requests.overdue') : t(`requests.status.${s}`)}</p>
            </button>
          ))}
        </div>
      ) : null}

      <div className="flex flex-wrap items-end gap-3">
        <Field id="rq-f-status" label={t('attendance.statusLabel')}>
          <Select id="rq-f-status" value={status} onChange={(e) => pickStatus(e.target.value)}>
            <option value="">{tab === 'mine' ? t('dashboard.all') : t('requests.openOnes')}</option>
            {tab === 'queue' ? <option value="all">{t('dashboard.all')}</option> : null}
            <option value="overdue">{t('requests.overdue')}</option>
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`requests.status.${s}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="rq-f-category" label={t('requests.category')}>
          <Select id="rq-f-category" value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">{t('dashboard.all')}</option>
            {CATEGORIES.map((c) => (
              <option key={c} value={c}>
                {t(`requests.categories.${c}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="rq-f-priority" label={t('requests.priorityLabel')}>
          <Select id="rq-f-priority" value={priority} onChange={(e) => setPriority(e.target.value)}>
            <option value="">{t('dashboard.all')}</option>
            {PRIORITIES.map((p) => (
              <option key={p} value={p}>
                {t(`requests.priority.${p}`)}
              </option>
            ))}
          </Select>
        </Field>
        {tab === 'queue' ? (
          <label className="flex min-h-10 items-center gap-2 text-sm">
            <input type="checkbox" checked={assignedToMe} onChange={(e) => setAssignedToMe(e.target.checked)} />
            {t('requests.assignedToMe')}
          </label>
        ) : null}
        <div className="min-w-48 flex-1">
          <Field id="rq-f-search" label={t('common.search')}>
            <Input id="rq-f-search" type="search" value={search} placeholder={t('requests.searchHint')} onChange={(e) => setSearch(e.target.value)} />
          </Field>
        </div>
      </div>

      <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('requests.none')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(q.data?.data ?? []).map((r: WorkRequest) => (
            <li key={r.id}>
              <Link to={`/requests/${r.id}`} className="flex flex-col gap-1 px-4 py-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-slate-800">
                <div className="min-w-0">
                  <p className="truncate font-medium">
                    <span className="text-slate-500">{r.request_code}</span> · {r.title || r.work_description}
                  </p>
                  <p className="text-sm text-slate-500">
                    {[t(`requests.categories.${r.category}`), r.department?.name, r.requester_name, r.assignee ? t('requests.withName', { name: r.assignee.name }) : null, r.location].filter(Boolean).join(' · ')}
                  </p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-3 text-sm">
                  <PriorityLabel priority={r.priority} />
                  {r.overdue ? <span className="rounded-md bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{t('requests.overdue')}</span> : null}
                  <span className="text-slate-600 dark:text-slate-400">{r.created_at ? formatDate(r.created_at.slice(0, 10)) : ''}</span>
                  <RequestStatusBadge status={r.status} />
                </div>
              </Link>
            </li>
          ))}
        </ul>
        {q.data ? (
          <div className="px-4 pb-3">
            <Pagination meta={q.data.meta} onPage={setPage} />
          </div>
        ) : null}
      </section>
      {creating ? <NewRequestDialog onClose={() => setCreating(false)} /> : null}
    </div>
  )
}
