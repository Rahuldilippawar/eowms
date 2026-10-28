import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { todayYmd } from '@/lib/format'
import { saveDvr, type Dvr, type DvrDetail } from './api'

export function DvrDialog({ dvr, onClose, onSaved }: { dvr: Dvr | null; onClose: () => void; onSaved?: (d: DvrDetail) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [v, setV] = useState({
    name: dvr?.name ?? '',
    location: dvr?.location ?? '',
    channel_capacity: dvr?.channel_capacity?.toString() ?? '',
    storage_tb: dvr?.storage_tb?.toString() ?? '',
    installed_date: dvr?.installed_date ?? '',
    is_active: dvr?.is_active ?? true,
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const save = useMutation({
    mutationFn: () =>
      saveDvr(dvr?.id ?? null, {
        name: v.name.trim(),
        location: v.location.trim() || null,
        channel_capacity: v.channel_capacity === '' ? null : Number(v.channel_capacity),
        storage_tb: v.storage_tb === '' ? null : Number(v.storage_tb),
        installed_date: v.installed_date || null,
        ...(dvr ? { is_active: v.is_active } : {}),
      }),
    onSuccess: (d) => {
      void queryClient.invalidateQueries({ queryKey: ['cctv-dashboard'] })
      toast.success(t('form.saved'))
      onClose()
      if (onSaved) onSaved(d)
      else navigate(`/cctv/dvrs/${d.id}`)
    },
    onError: (e) => setErrors(splitServerErrors(e, ['name', 'location', 'channel_capacity', 'storage_tb', 'installed_date'], t('common.actionFailed')).byField),
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={dvr ? t('cctv.editDvr') : t('cctv.newDvr')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={() => (v.name.trim() ? save.mutate() : setErrors({ name: t('form.required') }))}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <Field id="dv-name" label={t('cctv.dvrName')} error={errors.name}>
          <Input id="dv-name" maxLength={255} value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} />
        </Field>
        <Field id="dv-location" label={t('cctv.location')} error={errors.location}>
          <Input id="dv-location" maxLength={255} value={v.location} onChange={(e) => setV({ ...v, location: e.target.value })} />
        </Field>
        <Field id="dv-channels" label={t('cctv.channels')} error={errors.channel_capacity}>
          <Input id="dv-channels" type="number" inputMode="numeric" min={1} value={v.channel_capacity} onChange={(e) => setV({ ...v, channel_capacity: e.target.value })} />
        </Field>
        <Field id="dv-storage" label={t('cctv.storageTb')} error={errors.storage_tb}>
          <Input id="dv-storage" type="number" inputMode="decimal" min={0} step="0.5" value={v.storage_tb} onChange={(e) => setV({ ...v, storage_tb: e.target.value })} />
        </Field>
        <Field id="dv-installed" label={t('cctv.installed')} error={errors.installed_date}>
          <Input id="dv-installed" type="date" max={todayYmd()} value={v.installed_date} onChange={(e) => setV({ ...v, installed_date: e.target.value })} />
        </Field>
        {dvr ? (
          <label className="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" checked={v.is_active} onChange={(e) => setV({ ...v, is_active: e.target.checked })} />
            {t('store.inUse')}
          </label>
        ) : null}
      </div>
    </Modal>
  )
}
