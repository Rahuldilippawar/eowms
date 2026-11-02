import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, DatabaseZap, XCircle } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { useToast } from '@/components/ui/Toast'
import { DangerConfirmDialog } from '@/features/backup/DangerConfirmDialog'
import { formatDateTime } from '@/lib/format'
import { fetchSystem, runMigrations, SYSTEM_CHECKS } from './api'

/** System status and the database update for super admins (erp/docs/SYSTEM.md). */
export function SystemPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['system'], queryFn: fetchSystem })
  const [open, setOpen] = useState(false)
  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const s = q.data
  const facts: [string, string][] = [
    [t('system.version'), s.version || '—'],
    ['PHP', s.php],
    ['Laravel', s.laravel],
    [t('system.database'), s.database ?? '—'],
    [t('system.appUrl'), s.app_url],
    [t('system.fingerprintDomain'), s.webauthn_rp_id],
    [t('system.schedulerLastRun'), s.scheduler_last_run ? formatDateTime(s.scheduler_last_run) : t('system.never')],
    [t('system.latestBackup'), s.latest_backup ? formatDateTime(s.latest_backup.created_at) : t('system.never')],
  ]
  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <h1 className="text-2xl font-semibold">{t('nav.system')}</h1>
      <section aria-labelledby="sys-checks" className="space-y-2 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="sys-checks" className="font-semibold">
          {t('system.checksTitle')}
        </h2>
        <ul className="space-y-1">
          {SYSTEM_CHECKS.map((c) => (
            <li key={c} className="flex items-center gap-2 text-sm">
              {s.checks[c] ? <CheckCircle2 aria-hidden className="size-4 text-green-700" /> : <XCircle aria-hidden className="size-4 text-red-700" />}
              <span>{t(`system.checks.${c}`)}</span>
              <span className="sr-only">{s.checks[c] ? t('system.ok') : t('system.notOk')}</span>
            </li>
          ))}
        </ul>
      </section>
      <section aria-labelledby="sys-db" className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="sys-db" className="font-semibold">
          {t('system.updateTitle')}
        </h2>
        {s.pending_migrations.length === 0 ? (
          <p role="status" className="text-sm text-green-700">
            {t('system.upToDate')}
          </p>
        ) : (
          <>
            <p className="text-sm">{t('system.pending', { count: s.pending_migrations.length })}</p>
            <ul className="list-inside list-disc font-mono text-xs text-slate-600 dark:text-slate-400">
              {s.pending_migrations.map((m) => (
                <li key={m}>{m}</li>
              ))}
            </ul>
            <Button className="gap-2" onClick={() => setOpen(true)}>
              <DatabaseZap aria-hidden className="size-4" />
              {t('system.update')}
            </Button>
          </>
        )}
      </section>
      <dl className="grid gap-x-4 gap-y-2 rounded-xl border border-slate-200 bg-white p-4 text-sm sm:grid-cols-[12rem_1fr] dark:border-slate-800 dark:bg-slate-900">
        {facts.map(([k, v]) => (
          <div key={k} className="contents">
            <dt className="text-slate-500">{k}</dt>
            <dd className="break-all">{v}</dd>
          </div>
        ))}
      </dl>
      <DangerConfirmDialog
        open={open}
        onOpenChange={setOpen}
        title={t('system.updateTitle')}
        body={t('system.updateBody')}
        phrase="MIGRATE"
        confirmLabel={t('system.update')}
        onConfirm={async (c) => {
          await runMigrations(c)
          toast.success(t('system.updated'))
          await qc.invalidateQueries({ queryKey: ['system'] })
        }}
      />
    </div>
  )
}
