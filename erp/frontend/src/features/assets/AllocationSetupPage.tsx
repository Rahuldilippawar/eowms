import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Upload, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { CsvImport } from '@/components/data/CsvImport'
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
import {
  addAllocationItem,
  allocationTemplateUrl,
  fetchAllocationItems,
  fetchAllocationTypes,
  previewAllocationImport,
  retireAllocationItem,
  runAllocationImport,
  saveAllocationType,
  type AllocationType,
  type FieldType,
  type TypeField,
} from './api'
import { AssetsNav } from './AssetsNav'
import { ALLOCATION_LINKS } from './navLinks'

const FIELD_TYPES: FieldType[] = ['text', 'number', 'date', 'dropdown', 'yesno']

function TypeDialog({ type, onClose }: { type: AllocationType | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [name, setName] = useState(type?.name ?? '')
  const [isLoan, setIsLoan] = useState(type?.is_loan ?? false)
  const [active, setActive] = useState(type?.is_active ?? true)
  const [fields, setFields] = useState<(TypeField & { optionsText: string })[]>((type?.fields ?? []).map((f) => ({ ...f, optionsText: f.options.join(', ') })))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () =>
      saveAllocationType(type?.id ?? null, {
        name: name.trim(),
        is_loan: isLoan,
        fields: fields.map(({ optionsText, ...f }) => ({ ...f, label: f.label.trim(), options: f.type === 'dropdown' ? optionsText.split(',').map((o) => o.trim()).filter(Boolean) : [] })),
        ...(type ? { is_active: active } : {}),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['allocation-types'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['name', 'fields'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const set = (i: number, patch: Partial<TypeField & { optionsText: string }>) => setFields(fields.map((f, j) => (j === i ? { ...f, ...patch } : f)))
  const submit = () => {
    if (!name.trim()) return setErrors({ name: t('form.required') })
    if (fields.some((f) => !f.label.trim())) return setErrors({ fields: t('assets.fieldNeedsLabel') })
    if (fields.some((f) => f.type === 'dropdown' && !f.optionsText.trim())) return setErrors({ fields: t('assets.dropdownNeedsOptions') })
    save.mutate()
  }
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={type ? t('assets.editType') : t('assets.newType')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={submit}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {general ? (
          <p role="alert" className="text-sm text-red-600">
            {general}
          </p>
        ) : null}
        <Field id="ty-name" label={t('assets.typeName')} error={errors.name}>
          <Input id="ty-name" maxLength={255} value={name} onChange={(e) => setName(e.target.value)} />
        </Field>
        <label className="inline-flex items-center gap-2 text-sm">
          <input type="checkbox" checked={isLoan} onChange={(e) => setIsLoan(e.target.checked)} />
          {t('assets.isLoanHint')}
        </label>
        {type ? (
          <label className="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
            {t('store.inUse')}
          </label>
        ) : null}
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">{t('assets.extraFields')}</legend>
          {errors.fields ? (
            <p role="alert" className="text-sm text-red-600">
              {errors.fields}
            </p>
          ) : null}
          {fields.map((f, i) => (
            <div key={i} className="grid gap-2 rounded-xl border border-slate-200 p-2 sm:grid-cols-12 dark:border-slate-800">
              <Input aria-label={t('assets.fieldLabel', { n: i + 1 })} className="sm:col-span-4" maxLength={100} placeholder={t('assets.fieldLabelHint')} value={f.label} onChange={(e) => set(i, { label: e.target.value })} />
              <Select aria-label={t('assets.fieldType', { n: i + 1 })} className="sm:col-span-3" value={f.type} onChange={(e) => set(i, { type: e.target.value as FieldType })}>
                {FIELD_TYPES.map((ft) => (
                  <option key={ft} value={ft}>
                    {t(`assets.fieldTypes.${ft}`)}
                  </option>
                ))}
              </Select>
              {f.type === 'dropdown' ? (
                <Input aria-label={t('assets.fieldOptions', { n: i + 1 })} className="sm:col-span-3" placeholder={t('assets.optionsHint')} value={f.optionsText} onChange={(e) => set(i, { optionsText: e.target.value })} />
              ) : (
                <span className="sm:col-span-3" />
              )}
              <label className="inline-flex items-center gap-1 text-sm sm:col-span-1">
                <input type="checkbox" checked={f.required} onChange={(e) => set(i, { required: e.target.checked })} />
                {t('assets.required')}
              </label>
              <Button variant="ghost" className="sm:col-span-1" aria-label={t('assets.removeField', { n: i + 1 })} onClick={() => setFields(fields.filter((_, j) => j !== i))}>
                <X aria-hidden className="size-4" />
              </Button>
            </div>
          ))}
          <Button variant="secondary" onClick={() => setFields([...fields, { label: '', type: 'text', options: [], required: false, optionsText: '' }])}>
            {t('assets.addField')}
          </Button>
        </fieldset>
      </div>
    </Modal>
  )
}

function TypeItems({ type }: { type: AllocationType }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const items = useQuery({ queryKey: ['allocation-items', user?.current_organization?.id, String(type.id), 'all'], queryFn: () => fetchAllocationItems({ type_id: type.id }) })
  const [name, setName] = useState('')
  const [location, setLocation] = useState('')
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['allocation-items'] })
    void queryClient.invalidateQueries({ queryKey: ['allocation-types'] })
  }
  const add = useMutation({
    mutationFn: () => addAllocationItem({ type_id: type.id, name: name.trim(), default_location: location.trim() || null }),
    onSuccess: () => {
      setName('')
      setLocation('')
      refresh()
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const retire = useMutation({ mutationFn: retireAllocationItem, onSuccess: refresh, onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))) })
  return (
    <div className="space-y-2">
      <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
        {(items.data ?? []).map((i) => (
          <li key={i.id} className="flex items-center justify-between gap-2 py-1">
            <span>
              {i.name}
              <span className="text-slate-500">{[i.default_location, i.held_by ? t('assets.withName', { name: i.held_by.name }) : null].filter(Boolean).map((x) => ` · ${x}`)}</span>
            </span>
            {!i.held_by ? (
              <button type="button" className="text-xs font-medium text-red-700 hover:underline" aria-label={t('assets.retireNamed', { name: i.name })} onClick={() => retire.mutate(i.id)}>
                {t('assets.retire')}
              </button>
            ) : null}
          </li>
        ))}
      </ul>
      <div className="flex flex-wrap items-end gap-2">
        <Input aria-label={t('assets.newItemFor', { type: type.name })} className="min-w-40 flex-1" maxLength={255} placeholder={t('assets.newItemName')} value={name} onChange={(e) => setName(e.target.value)} />
        <Input aria-label={t('assets.itemLocationFor', { type: type.name })} className="min-w-32 flex-1" maxLength={255} placeholder={t('assets.location')} value={location} onChange={(e) => setLocation(e.target.value)} />
        <Button variant="secondary" disabled={!name.trim() || add.isPending} onClick={() => add.mutate()}>
          {t('common.add')}
        </Button>
      </div>
    </div>
  )
}

export function AllocationSetupPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [editing, setEditing] = useState<AllocationType | 'new' | null>(null)
  const q = useQuery({ queryKey: ['allocation-types', user?.current_organization?.id, true], queryFn: () => fetchAllocationTypes(true) })
  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('assets.setupTitle')}</h1>
        {q.data?.meta.can_manage ? (
          <div className="flex flex-wrap items-center gap-3">
            <Link to="/assets/allocations/import" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
              <Upload aria-hidden className="size-4" />
              {t('assets.importAllocations')}
            </Link>
            <Button onClick={() => setEditing('new')}>
              <Plus aria-hidden className="size-4" />
              {t('assets.newType')}
            </Button>
          </div>
        ) : null}
      </div>
      <AssetsNav links={ALLOCATION_LINKS} />
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.data.length === 0 ? <EmptyState message={t('assets.noTypes')} /> : null}
      <ul className="space-y-3">
        {(q.data?.data ?? []).map((ty) => (
          <li key={ty.id} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div className="mb-2 flex flex-wrap items-start justify-between gap-2">
              <div>
                <h2 className="font-semibold">
                  {ty.name}
                  {!ty.is_active ? <span className="ms-2 text-xs text-slate-500">{t('store.notInUse')}</span> : null}
                </h2>
                <p className="text-sm text-slate-500">
                  {[ty.is_loan ? t('assets.loan') : t('assets.permanent'), ty.fields.length ? t('assets.fieldsList', { list: ty.fields.map((f) => f.label).join(', ') }) : null].filter(Boolean).join(' · ')}
                </p>
              </div>
              {q.data?.meta.can_manage ? (
                <button type="button" className="text-sm font-medium text-brand-700 hover:underline" aria-label={t('common.editNamed', { name: ty.name })} onClick={() => setEditing(ty)}>
                  {t('common.edit')}
                </button>
              ) : null}
            </div>
            {ty.is_active && q.data?.meta.can_manage ? <TypeItems type={ty} /> : null}
          </li>
        ))}
      </ul>
      {editing ? <TypeDialog type={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </div>
  )
}

export function AllocationImportPage() {
  const { t } = useTranslation()
  return (
    <CsvImport
      title={t('assets.importAllocations')}
      templateUrl={allocationTemplateUrl}
      preview={previewAllocationImport}
      run={runAllocationImport}
      invalidate={['allocation-types', 'allocation-items']}
      doneLink={{ to: '/assets/allocations/setup', label: t('assets.setupTitle') }}
      doneText={(count) => t('assets.itemsImported', { count })}
      columns={[
        { header: t('assets.type'), cell: (d) => String(d.type_name ?? '') },
        { header: t('assets.item'), cell: (d) => String(d.item_name ?? '') },
      ]}
      note={t('assets.importNote')}
    />
  )
}
