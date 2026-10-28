import { useTranslation } from 'react-i18next'
import { CsvImport } from '@/components/data/CsvImport'
import { cctvTemplateUrl, previewCctvImport, runCctvImport } from './api'

export function CctvImportPage() {
  const { t } = useTranslation()
  return (
    <CsvImport
      title={t('cctv.import')}
      templateUrl={cctvTemplateUrl}
      preview={previewCctvImport}
      run={runCctvImport}
      invalidate={['cctv-dashboard', 'cctv-dvr']}
      doneLink={{ to: '/cctv', label: t('nav.cctv') }}
      doneText={(count) => t('cctv.imported', { count })}
      columns={[
        { header: t('cctv.dvrName'), cell: (d) => String(d.dvr_name ?? '') },
        { header: t('cctv.cameraName'), cell: (d) => String(d.camera_name ?? '') },
      ]}
      note={t('cctv.importNote')}
    />
  )
}
