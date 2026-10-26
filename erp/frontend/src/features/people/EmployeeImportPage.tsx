import { useTranslation } from 'react-i18next'
import { CsvImport } from '@/components/data/CsvImport'
import { importTemplateUrl, previewImport, runImport } from './profileApi'

export function EmployeeImportPage() {
  const { t } = useTranslation()
  return (
    <CsvImport
      title={t('import.employeesTitle')}
      templateUrl={importTemplateUrl}
      preview={previewImport}
      run={runImport}
      invalidate={['employees']}
      doneLink={{ to: '/employees', label: t('nav.employees') }}
      doneText={(count) => t('import.done', { count })}
      columns={[
        { header: t('people.employeeCode'), cell: (d) => <span className="font-mono text-xs">{d.employee_code}</span> },
        { header: t('org.name'), cell: (d) => d.name },
      ]}
    />
  )
}
