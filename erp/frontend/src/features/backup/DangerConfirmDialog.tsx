import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { parseApiError } from '@/lib/api'
import type { Confirmation } from './api'

/** For actions that cannot be undone here: the person's password again and an exact phrase typed. */
export function DangerConfirmDialog({ open, onOpenChange, title, body, phrase, confirmLabel, onConfirm }: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  body: string
  phrase: string
  confirmLabel: string
  onConfirm: (c: Confirmation) => Promise<void>
}) {
  const { t } = useTranslation()
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  const close = (o: boolean) => {
    if (busy) return
    if (!o) {
      setPassword('')
      setConfirmation('')
      setErrors({})
      setGeneral(null)
    }
    onOpenChange(o)
  }
  const submit = async () => {
    setBusy(true)
    setErrors({})
    setGeneral(null)
    try {
      await onConfirm({ password, confirmation })
      setBusy(false)
      close(false)
    } catch (e) {
      const err = parseApiError(e)
      const fields = Object.fromEntries(Object.entries(err.errors ?? {}).map(([k, v]) => [k, v[0]]))
      setErrors(fields)
      if (!fields.password && !fields.confirmation) setGeneral(err.message ?? t('common.actionFailed'))
      setBusy(false)
    }
  }

  return (
    <Modal
      open={open}
      onOpenChange={close}
      title={title}
      description={body}
      footer={
        <>
          <Button variant="secondary" disabled={busy} onClick={() => close(false)}>
            {t('common.cancel')}
          </Button>
          <Button className="bg-red-600 hover:bg-red-700 focus-visible:ring-red-500" disabled={busy || !password || confirmation.trim() !== phrase} onClick={() => void submit()}>
            {busy ? <Spinner label={t('backup.working')} /> : confirmLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field id="dc-confirm" label={t('backup.typeToConfirm', { phrase })} error={errors.confirmation}>
          <Input id="dc-confirm" autoComplete="off" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} aria-invalid={!!errors.confirmation} />
        </Field>
        <Field id="dc-password" label={t('backup.yourPassword')} error={errors.password}>
          <Input id="dc-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} aria-invalid={!!errors.password} />
        </Field>
        {general ? (
          <p role="alert" className="text-sm text-red-600">
            {general}
          </p>
        ) : null}
      </div>
    </Modal>
  )
}
