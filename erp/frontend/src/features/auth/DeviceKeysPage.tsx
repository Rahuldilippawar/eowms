import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Fingerprint, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useToast } from '@/components/ui/Toast'
import { parseApiError } from '@/lib/api'
import { formatDateTime } from '@/lib/format'
import { addKey, deleteKey, fetchKeys, webauthnSupported, type DeviceKey } from './webauthn'

/** One's own fingerprint / face / device-PIN keys (erp/docs/WEBAUTHN.md). */
export function DeviceKeysPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['device-keys'], queryFn: fetchKeys })
  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [removing, setRemoving] = useState<DeviceKey | null>(null)
  const add = useMutation({
    mutationFn: () => addKey(name.trim(), password),
    onSuccess: (keys) => {
      qc.setQueryData(['device-keys'], keys)
      setName('')
      setPassword('')
      setError(null)
      toast.success(t('fingerprint.added'))
    },
    onError: (e) => {
      if (e instanceof DOMException) return setError(e.name === 'NotAllowedError' ? null : t('fingerprint.deviceFailed'))
      const { errors } = parseApiError(e)
      setError(errors ? Object.values(errors)[0]?.[0] ?? t('common.actionFailed') : t('common.actionFailed'))
    },
  })
  const supported = webauthnSupported()

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('fingerprint.title')}</h1>
        <p className="mt-1 text-sm text-slate-500">{t('fingerprint.hint')}</p>
      </div>
      <section aria-labelledby="fp-add" className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="fp-add" className="font-semibold">
          {t('fingerprint.addTitle')}
        </h2>
        {!supported ? (
          <p role="status" className="text-sm text-amber-800 dark:text-amber-300">
            {t('fingerprint.unsupported')}
          </p>
        ) : (
          <form
            className="space-y-3"
            onSubmit={(e) => {
              e.preventDefault()
              add.mutate()
            }}
          >
            <Field id="fp-name" label={t('fingerprint.deviceName')}>
              <Input id="fp-name" value={name} maxLength={100} placeholder={t('fingerprint.deviceNameHint')} onChange={(e) => setName(e.target.value)} />
            </Field>
            <Field id="fp-password" label={t('fingerprint.yourPassword')}>
              <Input id="fp-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
            </Field>
            {error ? (
              <p role="alert" className="text-sm text-red-600">
                {error}
              </p>
            ) : null}
            <Button type="submit" className="gap-2" disabled={add.isPending || !name.trim() || !password}>
              <Fingerprint aria-hidden className="size-5" />
              {t('fingerprint.add')}
            </Button>
          </form>
        )}
      </section>
      <section aria-labelledby="fp-list" className="space-y-3">
        <h2 id="fp-list" className="font-semibold">
          {t('fingerprint.listTitle')}
        </h2>
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.length === 0 ? <EmptyState message={t('fingerprint.none')} /> : null}
        {q.data && q.data.length > 0 ? (
          <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
            {q.data.map((k) => (
              <li key={k.id} className="flex items-center justify-between gap-3 p-3">
                <div>
                  <p className="font-medium">{k.name}</p>
                  <p className="text-xs text-slate-500">
                    {t('fingerprint.addedOn', { date: formatDateTime(k.created_at) })}
                    {k.last_used_at ? ` · ${t('fingerprint.lastUsed', { date: formatDateTime(k.last_used_at) })}` : ''}
                  </p>
                </div>
                <Button variant="ghost" className="px-2" aria-label={t('fingerprint.removeNamed', { name: k.name })} onClick={() => setRemoving(k)}>
                  <Trash2 aria-hidden className="size-4" />
                </Button>
              </li>
            ))}
          </ul>
        ) : null}
      </section>
      <ConfirmDialog
        open={removing !== null}
        onOpenChange={(o) => !o && setRemoving(null)}
        title={t('fingerprint.removeTitle')}
        body={removing?.name ?? ''}
        confirmLabel={t('fingerprint.remove')}
        danger
        onConfirm={async () => {
          qc.setQueryData(['device-keys'], await deleteKey(removing!.id))
        }}
      />
    </div>
  )
}
