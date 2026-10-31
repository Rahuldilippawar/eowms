import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { DatabaseBackup, Download, Trash2, Upload, RotateCcw } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { formatDateTime } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { backupDownloadUrl, createBackup, deleteBackup, fetchBackups, formatSize, restoreBackup, uploadBackup, type Backup } from './api'
import { DangerConfirmDialog } from './DangerConfirmDialog'

/** Whole-database backups (super admins; erp/docs/BACKUP.md). */
export function BackupsPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['backups'], queryFn: fetchBackups })
  const [restoring, setRestoring] = useState<Backup | null>(null)
  const [deleting, setDeleting] = useState<Backup | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)
  const refresh = () => qc.invalidateQueries({ queryKey: ['backups'] })
  const onError = (e: unknown) => toast.error(actionErrorMessage(e, t('common.actionFailed')))
  const create = useMutation({ mutationFn: createBackup, onSuccess: () => { toast.success(t('backup.created')); void refresh() }, onError })
  const upload = useMutation({ mutationFn: uploadBackup, onSuccess: () => { toast.success(t('backup.uploaded')); void refresh() }, onError })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{t('nav.backups')}</h1>
          <p className="mt-1 text-sm text-slate-500">{t('backup.hint', { count: q.data?.meta.keep_scheduled ?? 14 })}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button className="gap-2" disabled={create.isPending} onClick={() => create.mutate()}>
            {create.isPending ? <Spinner label={t('backup.working')} /> : <DatabaseBackup aria-hidden className="size-4" />}
            {t('backup.backupNow')}
          </Button>
          <Button variant="secondary" className="gap-2" disabled={upload.isPending} onClick={() => fileRef.current?.click()}>
            <Upload aria-hidden className="size-4" />
            {t('backup.upload')}
          </Button>
          <input
            ref={fileRef}
            type="file"
            accept=".gz,application/gzip"
            className="hidden"
            aria-label={t('backup.upload')}
            onChange={(e) => {
              const f = e.target.files?.[0]
              if (f) upload.mutate(f)
              e.target.value = ''
            }}
          />
        </div>
      </div>
      <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">{t('backup.filesNote')}</p>

      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.data.length === 0 ? <EmptyState message={t('backup.none')} /> : null}
      {q.data && q.data.data.length > 0 ? (
        <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {q.data.data.map((b) => (
            <li key={b.name} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <p className="font-medium">
                  {formatDateTime(b.created_at)} <Badge tone={b.kind === 'scheduled' ? 'slate' : b.kind === 'manual' ? 'green' : 'blue'}>{t(`backup.kinds.${b.kind}`)}</Badge>
                </p>
                <p className="truncate font-mono text-xs text-slate-500">
                  {b.name} · {formatSize(b.size)}
                </p>
              </div>
              <div className="flex flex-wrap gap-1">
                <a href={backupDownloadUrl(b.name)} className="inline-flex min-h-9 items-center gap-1 rounded-md px-2 text-sm font-medium text-brand-700 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label={t('backup.downloadNamed', { name: b.name })}>
                  <Download aria-hidden className="size-4" />
                  {t('backup.download')}
                </a>
                <Button variant="ghost" className="gap-1 px-2 text-red-700" aria-label={t('backup.restoreNamed', { name: b.name })} onClick={() => setRestoring(b)}>
                  <RotateCcw aria-hidden className="size-4" />
                  {t('backup.restore')}
                </Button>
                <Button variant="ghost" className="px-2" aria-label={t('backup.deleteNamed', { name: b.name })} onClick={() => setDeleting(b)}>
                  <Trash2 aria-hidden className="size-4" />
                </Button>
              </div>
            </li>
          ))}
        </ul>
      ) : null}

      <DangerConfirmDialog
        open={restoring !== null}
        onOpenChange={(o) => !o && setRestoring(null)}
        title={t('backup.restoreTitle')}
        body={t('backup.restoreBody', { date: restoring ? formatDateTime(restoring.created_at) : '' })}
        phrase="RESTORE"
        confirmLabel={t('backup.restore')}
        onConfirm={async (c) => {
          await restoreBackup(restoring!.name, c)
          toast.success(t('backup.restored'))
          // Everything on screen may have changed: start the app again.
          window.setTimeout(() => window.location.assign('/'), 1200)
        }}
      />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(o) => !o && setDeleting(null)}
        title={t('backup.deleteTitle')}
        body={deleting ? deleting.name : ''}
        confirmLabel={t('common.delete')}
        danger
        onConfirm={async () => {
          await deleteBackup(deleting!.name)
          await refresh()
        }}
      />
    </div>
  )
}
