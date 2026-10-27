import { useTranslation } from 'react-i18next'
import { CsvImport } from '@/components/data/CsvImport'
import { itTemplateUrl, previewItImport, runItImport } from './api'

export function ItImportPage() {
  const { t } = useTranslation()
  return (
    <CsvImport
      title={t('it.import')}
      templateUrl={itTemplateUrl}
      preview={previewItImport}
      run={runItImport}
      invalidate={['it-assets', 'it-dashboard', 'it-labs']}
      doneLink={{ to: '/it/assets', label: t('it.assetsTitle') }}
      doneText={(count) => t('it.imported', { count })}
      columns={[
        { header: t('it.lab'), cell: (d) => String(d.lab_name ?? '') },
        { header: t('it.assetType'), cell: (d) => String(d.asset_type ?? '') },
        { header: t('it.field.serial_no'), cell: (d) => String(d.serial_no ?? '—') },
      ]}
      note={t('it.importNote')}
    />
  )
}
