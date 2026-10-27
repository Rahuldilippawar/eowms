import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchDepartmentOptions } from '@/features/org/api'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatDate, formatMoney, todayYmd } from '@/lib/format'
import { cn } from '@/lib/utils'
import { deadStockCsvUrl, deleteDeadStock, fetchDeadStock, fetchMasters, saveDeadStock, type Condition, type DeadStockItem } from './api'
import { AssetsNav } from './AssetsNav'
import { DEAD_STOCK_LINKS } from './navLinks'

const CONDITIONS: Condition[] = ['good', 'repairable', 'bad', 'scrap']
const conditionTone: Record<Condition, string> = {
  good: 'text-emerald-700',
  repairable: 'text-amber-700',
  bad: 'text-red-700',
  scrap: 'text-slate-500',
}

function DeadStockDialog({ item, onClose }: { item: DeadStockItem | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const departments = useQuery({ queryKey: ['department-options', user?.current_organization?.id], queryFn: fetchDepartmentOptions })
  const masters = useQuery({ queryKey: ['asset-masters', user?.current_organization?.id, false], queryFn: () => fetchMasters() })
  const [v, setV] = useState({
    department_id: item?.department?.id.toString() ?? '',
    item_master_id: item?.item_master_id.toString() ?? '',
    quantity: String(item?.quantity ?? 1),
    unit: item?.unit ?? '',
    purchase_date: item?.purchase_date ?? '',
    purchase_cost: item?.purchase_cost?.toString() ?? '',
    is_new_purchase: item?.is_new_purchase ?? false,
    condition: (item?.condition ?? 'good') as Condition,
    location: item?.location ?? '',
    remarks: item?.remarks ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () =>
      saveDeadStock(item?.id ?? null, {
        department_id: Number(v.department_id),
        item_master_id: Number(v.item_master_id),
        quantity: Number(v.quantity),
        unit: v.unit.trim() || null,
        purchase_date: v.purchase_date || null,
        purchase_cost: v.purchase_cost === '' ? null : Number(v.purchase_cost),
        is_new_purchase: v.is_new_purchase,
        condition: v.condition,
        location: v.location.trim() || null,
        remarks: v.remarks.trim() || null,
      }),
    onSuccess: (d) => {
      void queryClient.invalidateQueries({ queryKey: ['dead-stock'] })
      toast.success(item ? t('form.saved') : t('assets.recorded', { code: d.item_code }))
      onClose()
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['department_id', 'item_master_id', 'quantity', 'unit', 'purchase_date', 'purchase_cost', 'condition', 'location', 'remarks'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const submit = () => {
    const local: Record<string, string> = {}
    if (!v.department_id) local.department_id = t('form.required')
    if (!v.item_master_id) local.item_master_id = t('form.required')
    if (!(Number(v.quantity) >= 1)) local.quantity = t('form.required')
    if (v.is_new_purchase && v.purchase_cost === '') local.purchase_cost = t('assets.costForPurchase')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={item ? t('assets.editEntry', { code: item.item_code }) : t('assets.newEntry')}
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
      <div className="grid gap-3 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <Field id="ds-dept" label={t('assets.department')} error={errors.department_id}>
          <Select id="ds-dept" value={v.department_id} onChange={(e) => setV({ ...v, department_id: e.target.value })}>
            <option value="">—</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="ds-item" label={t('assets.item')} error={errors.item_master_id}>
          <Select
            id="ds-item"
            value={v.item_master_id}
            onChange={(e) => {
              const m = masters.data?.data.find((x) => String(x.id) === e.target.value)
              setV({ ...v, item_master_id: e.target.value, unit: v.unit || m?.default_unit || '' })
            }}
          >
            <option value="">—</option>
            {(masters.data?.data ?? []).map((m) => (
              <option key={m.id} value={m.id}>
                {m.category ? `${m.item_name} (${m.category})` : m.item_name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="ds-qty" label={t('assets.quantity')} error={errors.quantity}>
          <Input id="ds-qty" type="number" inputMode="numeric" min={1} value={v.quantity} onChange={(e) => setV({ ...v, quantity: e.target.value })} />
        </Field>
        <Field id="ds-unit" label={t('store.unit')} error={errors.unit}>
          <Input id="ds-unit" maxLength={30} value={v.unit} onChange={(e) => setV({ ...v, unit: e.target.value })} />
        </Field>
        <Field id="ds-date" label={t('assets.purchaseDate')} error={errors.purchase_date}>
          <Input id="ds-date" type="date" max={todayYmd()} value={v.purchase_date} onChange={(e) => setV({ ...v, purchase_date: e.target.value })} />
        </Field>
        <Field id="ds-cost" label={t('assets.purchaseCost')} error={errors.purchase_cost}>
          <Input id="ds-cost" type="number" inputMode="decimal" min={0} step="0.01" value={v.purchase_cost} onChange={(e) => setV({ ...v, purchase_cost: e.target.value })} />
        </Field>
        <fieldset className="space-y-1 text-sm sm:col-span-2">
          <legend className="font-medium">{t('assets.howItCame')}</legend>
          <label className="flex items-center gap-2">
            <input type="radio" name="ds-new" checked={v.is_new_purchase} onChange={() => setV({ ...v, is_new_purchase: true })} />
            {t('assets.newPurchase')}
          </label>
          <label className="flex items-center gap-2">
            <input type="radio" name="ds-new" checked={!v.is_new_purchase} onChange={() => setV({ ...v, is_new_purchase: false })} />
            {t('assets.alreadyHad')}
          </label>
        </fieldset>
        <Field id="ds-condition" label={t('assets.condition')} error={errors.condition}>
          <Select id="ds-condition" value={v.condition} onChange={(e) => setV({ ...v, condition: e.target.value as Condition })}>
            {CONDITIONS.map((c) => (
              <option key={c} value={c}>
                {t(`assets.conditionName.${c}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="ds-location" label={t('assets.location')} error={errors.location}>
          <Input id="ds-location" maxLength={255} value={v.location} onChange={(e) => setV({ ...v, location: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="ds-remarks" label={t('store.remarks')} error={errors.remarks}>
            <Input id="ds-remarks" maxLength={1000} value={v.remarks} onChange={(e) => setV({ ...v, remarks: e.target.value })} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}

export function DeadStockPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user, can } = useAuth()
  const [f, setF] = useState({ department_id: '', category: '', condition: '', search: '' })
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<DeadStockItem | 'new' | null>(null)
  const [removing, setRemoving] = useState<DeadStockItem | null>(null)
  const search = useDebouncedValue(f.search.trim(), 300)
  const departments = useQuery({ queryKey: ['department-options', user?.current_organization?.id], queryFn: fetchDepartmentOptions })
  const q = useQuery({
    queryKey: ['dead-stock', user?.current_organization?.id, f.department_id, f.category, f.condition, search, page],
    queryFn: () => fetchDeadStock({ ...f, search, page }),
    placeholderData: keepPreviousData,
  })
  const set = (patch: Partial<typeof f>) => {
    setF({ ...f, ...patch })
    setPage(1)
  }

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.deadStock')}</h1>
        <div className="flex flex-wrap items-center gap-3">
          {can('assets.export') ? (
            <a href={deadStockCsvUrl} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
              <Download aria-hidden className="size-4" />
              {t('attendance.downloadCsv')}
            </a>
          ) : null}
          {q.data?.meta.can_manage ? (
            <Button onClick={() => setEditing('new')}>
              <Plus aria-hidden className="size-4" />
              {t('assets.newEntry')}
            </Button>
          ) : null}
        </div>
      </div>
      <AssetsNav links={DEAD_STOCK_LINKS} />
      <div className="flex flex-wrap items-end gap-3">
        <Field id="dsf-dept" label={t('assets.department')}>
          <Select id="dsf-dept" value={f.department_id} onChange={(e) => set({ department_id: e.target.value })}>
            <option value="">{t('assets.allDepartments')}</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="dsf-category" label={t('store.category')}>
          <Select id="dsf-category" value={f.category} onChange={(e) => set({ category: e.target.value })}>
            <option value="">{t('store.allCategories')}</option>
            {(q.data?.meta.categories ?? []).map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="dsf-condition" label={t('assets.condition')}>
          <Select id="dsf-condition" value={f.condition} onChange={(e) => set({ condition: e.target.value })}>
            <option value="">{t('assets.anyCondition')}</option>
            {CONDITIONS.map((c) => (
              <option key={c} value={c}>
                {t(`assets.conditionName.${c}`)}
              </option>
            ))}
          </Select>
        </Field>
        <div className="min-w-48 flex-1">
          <Field id="dsf-search" label={t('common.search')}>
            <Input id="dsf-search" type="search" placeholder={t('assets.searchHint')} value={f.search} onChange={(e) => set({ search: e.target.value })} />
          </Field>
        </div>
      </div>
      <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('assets.noEntries')} /> : null}
        {q.data && q.data.data.length ? (
          <table className="w-full text-sm">
            <thead className="text-left text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">{t('assets.item')}</th>
                <th className="hidden px-3 py-2 font-medium sm:table-cell">{t('assets.department')}</th>
                <th className="px-3 py-2 text-right font-medium">{t('assets.quantity')}</th>
                <th className="px-3 py-2 font-medium">{t('assets.condition')}</th>
                <th className="hidden px-3 py-2 text-right font-medium sm:table-cell">{t('assets.purchaseCost')}</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {q.data.data.map((d) => (
                <tr key={d.id}>
                  <td className="px-3 py-2">
                    <span className="block font-medium">{d.item_name}</span>
                    <span className="block text-xs text-slate-500">{[d.item_code, d.location, d.purchase_date ? formatDate(d.purchase_date) : null].filter(Boolean).join(' · ')}</span>
                  </td>
                  <td className="hidden px-3 py-2 sm:table-cell">{d.department?.name}</td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {d.quantity} {d.unit ?? ''}
                  </td>
                  <td className={cn('px-3 py-2', conditionTone[d.condition])}>{t(`assets.conditionName.${d.condition}`)}</td>
                  <td className="hidden px-3 py-2 text-right tabular-nums sm:table-cell">
                    {d.purchase_cost === null ? '—' : formatMoney(d.purchase_cost)}
                    {d.is_new_purchase ? <span className="block text-xs text-slate-500">{t('assets.fromBudget')}</span> : null}
                  </td>
                  <td className="px-3 py-2 text-right whitespace-nowrap">
                    {d.can_manage ? (
                      <>
                        <button type="button" className="font-medium text-brand-700 hover:underline" aria-label={t('common.editNamed', { name: d.item_code })} onClick={() => setEditing(d)}>
                          {t('common.edit')}
                        </button>
                        <button type="button" className="ms-3 font-medium text-red-700 hover:underline" aria-label={t('assets.removeNamed', { code: d.item_code })} onClick={() => setRemoving(d)}>
                          {t('common.delete')}
                        </button>
                      </>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>
      {q.data ? <Pagination meta={q.data.meta} onPage={setPage} /> : null}
      {editing ? <DeadStockDialog item={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
      <ConfirmDialog
        open={!!removing}
        onOpenChange={(o) => !o && setRemoving(null)}
        title={t('assets.removeTitle', { code: removing?.item_code ?? '' })}
        body={t('assets.removeBody')}
        confirmLabel={t('common.delete')}
        danger
        onConfirm={async () => {
          if (!removing) return
          await deleteDeadStock(removing.id)
          void queryClient.invalidateQueries({ queryKey: ['dead-stock'] })
          toast.success(t('assets.removed'))
          setRemoving(null)
        }}
      />
    </div>
  )
}
