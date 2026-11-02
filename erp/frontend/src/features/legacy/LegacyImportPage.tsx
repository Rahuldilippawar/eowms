import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { DatabaseZap, FlaskConical } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { DangerConfirmDialog } from '@/features/backup/DangerConfirmDialog'
import { formatDateTime } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { fetchImporter, fetchRun, startRun, type StepReport } from './api'

function Report({ report }: { report: Record<string, StepReport> }) {
  const { t } = useTranslation()
  return (
    <div className="space-y-3">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[36rem] text-sm">
          <thead className="text-left text-slate-500">
            <tr>
              <th className="py-1 pr-3 font-medium">{t('legacy.step')}</th>
              {(['read', 'created', 'existing', 'matched', 'skipped'] as const).map((k) => (
                <th key={k} className="py-1 pr-3 text-right font-medium">
                  {t(`legacy.cols.${k}`)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {Object.entries(report).map(([step, r]) => (
              <tr key={step}>
                <th scope="row" className="py-1 pr-3 text-left font-normal">
                  {t(`legacy.steps.${step}`, { defaultValue: step })}
                </th>
                <td className="py-1 pr-3 text-right tabular-nums">{r.read}</td>
                <td className="py-1 pr-3 text-right tabular-nums">{r.created}</td>
                <td className="py-1 pr-3 text-right tabular-nums">{r.existing}</td>
                <td className="py-1 pr-3 text-right tabular-nums">{r.matched}</td>
                <td className={r.skipped ? 'py-1 pr-3 text-right font-semibold text-red-700 tabular-nums' : 'py-1 pr-3 text-right tabular-nums'}>{r.skipped}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {Object.entries(report).map(([step, r]) =>
        r.skips.length || r.warnings.length ? (
          <details key={step} className="rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-800">
            <summary className="cursor-pointer font-medium">
              {t(`legacy.steps.${step}`, { defaultValue: step })}: {t('legacy.notes', { skipped: r.skips.length, warnings: r.warnings.length })}
            </summary>
            <ul className="mt-2 list-inside list-disc space-y-0.5 text-xs">
              {r.skips.map((s, i) => (
                <li key={`s${i}`}>
                  {s.table} #{s.id ?? '—'}: {t(`legacy.reasons.${s.reason}`, { defaultValue: s.reason })}
                </li>
              ))}
              {r.warnings.map((w, i) => (
                <li key={`w${i}`} className="text-amber-800 dark:text-amber-300">
                  {w}
                </li>
              ))}
            </ul>
          </details>
        ) : null,
      )}
    </div>
  )
}

/** The old-data importer for super admins (erp/docs/LEGACY-IMPORT.md). */
export function LegacyImportPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['legacy-import'], queryFn: fetchImporter })
  const [shown, setShown] = useState<number | null>(null)
  const [confirming, setConfirming] = useState(false)
  const run = useQuery({ queryKey: ['legacy-run', shown], queryFn: () => fetchRun(shown!), enabled: shown !== null })
  const dry = useMutation({
    mutationFn: () => startRun('dry_run'),
    onSuccess: (r) => {
      setShown(r.id)
      void qc.invalidateQueries({ queryKey: ['legacy-import'] })
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const s = q.data.source
  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('nav.legacyImport')}</h1>
        <p className="mt-1 text-sm text-slate-500">{t('legacy.hint')}</p>
      </div>
      <section aria-labelledby="li-source" className="space-y-2 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="li-source" className="font-semibold">
          {t('legacy.source')}
        </h2>
        {s.connected ? (
          <>
            <p role="status" className="text-sm text-green-700">
              {t('legacy.connected')}
            </p>
            <p className="text-sm text-slate-600 dark:text-slate-400">
              {Object.entries(s.tables ?? {})
                .map(([k, n]) => `${k}: ${n}`)
                .join(' · ')}
            </p>
            <div className="flex flex-wrap gap-2 pt-1">
              <Button variant="secondary" className="gap-2" disabled={dry.isPending} onClick={() => dry.mutate()}>
                {dry.isPending ? <Spinner label={t('backup.working')} /> : <FlaskConical aria-hidden className="size-4" />}
                {t('legacy.dryRun')}
              </Button>
              <Button className="gap-2" disabled={dry.isPending} onClick={() => setConfirming(true)}>
                <DatabaseZap aria-hidden className="size-4" />
                {t('legacy.import')}
              </Button>
            </div>
          </>
        ) : (
          <p role="status" className="text-sm text-amber-800 dark:text-amber-300">
            {t(`legacy.sourceErrors.${s.error ?? 'not_configured'}`)}
          </p>
        )}
      </section>
      {shown !== null ? (
        <section aria-labelledby="li-report" className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
          <h2 id="li-report" className="font-semibold">
            {t('legacy.reportTitle', { id: shown })}
          </h2>
          {run.isPending ? <LoadingState /> : null}
          {run.data?.status === 'failed' ? (
            <p role="alert" className="text-sm text-red-700">
              {t('legacy.failed', { error: run.data.error ?? '' })}
            </p>
          ) : null}
          {run.data?.mode === 'dry_run' && run.data.status === 'done' ? <p className="text-sm text-slate-600">{t('legacy.dryRunNote')}</p> : null}
          {run.data?.report ? <Report report={run.data.report} /> : null}
        </section>
      ) : null}
      {q.data.runs.length > 0 ? (
        <section aria-labelledby="li-runs" className="space-y-2">
          <h2 id="li-runs" className="font-semibold">
            {t('legacy.runs')}
          </h2>
          <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
            {q.data.runs.map((r) => (
              <li key={r.id}>
                <button type="button" className="flex w-full items-center justify-between gap-3 p-3 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-800" onClick={() => setShown(r.id)}>
                  <span>
                    #{r.id} · {formatDateTime(r.started_at)}
                  </span>
                  <span className="flex gap-2">
                    <Badge tone={r.mode === 'import' ? 'blue' : 'slate'}>{t(`legacy.modes.${r.mode}`)}</Badge>
                    <Badge tone={r.status === 'done' ? 'green' : 'slate'}>{t(`legacy.statuses.${r.status}`)}</Badge>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      ) : null}
      <DangerConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title={t('legacy.import')}
        body={t('legacy.importBody')}
        phrase="IMPORT"
        confirmLabel={t('legacy.import')}
        onConfirm={async (c) => {
          const r = await startRun('import', c)
          setShown(r.id)
          if (r.status === 'done') toast.success(t('legacy.imported'))
          else toast.error(t('legacy.failed', { error: r.error ?? '' }))
          await qc.invalidateQueries({ queryKey: ['legacy-import'] })
        }}
      />
    </div>
  )
}
