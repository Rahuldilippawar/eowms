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
import { RecipientPicker } from '@/features/letters/LetterBits'
import type { Recipient } from '@/features/letters/api'
import { fetchDepartmentOptions } from '@/features/org/api'
import { todayYmd } from '@/lib/format'
import { ASSET_TYPES, fetchLabs, saveItAsset, saveLab, type AssetType, type ItAssetDetail, type Lab } from './api'

function Footer({ onClose, busy, onSave }: { onClose: () => void; busy: boolean; onSave: () => void }) {
  const { t } = useTranslation()
  return (
    <>
      <Button variant="secondary" onClick={onClose}>
        {t('common.cancel')}
      </Button>
      <Button disabled={busy} onClick={onSave}>
        {busy ? <Spinner label={t('common.saving')} /> : t('common.save')}
      </Button>
    </>
  )
}

export function LabDialog({ lab, onClose }: { lab: Lab | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const departments = useQuery({ queryKey: ['department-options', user?.current_organization?.id], queryFn: fetchDepartmentOptions })
  const [v, setV] = useState({ name: lab?.name ?? '', location: lab?.location ?? '', capacity: lab?.capacity?.toString() ?? '', department_id: lab?.department?.id.toString() ?? '', is_active: lab?.is_active ?? true })
  const [assistant, setAssistant] = useState<Recipient | null>(lab?.assistant ? { user_id: lab.assistant.id, name: lab.assistant.name, designation: null, department: null } : null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const save = useMutation({
    mutationFn: () =>
      saveLab(lab?.id ?? null, {
        name: v.name.trim(),
        location: v.location.trim() || null,
        capacity: v.capacity === '' ? null : Number(v.capacity),
        department_id: v.department_id ? Number(v.department_id) : null,
        assistant_user_id: assistant?.user_id ?? null,
        ...(lab ? { is_active: v.is_active } : {}),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['it-dashboard'] })
      void queryClient.invalidateQueries({ queryKey: ['it-labs'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => setErrors(splitServerErrors(e, ['name', 'location', 'capacity', 'department_id', 'assistant_user_id'], t('common.actionFailed')).byField),
  })
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={lab ? t('it.editLab') : t('it.newLab')} footer={<Footer onClose={onClose} busy={save.isPending} onSave={() => (v.name.trim() ? save.mutate() : setErrors({ name: t('form.required') }))} />}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field id="lb-name" label={t('it.labName')} error={errors.name}>
          <Input id="lb-name" maxLength={255} value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} />
        </Field>
        <Field id="lb-location" label={t('it.location')} error={errors.location}>
          <Input id="lb-location" maxLength={255} value={v.location} onChange={(e) => setV({ ...v, location: e.target.value })} />
        </Field>
        <Field id="lb-capacity" label={t('it.capacity')} error={errors.capacity}>
          <Input id="lb-capacity" type="number" inputMode="numeric" min={0} value={v.capacity} onChange={(e) => setV({ ...v, capacity: e.target.value })} />
        </Field>
        <Field id="lb-dept" label={t('it.department')} error={errors.department_id}>
          <Select id="lb-dept" value={v.department_id} onChange={(e) => setV({ ...v, department_id: e.target.value })}>
            <option value="">—</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <div className="sm:col-span-2">
          <RecipientPicker label={t('it.assistant')} value={assistant} onChange={setAssistant} error={errors.assistant_user_id} />
        </div>
        {lab ? (
          <label className="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" checked={v.is_active} onChange={(e) => setV({ ...v, is_active: e.target.checked })} />
            {t('store.inUse')}
          </label>
        ) : null}
      </div>
    </Modal>
  )
}

const TEXT = ['workstation_no', 'brand', 'model', 'serial_no', 'os_license', 'remarks'] as const
const DATES = ['purchase_date', 'warranty_expiry', 'license_expiry'] as const

export function AssetDialog({ asset, defaultLab, onClose, onSaved }: { asset: ItAssetDetail | null; defaultLab?: string; onClose: () => void; onSaved: (a: ItAssetDetail) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const labs = useQuery({ queryKey: ['it-labs', user?.current_organization?.id], queryFn: fetchLabs, enabled: !asset })
  const [labId, setLabId] = useState(defaultLab ?? '')
  const [type, setType] = useState<AssetType>(asset?.asset_type ?? 'computer')
  const [v, setV] = useState<Record<(typeof TEXT)[number] | (typeof DATES)[number] | 'purchase_cost', string>>({
    workstation_no: asset?.workstation_no ?? '',
    brand: asset?.brand ?? '',
    model: asset?.model ?? '',
    serial_no: asset?.serial_no ?? '',
    os_license: asset?.os_license ?? '',
    remarks: asset?.remarks ?? '',
    purchase_date: asset?.purchase_date ?? '',
    warranty_expiry: asset?.warranty_expiry ?? '',
    license_expiry: asset?.license_expiry ?? '',
    purchase_cost: asset?.purchase_cost?.toString() ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => {
      const s = (k: keyof typeof v) => v[k].trim() || null
      return saveItAsset(asset?.id ?? null, {
        ...(asset ? {} : { lab_id: Number(labId), asset_type: type }),
        workstation_no: s('workstation_no'),
        brand: s('brand'),
        model: s('model'),
        serial_no: s('serial_no'),
        os_license: s('os_license'),
        remarks: s('remarks'),
        purchase_date: s('purchase_date'),
        warranty_expiry: s('warranty_expiry'),
        license_expiry: s('license_expiry'),
        purchase_cost: v.purchase_cost === '' ? null : Number(v.purchase_cost),
      })
    },
    onSuccess: (a) => {
      void queryClient.invalidateQueries({ queryKey: ['it-assets'] })
      void queryClient.invalidateQueries({ queryKey: ['it-dashboard'] })
      toast.success(asset ? t('form.saved') : t('it.assetAdded', { tag: a.asset_tag }))
      onSaved(a)
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['lab_id', 'asset_type', ...TEXT, ...DATES, 'purchase_cost'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={asset ? t('it.editAsset', { tag: asset.asset_tag }) : t('it.newAsset')}
      footer={<Footer onClose={onClose} busy={save.isPending} onSave={() => (asset || labId ? save.mutate() : setErrors({ lab_id: t('form.required') }))} />}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        {!asset ? (
          <>
            <Field id="as-lab" label={t('it.lab')} error={errors.lab_id}>
              <Select id="as-lab" value={labId} onChange={(e) => setLabId(e.target.value)}>
                <option value="">—</option>
                {(labs.data ?? [])
                  .filter((l) => l.is_active)
                  .map((l) => (
                    <option key={l.id} value={l.id}>
                      {l.name}
                    </option>
                  ))}
              </Select>
            </Field>
            <Field id="as-type" label={t('it.assetType')} error={errors.asset_type}>
              <Select id="as-type" value={type} onChange={(e) => setType(e.target.value as AssetType)}>
                {ASSET_TYPES.map((x) => (
                  <option key={x} value={x}>
                    {t(`it.type.${x}`)}
                  </option>
                ))}
              </Select>
            </Field>
          </>
        ) : null}
        {TEXT.map((k) => (
          <Field key={k} id={`as-${k}`} label={t(`it.field.${k}`)} error={errors[k]}>
            <Input id={`as-${k}`} maxLength={k === 'remarks' ? 500 : 150} value={v[k]} onChange={(e) => setV({ ...v, [k]: e.target.value })} />
          </Field>
        ))}
        {DATES.map((k) => (
          <Field key={k} id={`as-${k}`} label={t(`it.field.${k}`)} error={errors[k]}>
            <Input id={`as-${k}`} type="date" max={k === 'purchase_date' ? todayYmd() : undefined} value={v[k]} onChange={(e) => setV({ ...v, [k]: e.target.value })} />
          </Field>
        ))}
        <Field id="as-cost" label={t('it.field.purchase_cost')} error={errors.purchase_cost}>
          <Input id="as-cost" type="number" inputMode="decimal" min={0} step="0.01" value={v.purchase_cost} onChange={(e) => setV({ ...v, purchase_cost: e.target.value })} />
        </Field>
      </div>
    </Modal>
  )
}
