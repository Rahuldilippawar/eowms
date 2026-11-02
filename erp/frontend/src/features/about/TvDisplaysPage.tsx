import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, MonitorPlay, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useToast } from '@/components/ui/Toast'
import { formatDateTime } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { createTvDisplay, deleteTvDisplay, fetchTvDisplays, type TvDisplay } from './api'

/** Notice-board screens and their secret links (erp/docs/ABOUT-AND-TV.md). */
export function TvDisplaysPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['tv-displays'], queryFn: fetchTvDisplays })
  const [name, setName] = useState('')
  const [link, setLink] = useState<string | null>(null)
  const [removing, setRemoving] = useState<TvDisplay | null>(null)
  const create = useMutation({
    mutationFn: () => createTvDisplay(name.trim()),
    onSuccess: (d) => {
      setLink(`${window.location.origin}${d.url}`)
      setName('')
      void qc.invalidateQueries({ queryKey: ['tv-displays'] })
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('nav.tvDisplays')}</h1>
        <p className="mt-1 text-sm text-slate-500">{t('tv.hint')}</p>
      </div>
      <form
        className="flex flex-wrap items-end gap-3"
        onSubmit={(e) => {
          e.preventDefault()
          create.mutate()
        }}
      >
        <div className="w-full sm:w-72">
          <Field id="tv-name" label={t('tv.screenName')}>
            <Input id="tv-name" value={name} maxLength={100} placeholder={t('tv.screenNameHint')} onChange={(e) => setName(e.target.value)} />
          </Field>
        </div>
        <Button type="submit" className="gap-2" disabled={create.isPending || !name.trim()}>
          <MonitorPlay aria-hidden className="size-4" />
          {t('tv.create')}
        </Button>
      </form>
      {link ? (
        <div role="status" className="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-700 dark:bg-amber-950">
          <p className="font-medium">{t('tv.linkOnce')}</p>
          <div className="flex flex-wrap items-center gap-2">
            <code className="min-w-0 flex-1 break-all rounded bg-white px-2 py-1 text-xs dark:bg-slate-900">{link}</code>
            <Button
              variant="secondary"
              className="gap-2"
              onClick={() => void navigator.clipboard?.writeText(link).then(() => toast.success(t('tv.copied')), () => undefined)}
            >
              <Copy aria-hidden className="size-4" />
              {t('tv.copy')}
            </Button>
          </div>
        </div>
      ) : null}
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.length === 0 ? <EmptyState message={t('tv.none')} /> : null}
      {q.data && q.data.length > 0 ? (
        <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {q.data.map((d) => (
            <li key={d.id} className="flex items-center justify-between gap-3 p-3">
              <div>
                <p className="font-medium">{d.name}</p>
                <p className="text-xs text-slate-500">{d.last_seen_at ? t('tv.lastSeen', { date: formatDateTime(d.last_seen_at) }) : t('tv.neverSeen')}</p>
              </div>
              <Button variant="ghost" className="px-2" aria-label={t('tv.removeNamed', { name: d.name })} onClick={() => setRemoving(d)}>
                <Trash2 aria-hidden className="size-4" />
              </Button>
            </li>
          ))}
        </ul>
      ) : null}
      <ConfirmDialog
        open={removing !== null}
        onOpenChange={(o) => !o && setRemoving(null)}
        title={t('tv.removeTitle')}
        body={t('tv.removeBody')}
        confirmLabel={t('common.delete')}
        danger
        onConfirm={async () => {
          await deleteTvDisplay(removing!.id)
          await qc.invalidateQueries({ queryKey: ['tv-displays'] })
        }}
      />
    </div>
  )
}
