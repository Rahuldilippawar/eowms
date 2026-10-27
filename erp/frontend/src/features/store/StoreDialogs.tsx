import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchDepartmentOptions } from '@/features/org/api'
import { todayYmd } from '@/lib/format'
import { moveStock, saveStoreItem, type MovementType, type StoreItem, type StoreItemDetail } from './api'

function Footer({ onClose, busy, onSave, label }: { onClose: () => void; busy: boolean; onSave: () => void; label?: string }) {
  const { t } = useTranslation()
  return (
    <>
      <Button variant="secondary" onClick={onClose}>
        {t('common.cancel')}
      </Button>
      <Button disabled={busy} onClick={onSave}>
        {busy ? <Spinner label={t('common.saving')} /> : (label ?? t('common.save'))}
      </Button>
    </>
  )
}

function useDepartments(enabled = true) {
  const { user } = useAuth()
  return useQuery({ queryKey: ['department-options', user?.current_organization?.id], queryFn: fetchDepartmentOptions, enabled })
}

export function ItemDialog({ item, categories, onClose, onSaved }: { item: StoreItem | null; categories: string[]; onClose: () => void; onSaved: (i: StoreItemDetail) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const departments = useDepartments()
  const [v, setV] = useState({
    item_name: item?.item_name ?? '',
    category: item?.category ?? '',
    unit: item?.unit ?? '',
    department_id: item?.department?.id.toString() ?? '',
    reorder_level: item ? String(item.reorder_level) : '',
    is_active: item?.is_active ?? true,
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () =>
      saveStoreItem(item?.id ?? null, {
        item_name: v.item_name.trim(),
        category: v.category.trim() || null,
        unit: v.unit.trim(),
        department_id: v.department_id ? Number(v.department_id) : null,
        reorder_level: v.reorder_level === '' ? null : Number(v.reorder_level),
        ...(item ? { is_active: v.is_active } : {}),
      }),
    onSuccess: (i) => {
      void queryClient.invalidateQueries({ queryKey: ['store-items'] })
      toast.success(t('form.saved'))
      onSaved(i)
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['item_name', 'category', 'unit', 'department_id', 'reorder_level'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const submit = () => {
    const local: Record<string, string> = {}
    if (!v.item_name.trim()) local.item_name = t('form.required')
    if (!v.unit.trim()) local.unit = t('form.required')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={item ? t('store.editItem') : t('store.newItem')} footer={<Footer onClose={onClose} busy={save.isPending} onSave={submit} />}>
      <div className="grid gap-3 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <div className="sm:col-span-2">
          <Field id="si-name" label={t('store.itemName')} error={errors.item_name}>
            <Input id="si-name" maxLength={255} value={v.item_name} onChange={(e) => setV({ ...v, item_name: e.target.value })} />
          </Field>
        </div>
        <Field id="si-category" label={t('store.category')} error={errors.category}>
          <Input id="si-category" list="si-categories" maxLength={100} placeholder={t('store.categoryHint')} value={v.category} onChange={(e) => setV({ ...v, category: e.target.value })} />
          <datalist id="si-categories">
            {categories.map((c) => (
              <option key={c} value={c} />
            ))}
          </datalist>
        </Field>
        <Field id="si-unit" label={t('store.unit')} error={errors.unit}>
          <Input id="si-unit" maxLength={30} placeholder={t('store.unitHint')} value={v.unit} onChange={(e) => setV({ ...v, unit: e.target.value })} />
        </Field>
        <Field id="si-dept" label={t('store.forDepartment')} error={errors.department_id}>
          <Select id="si-dept" value={v.department_id} onChange={(e) => setV({ ...v, department_id: e.target.value })}>
            <option value="">{t('store.shared')}</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="si-reorder" label={t('store.reorderLevel')} error={errors.reorder_level}>
          <Input id="si-reorder" type="number" inputMode="decimal" min={0} step="0.01" value={v.reorder_level} onChange={(e) => setV({ ...v, reorder_level: e.target.value })} />
        </Field>
        {item ? (
          <label className="inline-flex items-center gap-2 text-sm sm:col-span-2">
            <input type="checkbox" checked={v.is_active} onChange={(e) => setV({ ...v, is_active: e.target.checked })} />
            {t('store.inUse')}
          </label>
        ) : null}
      </div>
    </Modal>
  )
}

export function MoveDialog({ item, type, onClose, onSaved }: { item: StoreItem; type: MovementType; onClose: () => void; onSaved: (i: StoreItemDetail) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  // A correction names no department.
  const departments = useDepartments(type !== 'adjust')
  const [v, setV] = useState({ quantity: '', unit_cost: '', department_id: '', issued_to: '', movement_date: todayYmd(), remarks: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () =>
      moveStock(item.id, {
        movement_type: type,
        quantity: Number(v.quantity),
        unit_cost: type === 'receive' && v.unit_cost !== '' ? Number(v.unit_cost) : null,
        department_id: v.department_id ? Number(v.department_id) : null,
        issued_to: v.issued_to.trim() || null,
        movement_date: v.movement_date || null,
        remarks: v.remarks.trim() || null,
      }),
    onSuccess: (i) => {
      void queryClient.invalidateQueries({ queryKey: ['store-items'] })
      toast.success(t('form.saved'))
      onSaved(i)
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['quantity', 'unit_cost', 'department_id', 'issued_to', 'movement_date', 'remarks'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general ?? r.byField.item ?? null)
    },
  })
  const submit = () => {
    if (!v.quantity || Number(v.quantity) === 0) return setErrors({ quantity: t('form.required') })
    if (type === 'adjust' && !v.remarks.trim()) return setErrors({ remarks: t('store.reasonRequired') })
    save.mutate()
  }
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t(`store.moveTitle.${type}`, { item: item.item_name })}
      footer={<Footer onClose={onClose} busy={save.isPending} onSave={submit} label={t(`store.move.${type}`)} />}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <p className="text-sm text-slate-600 sm:col-span-2">{t('store.inStock', { balance: item.balance, unit: item.unit })}</p>
        <Field id="mv-qty" label={type === 'adjust' ? t('store.changeBy') : t('store.quantityIn', { unit: item.unit })} error={errors.quantity}>
          <Input id="mv-qty" type="number" inputMode="decimal" step="0.01" min={type === 'adjust' ? undefined : 0} value={v.quantity} onChange={(e) => setV({ ...v, quantity: e.target.value })} />
        </Field>
        <Field id="mv-date" label={t('store.date')} error={errors.movement_date}>
          <Input id="mv-date" type="date" max={todayYmd()} value={v.movement_date} onChange={(e) => setV({ ...v, movement_date: e.target.value })} />
        </Field>
        {type === 'receive' ? (
          <Field id="mv-cost" label={t('store.unitCost')} error={errors.unit_cost}>
            <Input id="mv-cost" type="number" inputMode="decimal" min={0} step="0.01" value={v.unit_cost} onChange={(e) => setV({ ...v, unit_cost: e.target.value })} />
          </Field>
        ) : null}
        {type !== 'adjust' ? (
          <Field id="mv-dept" label={type === 'issue' ? t('store.issuedToDept') : t('store.forDepartment')} error={errors.department_id}>
            <Select id="mv-dept" value={v.department_id} onChange={(e) => setV({ ...v, department_id: e.target.value })}>
              <option value="">—</option>
              {(departments.data ?? []).map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </Select>
          </Field>
        ) : null}
        {type === 'issue' ? (
          <Field id="mv-to" label={t('store.issuedTo')} error={errors.issued_to}>
            <Input id="mv-to" maxLength={255} value={v.issued_to} onChange={(e) => setV({ ...v, issued_to: e.target.value })} />
          </Field>
        ) : null}
        <div className="sm:col-span-2">
          <Field id="mv-remarks" label={type === 'adjust' ? t('store.reason') : t('store.remarks')} error={errors.remarks}>
            <Input id="mv-remarks" maxLength={500} value={v.remarks} onChange={(e) => setV({ ...v, remarks: e.target.value })} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}
