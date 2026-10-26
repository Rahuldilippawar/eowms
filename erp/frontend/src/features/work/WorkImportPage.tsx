import { useTranslation } from 'react-i18next'
import { CsvImport } from '@/components/data/CsvImport'
import { formatDate } from '@/lib/format'
import { previewWorkImport, runWorkImport, workImportTemplateUrl } from './api'

/** Old work records from the previous app, matched to employees by code. */
export function WorkImportPage() {
  const { t } = useTranslation()
  return (
    <CsvImport
      title={t('work.importTitle')}
      templateUrl={workImportTemplateUrl}
      preview={previewWorkImport}
      run={runWorkImport}
      invalidate={['team-work', 'my-work']}
      doneLink={{ to: '/work', label: t('nav.teamWork') }}
      note={t('work.importNote')}
      doneText={(count) => t('work.importDone', { count })}
      columns={[
        { header: t('attendance.date'), cell: (d) => (d.work_date ? formatDate(String(d.work_date)) : '—') },
        { header: t('work.whatDidYouDo'), cell: (d) => d.title },
      ]}
    />
  )
}
