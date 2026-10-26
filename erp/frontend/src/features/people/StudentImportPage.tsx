import { useTranslation } from 'react-i18next'
import { CsvImport } from '@/components/data/CsvImport'
import { previewStudentImport, runStudentImport, studentImportTemplateUrl } from './api'

export function StudentImportPage() {
  const { t } = useTranslation()
  return (
    <CsvImport
      title={t('import.studentsTitle')}
      templateUrl={studentImportTemplateUrl}
      preview={previewStudentImport}
      run={runStudentImport}
      invalidate={['students']}
      doneLink={{ to: '/students', label: t('nav.students') }}
      doneText={(count) => t('import.studentsDone', { count })}
      columns={[
        { header: t('people.studentCode'), cell: (d) => <span className="font-mono text-xs">{d.student_code}</span> },
        { header: t('org.name'), cell: (d) => d.name },
      ]}
    />
  )
}
