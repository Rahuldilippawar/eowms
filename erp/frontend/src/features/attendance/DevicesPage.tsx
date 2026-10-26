import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchDevices, fetchPunches, saveDevice, type Device } from './parityApi'

function DeviceDialog({ open, onOpenChange, device }: { open: boolean; onOpenChange: (o: boolean) => void; device?: Device }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [v, setV] = useState({ name: '', serial_number: '', is_active: true })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    if (open) {
      setV({ name: device?.name ?? '', serial_number: device?.serial_number ?? '', is_active: device?.is_active ?? true })
      setErrors({})
    }
  }, [open, device])

  const submit = async () => {
    setBusy(true)
    try {
      await saveDevice(device?.id ?? null, { ...v, name: v.name.trim(), serial_number: v.serial_number.trim(), institution_id: device?.institution?.id ?? null })
      void queryClient.invalidateQueries({ queryKey: ['devices'] })
      toast.success(t('form.saved'))
      onOpenChange(false)
    } catch (e) {
      setErrors(splitServerErrors(e, ['name', 'serial_number'], t('common.actionFailed')).byField)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title={device ? t('devices.edit') : t('devices.new')}
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button disabled={busy} onClick={() => void submit()}>
            {busy ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="dv-name" label={t('org.name')} error={errors.name}>
          <Input id="dv-name" value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} />
        </Field>
        <Field id="dv-sn" label={t('devices.serial')} error={errors.serial_number}>
          <Input id="dv-sn" value={v.serial_number} className="font-mono" onChange={(e) => setV({ ...v, serial_number: e.target.value })} />
        </Field>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={v.is_active} onChange={(e) => setV({ ...v, is_active: e.target.checked })} />
          {t('org.active')}
        </label>
      </div>
    </Modal>
  )
}

export function DevicesPage() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const orgKey = user?.current_organization?.id
  const devices = useQuery({ queryKey: ['devices', orgKey], queryFn: fetchDevices })
  const unknown = useQuery({ queryKey: ['punches', orgKey, 'unknown_pin'], queryFn: () => fetchPunches('unknown_pin') })
  const [editing, setEditing] = useState<Device | undefined>()
  const [open, setOpen] = useState(false)
  const when = (iso: string | null) => (iso ? new Intl.DateTimeFormat(i18n.language === 'mr' ? 'mr-IN' : 'en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : t('devices.never'))

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.devices')}</h1>
        <Button
          onClick={() => {
            setEditing(undefined)
            setOpen(true)
          }}
        >
          <Plus aria-hidden className="size-4" />
          {t('devices.new')}
        </Button>
      </div>
      <p className="text-sm text-slate-600 dark:text-slate-400">{t('devices.hint', { url: `${window.location.origin}/iclock/cdata` })}</p>
      {devices.isPending ? <LoadingState /> : null}
      {devices.isError ? <ErrorState onRetry={() => void devices.refetch()} /> : null}
      {devices.data && devices.data.length === 0 ? <EmptyState message={t('devices.none')} /> : null}
      {devices.data && devices.data.length > 0 ? (
        <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {devices.data.map((d) => (
            <li key={d.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
              <div>
                <p className="font-medium">{d.name}</p>
                <p className="text-sm text-slate-500">
                  <span className="font-mono">{d.serial_number}</span> · {t('devices.lastSeen')}: {when(d.last_seen_at)}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge tone={d.is_active ? 'green' : 'slate'}>{d.is_active ? t('org.active') : t('org.inactive')}</Badge>
                <Button
                  variant="ghost"
                  className="min-h-8 px-2 py-1 text-xs"
                  aria-label={t('common.editNamed', { name: d.name })}
                  onClick={() => {
                    setEditing(d)
                    setOpen(true)
                  }}
                >
                  {t('common.edit')}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      ) : null}
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 className="mb-2 font-semibold">{t('devices.unknownPins')}</h2>
        <p className="mb-2 text-sm text-slate-500">{t('devices.unknownHint')}</p>
        {unknown.data && unknown.data.length === 0 ? <p className="text-sm text-slate-500">{t('devices.allMatched')}</p> : null}
        <ul className="space-y-1 text-sm">
          {(unknown.data ?? []).slice(0, 50).map((p) => (
            <li key={p.id}>
              <span className="font-mono">{p.pin}</span> · {p.device} · {when(p.punched_at)}
            </li>
          ))}
        </ul>
      </section>
      <DeviceDialog open={open} onOpenChange={setOpen} device={editing} />
    </div>
  )
}
