import { useTranslation } from 'react-i18next'
import { CsvImport } from '@/components/data/CsvImport'
import { previewVehicleImport, runVehicleImport, vehicleImportTemplateUrl } from './api'

export function VehicleImportPage() {
  const { t } = useTranslation()
  return (
    <CsvImport
      title={t('vehicles.importTitle')}
      templateUrl={vehicleImportTemplateUrl}
      preview={previewVehicleImport}
      run={runVehicleImport}
      invalidate={['vehicles']}
      doneLink={{ to: '/vehicles', label: t('nav.vehicles') }}
      doneText={(count) => t('vehicles.importDone', { count })}
      columns={[
        { header: t('vehicles.number'), cell: (d) => String(d.vehicle_number ?? '') },
        { header: t('vehicles.type'), cell: (d) => String(d.vehicle_type ?? '—') },
      ]}
      note={t('vehicles.importNote')}
    />
  )
}
