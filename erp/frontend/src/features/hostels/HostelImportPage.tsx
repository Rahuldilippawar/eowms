import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { CsvImport } from '@/components/data/CsvImport'
import { previewResidentImport, residentImportTemplateUrl, runResidentImport } from './api'

export function HostelImportPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const hostelId = Number(id)
  return (
    <CsvImport
      title={t('hostels.import')}
      templateUrl={residentImportTemplateUrl}
      preview={previewResidentImport(hostelId)}
      run={runResidentImport(hostelId)}
      invalidate={['hostel-residents', 'hostel']}
      doneLink={{ to: `/hostels/${hostelId}`, label: t('hostels.backToHostel') }}
      doneText={(count) => t('hostels.importDone', { count })}
      columns={[
        { header: t('hostels.residentName'), cell: (d) => String(d.name ?? '') },
        { header: t('hostels.school'), cell: (d) => String(d.school_name ?? '—') },
      ]}
      note={t('hostels.importNote')}
    />
  )
}
