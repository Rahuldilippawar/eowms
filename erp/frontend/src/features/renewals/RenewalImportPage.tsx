import { useTranslation } from 'react-i18next'
import { CsvImport } from '@/components/data/CsvImport'
import { formatDate } from '@/lib/format'
import { previewRenewalImport, renewalImportTemplateUrl, runRenewalImport } from './api'

export function RenewalImportPage() {
  const { t } = useTranslation()
  return (
    <CsvImport
      title={t('renewals.importTitle')}
      templateUrl={renewalImportTemplateUrl}
      preview={previewRenewalImport}
      run={runRenewalImport}
      invalidate={['renewals']}
      doneLink={{ to: '/renewals', label: t('nav.renewals') }}
      doneText={(count) => t('renewals.importDone', { count })}
      columns={[
        { header: t('renewals.itemName'), cell: (d) => d.item_name },
        { header: t('renewals.expiresOn'), cell: (d) => (d.expiry_date ? formatDate(String(d.expiry_date)) : '—') },
      ]}
    />
  )
}
