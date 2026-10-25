import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from './Button'
import { Modal } from './Modal'
import { Spinner } from './Spinner'

interface ConfirmDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  body: string
  confirmLabel: string
  danger?: boolean
  /** Ask for a reason (stored in the audit log). */
  withReason?: boolean
  onConfirm: (reason: string) => Promise<void>
}

export function ConfirmDialog({ open, onOpenChange, title, body, confirmLabel, danger, withReason, onConfirm }: ConfirmDialogProps) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const confirm = async () => {
    setBusy(true)
    setError(null)
    try {
      await onConfirm(reason.trim())
      setReason('')
      onOpenChange(false)
    } catch (e) {
      setError(e instanceof Error && e.message ? e.message : t('common.actionFailed'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open={open}
      onOpenChange={(o) => !busy && onOpenChange(o)}
      title={title}
      description={body}
      footer={
        <>
          <Button variant="secondary" disabled={busy} onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button className={danger ? 'bg-red-600 hover:bg-red-700 focus-visible:ring-red-500' : undefined} disabled={busy} onClick={() => void confirm()}>
            {busy ? <Spinner label={t('common.saving')} /> : confirmLabel}
          </Button>
        </>
      }
    >
      {withReason ? (
        <label className="block text-sm">
          <span className="mb-1.5 block font-medium text-slate-700 dark:text-slate-300">{t('common.reasonOptional')}</span>
          <textarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            maxLength={500}
            rows={2}
            className="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
          />
        </label>
      ) : null}
      {error ? (
        <p role="alert" className="mt-3 text-sm text-red-600">
          {error}
        </p>
      ) : null}
    </Modal>
  )
}
