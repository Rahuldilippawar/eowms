import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { RecipientPicker } from '@/features/letters/LetterBits'
import type { Recipient } from '@/features/letters/api'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { cn } from '@/lib/utils'
import { allocationsCsvUrl, fetchAllocationItems, fetchAllocations, fetchAllocationTypes, giveItem, takeBack, transferItem, type Allocation, type AllocationView, type TypeField } from './api'
import { AssetsNav } from './AssetsNav'
import { ALLOCATION_LINKS } from './navLinks'
import { when } from './when'

const VIEWS: AllocationView[] = ['current', 'loans', 'overdue', 'history']

function FieldInput({ field, value, onChange, error }: { field: TypeField; value: string; onChange: (v: string) => void; error?: string }) {
  const { t } = useTranslation()
  const id = `gv-f-${field.key}`
  const label = field.required ? `${field.label} *` : field.label
  return (
    <Field id={id} label={label} error={error}>
      {field.type === 'dropdown' || field.type === 'yesno' ? (
        <Select id={id} value={value} onChange={(e) => onChange(e.target.value)}>
          <option value="">—</option>
          {(field.type === 'yesno' ? [t('assets.yes'), t('assets.no')] : field.options).map((o) => (
            <option key={o} value={o}>
              {o}
            </option>
          ))}
        </Select>
      ) : (
        <Input id={id} type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'} maxLength={255} value={value} onChange={(e) => onChange(e.target.value)} />
      )}
    </Field>
  )
}

function GiveDialog({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const types = useQuery({ queryKey: ['allocation-types', user?.current_organization?.id, false], queryFn: () => fetchAllocationTypes() })
  const [typeId, setTypeId] = useState('')
  const type = types.data?.data.find((x) => String(x.id) === typeId)
  const items = useQuery({ queryKey: ['allocation-items', user?.current_organization?.id, typeId, 'free'], queryFn: () => fetchAllocationItems({ type_id: Number(typeId), free: true }), enabled: !!typeId })
  const [itemId, setItemId] = useState('')
  const [person, setPerson] = useState<Recipient | null>(null)
  const [purpose, setPurpose] = useState('')
  const [due, setDue] = useState('')
  const [custom, setCustom] = useState<Record<string, string>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => giveItem({ item_id: Number(itemId), user_id: person!.user_id, purpose: purpose.trim() || null, due_at: due ? new Date(due).toISOString() : null, custom }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['allocations'] })
      void queryClient.invalidateQueries({ queryKey: ['allocation-items'] })
      toast.success(t('assets.given'))
      onClose()
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['item_id', 'user_id', 'purpose', 'due_at', 'custom'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const submit = () => {
    const local: Record<string, string> = {}
    if (!itemId) local.item_id = t('form.required')
    if (!person) local.user_id = t('form.required')
    for (const f of type?.fields ?? []) if (f.required && !custom[f.key!]?.trim()) local[`custom.${f.key}`] = t('form.required')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t('assets.giveItem')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={submit}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('assets.give')}
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
        <Field id="gv-type" label={t('assets.type')}>
          <Select
            id="gv-type"
            value={typeId}
            onChange={(e) => {
              setTypeId(e.target.value)
              setItemId('')
              setCustom({})
            }}
          >
            <option value="">—</option>
            {(types.data?.data ?? []).map((x) => (
              <option key={x.id} value={x.id}>
                {x.is_loan ? `${x.name} (${t('assets.loan')})` : x.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="gv-item" label={t('assets.item')} error={errors.item_id}>
          <Select id="gv-item" value={itemId} disabled={!typeId} onChange={(e) => setItemId(e.target.value)}>
            <option value="">{typeId && items.data?.length === 0 ? t('assets.noFreeItems') : '—'}</option>
            {(items.data ?? []).map((i) => (
              <option key={i.id} value={i.id}>
                {i.name}
              </option>
            ))}
          </Select>
        </Field>
        <div className="sm:col-span-2">
          <RecipientPicker label={t('assets.giveTo')} value={person} onChange={setPerson} error={errors.user_id} />
        </div>
        {(type?.fields ?? []).map((f) => (
          <FieldInput key={f.key} field={f} value={custom[f.key!] ?? ''} onChange={(v) => setCustom({ ...custom, [f.key!]: v })} error={errors[`custom.${f.key}`]} />
        ))}
        <Field id="gv-purpose" label={t('assets.purpose')} error={errors.purpose}>
          <Input id="gv-purpose" maxLength={255} value={purpose} onChange={(e) => setPurpose(e.target.value)} />
        </Field>
        <Field id="gv-due" label={type?.is_loan ? t('assets.returnByLabel') : t('assets.returnByOptional')} error={errors.due_at}>
          <Input id="gv-due" type="datetime-local" value={due} onChange={(e) => setDue(e.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}

function CloseDialog({ allocation, mode, onClose }: { allocation: Allocation; mode: 'return' | 'transfer'; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [reason, setReason] = useState('')
  const [person, setPerson] = useState<Recipient | null>(null)
  const [error, setError] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => (mode === 'return' ? takeBack(allocation.id, reason.trim() || null) : transferItem(allocation.id, person!.user_id, reason.trim() || null)),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['allocations'] })
      void queryClient.invalidateQueries({ queryKey: ['allocation-items'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => {
      const r = splitServerErrors(e, [], t('common.actionFailed'))
      setError(r.general)
    },
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t(mode === 'return' ? 'assets.takeBackNamed' : 'assets.transferNamed', { item: allocation.item?.name ?? '', name: allocation.user?.name ?? '' })}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending || (mode === 'transfer' && !person)} onClick={() => save.mutate()}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t(mode === 'return' ? 'assets.takeBack' : 'assets.transfer')}
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? (
          <p role="alert" className="text-sm text-red-600">
            {error}
          </p>
        ) : null}
        {mode === 'transfer' ? <RecipientPicker label={t('assets.giveTo')} value={person} onChange={setPerson} /> : null}
        <Field id="cl-reason" label={t('assets.reason')}>
          <Input id="cl-reason" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}

export function AllocationsPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [view, setView] = useState<AllocationView>('current')
  const [typeId, setTypeId] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [giving, setGiving] = useState(false)
  const [closing, setClosing] = useState<{ allocation: Allocation; mode: 'return' | 'transfer' } | null>(null)
  const debounced = useDebouncedValue(search.trim(), 300)
  const types = useQuery({ queryKey: ['allocation-types', user?.current_organization?.id, false], queryFn: () => fetchAllocationTypes() })
  const q = useQuery({
    queryKey: ['allocations', user?.current_organization?.id, view, typeId, debounced, page],
    queryFn: () => fetchAllocations({ view, type_id: typeId, search: debounced, page }),
    placeholderData: keepPreviousData,
  })
  const manage = q.data?.meta.can_manage ?? false

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.allocations')}</h1>
        <div className="flex flex-wrap items-center gap-3">
          {can('assets.export') ? (
            <a href={allocationsCsvUrl(view)} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
              <Download aria-hidden className="size-4" />
              {t('attendance.downloadCsv')}
            </a>
          ) : null}
          {manage ? (
            <Button onClick={() => setGiving(true)}>
              <Plus aria-hidden className="size-4" />
              {t('assets.giveItem')}
            </Button>
          ) : null}
        </div>
      </div>
      <AssetsNav links={ALLOCATION_LINKS} />
      <div role="tablist" className="inline-flex flex-wrap rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
        {VIEWS.map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={view === k}
            onClick={() => {
              setView(k)
              setPage(1)
            }}
            className={cn('min-h-9 rounded-md px-4 text-sm font-medium', view === k ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}
          >
            {t(`assets.view.${k}`)}
            {k === 'overdue' && q.data?.meta.overdue ? ` (${q.data.meta.overdue})` : ''}
          </button>
        ))}
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <Field id="al-type" label={t('assets.type')}>
          <Select
            id="al-type"
            value={typeId}
            onChange={(e) => {
              setTypeId(e.target.value)
              setPage(1)
            }}
          >
            <option value="">{t('assets.allTypes')}</option>
            {(types.data?.data ?? []).map((x) => (
              <option key={x.id} value={x.id}>
                {x.name}
              </option>
            ))}
          </Select>
        </Field>
        <div className="min-w-48 flex-1">
          <Field id="al-search" label={t('common.search')}>
            <Input
              id="al-search"
              type="search"
              placeholder={t('assets.allocationSearchHint')}
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
            />
          </Field>
        </div>
      </div>
      <div className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('assets.noAllocations')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(q.data?.data ?? []).map((a) => (
            <li key={a.id} className="flex flex-col gap-2 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
              <span className="min-w-0">
                <span className="block font-medium">
                  {a.item?.name} → {a.user?.name}
                </span>
                <span className="block text-slate-500">
                  {[a.type?.name, t('assets.givenOn', { when: when(a.allocated_at) }), a.purpose, a.due_at ? t('assets.returnBy', { when: when(a.due_at) }) : null].filter(Boolean).join(' · ')}
                </span>
                {a.returned_at ? (
                  <span className="block text-slate-500">
                    {t('assets.returnedOn', { when: when(a.returned_at) })}
                    {a.return_reason ? ` · ${a.return_reason}` : ''}
                  </span>
                ) : null}
                {a.overdue ? <span className="block font-medium text-red-700">{t('assets.overdue')}</span> : null}
              </span>
              {manage && !a.returned_at ? (
                <span className="flex shrink-0 gap-2">
                  <Button variant="secondary" onClick={() => setClosing({ allocation: a, mode: 'transfer' })}>
                    {t('assets.transfer')}
                  </Button>
                  <Button variant="secondary" onClick={() => setClosing({ allocation: a, mode: 'return' })}>
                    {t('assets.takeBack')}
                  </Button>
                </span>
              ) : null}
            </li>
          ))}
        </ul>
      </div>
      {q.data ? <Pagination meta={q.data.meta} onPage={setPage} /> : null}
      {giving ? <GiveDialog onClose={() => setGiving(false)} /> : null}
      {closing ? <CloseDialog allocation={closing.allocation} mode={closing.mode} onClose={() => setClosing(null)} /> : null}
    </div>
  )
}
