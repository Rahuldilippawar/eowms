import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Download, Printer } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/features/auth/AuthProvider'
import { addDays, formatDate, formatMoney, todayYmd } from '@/lib/format'
import { fetchVehicleReport, vehicleReportCsvUrl } from './api'

const cell = 'px-3 py-2'

export function VehicleReportPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const vehicleId = Number(id)
  const { can } = useAuth()
  const [from, setFrom] = useState(() => addDays(todayYmd(), -90))
  const [to, setTo] = useState(todayYmd)
  const q = useQuery({ queryKey: ['vehicle-report', vehicleId, from, to], queryFn: () => fetchVehicleReport(vehicleId, from, to), enabled: !!from && !!to && from <= to })
  const r = q.data

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <Link to={`/vehicles/${vehicleId}`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline print:hidden">
        <ArrowLeft aria-hidden className="size-4" />
        {r?.vehicle.vehicle_number ?? t('nav.vehicles')}
      </Link>
      <h1 className="text-2xl font-semibold">{t('vehicles.reportTitle', { number: r?.vehicle.vehicle_number ?? '' })}</h1>
      <div className="flex flex-wrap items-end gap-3 print:hidden">
        <Field id="vr-from" label={t('vehicles.fromDate')}>
          <Input id="vr-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </Field>
        <Field id="vr-to" label={t('vehicles.toDate')}>
          <Input id="vr-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </Field>
        <Button variant="secondary" onClick={() => window.print()}>
          <Printer aria-hidden className="size-4" />
          {t('vehicles.print')}
        </Button>
        {can('vehicles.export') ? (
          <a href={vehicleReportCsvUrl(vehicleId, from, to)} className="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
            <Download aria-hidden className="size-4" />
            {t('attendance.downloadCsv')}
          </a>
        ) : null}
      </div>
      {q.isPending && q.fetchStatus !== 'idle' ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {r ? (
        <>
          <p className="text-sm text-slate-500">{t('vehicles.period', { from: formatDate(r.from), to: formatDate(r.to) })}</p>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
            {[
              [t('vehicles.tabs.trip'), String(r.totals.trips)],
              [t('vehicles.totalKm'), r.totals.km.toLocaleString('en-IN')],
              [t('vehicles.mileage'), r.totals.km_per_liter === null ? '—' : t('vehicles.kmpl', { value: r.totals.km_per_liter })],
              [t('vehicles.fuelCost'), formatMoney(r.totals.fuel_cost)],
              [t('vehicles.serviceCost'), formatMoney(r.totals.service_cost)],
            ].map(([label, value]) => (
              <div key={label} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                <p className="text-lg font-semibold tabular-nums">{value}</p>
                <p className="text-xs text-slate-500">{label}</p>
              </div>
            ))}
          </div>
          {(
            [
              ['trip', r.trips.map((e) => [formatDate(e.date), `${e.from_location} → ${e.to_location}`, `${e.start_odometer} – ${e.end_odometer}`, t('vehicles.km', { km: e.distance_km }), e.purpose ?? ''])],
              ['fuel', r.fuel.map((e) => [formatDate(e.date), e.fuel_station ?? '', String(e.odometer_reading), t('vehicles.litersShort', { liters: e.liters }), e.cost === null ? '' : formatMoney(e.cost)])],
              ['service', r.services.map((e) => [formatDate(e.date), e.service_type, e.odometer_reading === null ? '' : String(e.odometer_reading), e.vendor ?? '', e.cost === null ? '' : formatMoney(e.cost)])],
            ] as const
          ).map(([kind, rows]) => (
            <section key={kind} className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
              <h2 className="px-4 pt-3 font-semibold">{t(`vehicles.tabs.${kind}`)}</h2>
              {rows.length === 0 ? (
                <p className="px-4 py-3 text-sm text-slate-500">{t('vehicles.noEntries')}</p>
              ) : (
                <table className="w-full text-sm">
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {rows.map((row, i) => (
                      <tr key={i}>
                        {row.map((c, j) => (
                          <td key={j} className={cell}>
                            {c}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </section>
          ))}
        </>
      ) : null}
    </div>
  )
}
