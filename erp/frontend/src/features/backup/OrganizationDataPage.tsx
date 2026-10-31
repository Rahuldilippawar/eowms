import { useQueryClient } from '@tanstack/react-query'
import { Download, Eraser } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { clearOrganization, ORGANIZATION_EXPORT_URL } from './api'
import { DangerConfirmDialog } from './DangerConfirmDialog'

/** An organization's own data: export it, or clear its working data (erp/docs/BACKUP.md). */
export function OrganizationDataPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const toast = useToast()
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [cleared, setCleared] = useState<number | null>(null)
  const orgName = user?.current_organization?.name ?? ''
  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <h1 className="text-2xl font-semibold">{t('nav.organizationData')}</h1>
      <section aria-labelledby="od-export" className="space-y-2 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="od-export" className="font-semibold">
          {t('backup.exportTitle')}
        </h2>
        <p className="text-sm text-slate-600 dark:text-slate-400">{t('backup.exportBody')}</p>
        <a href={ORGANIZATION_EXPORT_URL} className="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
          <Download aria-hidden className="size-4" />
          {t('backup.exportButton')}
        </a>
      </section>
      <section aria-labelledby="od-clear" className="space-y-2 rounded-xl border border-red-300 bg-white p-4 dark:border-red-800 dark:bg-slate-900">
        <h2 id="od-clear" className="font-semibold text-red-700">
          {t('backup.clearTitle')}
        </h2>
        <p className="text-sm text-slate-600 dark:text-slate-400">{t('backup.clearBody')}</p>
        {cleared !== null ? (
          <p role="status" className="text-sm font-medium text-green-700">
            {t('backup.clearedCount', { count: cleared })}
          </p>
        ) : null}
        <Button className="gap-2 bg-red-600 hover:bg-red-700 focus-visible:ring-red-500" onClick={() => setOpen(true)}>
          <Eraser aria-hidden className="size-4" />
          {t('backup.clearButton')}
        </Button>
      </section>
      <DangerConfirmDialog
        open={open}
        onOpenChange={setOpen}
        title={t('backup.clearTitle')}
        body={t('backup.clearConfirmBody')}
        phrase={orgName}
        confirmLabel={t('backup.clearButton')}
        onConfirm={async (c) => {
          const res = await clearOrganization(c)
          setCleared(Object.values(res.deleted).reduce((a, b) => a + b, 0))
          toast.success(t('backup.cleared'))
          await qc.invalidateQueries()
        }}
      />
    </div>
  )
}
