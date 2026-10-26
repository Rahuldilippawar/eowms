import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CsvImport } from '@/components/data/CsvImport'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate } from '@/lib/format'
import { fetchImportHistory, previewResidencyImport, residencyImportTemplateUrl, rollbackImport, runResidencyImport, type ImportBatch } from './api'
import { ResidencyNav } from './ResidencyNav'

export function ResidencyImportPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const key = ['residency-import-history', user?.current_organization?.id]
  const history = useQuery({ queryKey: key, queryFn: fetchImportHistory })
  const [undo, setUndo] = useState<ImportBatch | null>(null)
  const rollback = useMutation({ mutationFn: rollbackImport })

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <ResidencyNav />
      <CsvImport
        title={t('residency.import')}
        templateUrl={residencyImportTemplateUrl}
        preview={previewResidencyImport}
        run={runResidencyImport}
        invalidate={['residency-rooms', 'residency-import-history', 'residency-directory', 'residency-unlinked']}
        doneLink={{ to: '/residency', label: t('residency.allRooms') }}
        doneText={(count) => t('residency.importDone', { count })}
        columns={[
          { header: t('residency.room'), cell: (d) => [d.building_name, d.room_name].filter(Boolean).join(' · ') },
          { header: t('residency.name'), cell: (d) => String(d.name ?? '') },
        ]}
        note={t('residency.importNote')}
      />
      <section aria-labelledby="ri-history" className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <h2 id="ri-history" className="px-4 pt-4 font-semibold">
          {t('residency.importHistory')}
        </h2>
        {history.isPending ? <LoadingState /> : null}
        {history.isError ? <ErrorState onRetry={() => void history.refetch()} /> : null}
        {history.data && history.data.length === 0 ? <EmptyState message={t('residency.noImports')} /> : null}
        <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
          {(history.data ?? []).map((b) => (
            <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
              <span>
                <span className="font-medium">{b.filename ?? `#${b.id}`}</span> {b.status === 'rolled_back' ? <Badge>{t('residency.rolledBack')}</Badge> : null}
                <span className="block text-slate-500">
                  {[formatDate(b.created_at?.slice(0, 10)), b.imported_by, t('residency.batchCounts', { buildings: b.buildings_created, rooms: b.rooms_created, occupants: b.occupants_created })].filter(Boolean).join(' · ')}
                </span>
              </span>
              {b.status === 'completed' ? (
                <Button variant="secondary" onClick={() => setUndo(b)}>
                  {t('residency.rollback')}
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      </section>
      <ConfirmDialog
        open={!!undo}
        onOpenChange={(o) => !o && setUndo(null)}
        title={t('residency.rollbackTitle')}
        body={t('residency.rollbackBody')}
        confirmLabel={t('residency.rollback')}
        danger
        onConfirm={async () => {
          if (!undo) return
          const rows = await rollback.mutateAsync(undo.id)
          queryClient.setQueryData(key, rows)
          void queryClient.invalidateQueries({ queryKey: ['residency-rooms'] })
          toast.success(t('residency.rolledBack'))
          setUndo(null)
        }}
      />
    </div>
  )
}
