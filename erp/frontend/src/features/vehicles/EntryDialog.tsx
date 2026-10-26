import { useMutation } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { todayYmd } from '@/lib/format'
import type { EntryInput, EntryType } from './api'
import { FIELDS, toInput, type Values } from './entryFields'

export function EntryDialog({
  type,
  title,
  initial,
  submitLabel,
  extra,
  onSubmit,
  onClose,
}: {
  type: EntryType
  title: string
  initial: Values
  submitLabel?: string
  extra?: ReactNode
  onSubmit: (input: EntryInput) => Promise<unknown>
  onClose: () => void
}) {
  const { t } = useTranslation()
  const [v, setV] = useState<Values>(initial)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const fields = FIELDS[type]
  const save = useMutation({
    mutationFn: () => onSubmit(toInput(type, v)),
    onSuccess: onClose,
    onError: (e) => {
      const r = splitServerErrors(
        e,
        fields.map((f) => f.key),
        t('common.actionFailed'),
      )
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const submit = () => {
    const local: Record<string, string> = {}
    for (const f of fields) if (f.required && !v[f.key].trim()) local[f.key] = t('form.required')
    if (type === 'trip' && v.start_odometer && v.end_odometer && Number(v.end_odometer) < Number(v.start_odometer)) local.end_odometer = t('vehicles.endBeforeStart')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }
  const distance = type === 'trip' && v.start_odometer && v.end_odometer ? Number(v.end_odometer) - Number(v.start_odometer) : null

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={title}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={submit}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : (submitLabel ?? t('common.save'))}
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
        {fields.map((f) => (
          <div key={f.key} className={f.wide ? 'sm:col-span-2' : undefined}>
            <Field id={`en-${f.key}`} label={t(f.label)} error={errors[f.key]}>
              {f.kind === 'textarea' ? (
                <Textarea id={`en-${f.key}`} rows={2} value={v[f.key]} maxLength={2000} onChange={(e) => setV({ ...v, [f.key]: e.target.value })} />
              ) : (
                <Input
                  id={`en-${f.key}`}
                  type={f.kind}
                  inputMode={f.kind === 'number' ? 'decimal' : undefined}
                  min={f.kind === 'number' ? 0 : undefined}
                  step={f.kind === 'number' ? 'any' : undefined}
                  max={f.kind === 'date' && !f.key.startsWith('next') ? todayYmd() : undefined}
                  value={v[f.key]}
                  maxLength={f.kind === 'text' ? 255 : undefined}
                  onChange={(e) => setV({ ...v, [f.key]: e.target.value })}
                />
              )}
            </Field>
          </div>
        ))}
        {distance !== null && distance >= 0 ? <p className="text-sm text-slate-600 sm:col-span-2">{t('vehicles.distance', { km: Math.round(distance * 10) / 10 })}</p> : null}
        {extra ? <div className="sm:col-span-2">{extra}</div> : null}
      </div>
    </Modal>
  )
}
