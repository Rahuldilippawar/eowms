import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, FileBarChart, Pencil, Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Field } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { RenewalStatusBadge } from '@/features/renewals/RenewalsPage'
import { formatDate, formatMoney } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import { addEntry, deleteEntry, fetchVehicle, requestCorrection, setVehicleActive, updateEntry, type Entry, type EntryType, type VehicleDetail } from './api'
import { EntryDialog } from './EntryDialog'
import { blankEntry, entryValues } from './entryFields'
import { ServiceDue, VehicleDialog } from './VehiclesPage'

const TABS: EntryType[] = ['trip', 'fuel', 'service']

function describe(e: Entry, t: (k: string, o?: Record<string, unknown>) => string): { title: string; sub: string; amount: string | null } {
  if (e.type === 'trip')
    return {
      title: `${e.from_location} → ${e.to_location}`,
      sub: [t('vehicles.kmRange', { from: e.start_odometer, to: e.end_odometer }), e.purpose].filter(Boolean).join(' · '),
      amount: t('vehicles.km', { km: e.distance_km }),
    }
  if (e.type === 'fuel')
    return { title: t('vehicles.litersAt', { liters: e.liters, km: e.odometer_reading }), sub: e.fuel_station ?? '', amount: e.cost === null ? null : formatMoney(e.cost) }
  return {
    title: e.service_type,
    sub: [e.vendor, e.next_due_date ? t('vehicles.nextOn', { date: formatDate(e.next_due_date) }) : null, e.next_due_km ? t('vehicles.nextAtKm', { km: e.next_due_km }) : null].filter(Boolean).join(' · '),
    amount: e.cost === null ? null : formatMoney(e.cost),
  }
}

function CorrectionDialog({ vehicle, entry, onClose }: { vehicle: VehicleDetail; entry: Entry; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [kind, setKind] = useState<'edit' | 'delete'>('edit')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const send = useMutation({
    mutationFn: () => requestCorrection(vehicle.id, entry.type, entry.id, { request_type: kind, reason: reason.trim() }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['vehicle', vehicle.id] })
      toast.success(t('vehicles.correctionSent'))
      onClose()
    },
    onError: (e) => setError(actionErrorMessage(e, t('common.actionFailed'))),
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t('vehicles.requestCorrection')}
      description={t('vehicles.correctionHint')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={send.isPending || reason.trim().length < 5} onClick={() => send.mutate()}>
            {send.isPending ? <Spinner label={t('common.saving')} /> : t('vehicles.sendRequest')}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {error ? (
          <p role="alert" className="text-sm text-red-600">
            {error}
          </p>
        ) : null}
        <Field id="cr-kind" label={t('vehicles.requestType')}>
          <Select id="cr-kind" value={kind} onChange={(e) => setKind(e.target.value as 'edit' | 'delete')}>
            <option value="edit">{t('vehicles.requestEdit')}</option>
            <option value="delete">{t('vehicles.requestDelete')}</option>
          </Select>
        </Field>
        <Field id="cr-reason" label={t('vehicles.whatIsWrong')}>
          <Textarea id="cr-reason" rows={3} maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}

export function VehicleDetailPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const vehicleId = Number(id)
  const toast = useToast()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const q = useQuery({ queryKey: ['vehicle', vehicleId], queryFn: () => fetchVehicle(vehicleId) })
  const [tab, setTab] = useState<EntryType>('trip')
  const [editing, setEditing] = useState(false)
  const [adding, setAdding] = useState<EntryType | null>(null)
  const [changing, setChanging] = useState<Entry | null>(null)
  const [removing, setRemoving] = useState<Entry | null>(null)
  const [correcting, setCorrecting] = useState<Entry | null>(null)
  const [retiring, setRetiring] = useState(false)

  const store = (v: VehicleDetail) => {
    queryClient.setQueryData(['vehicle', v.id], v)
    void queryClient.invalidateQueries({ queryKey: ['vehicles'] })
  }

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const v = q.data
  const entries: Entry[] = tab === 'trip' ? v.trips : tab === 'fuel' ? v.fuel : v.services
  const maxKm = Math.max(1, ...v.trend.map((m) => m.km))

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <Link to="/vehicles" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.vehicles')}
      </Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{v.vehicle_number}</h1>
          <p className="text-sm text-slate-500">
            {[v.vehicle_type, v.model, v.department?.name, t('vehicles.drivenBy', { name: v.driver?.name ?? t('vehicles.noDriver') })].filter(Boolean).join(' · ')}
          </p>
          <p className="mt-1 flex flex-wrap items-center gap-3 text-sm">
            <span className="tabular-nums">{t('vehicles.odometerNow', { km: v.current_odometer.toLocaleString('en-IN') })}</span>
            <ServiceDue vehicle={v} />
            {!v.is_active ? <RenewalStatusBadge status="inactive" /> : null}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Link to={`/vehicles/${v.id}/report`} className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
            <FileBarChart aria-hidden className="size-4" />
            {t('vehicles.report')}
          </Link>
          {v.can_manage ? (
            <>
              <Button variant="secondary" onClick={() => setRetiring(true)}>
                {v.is_active ? t('vehicles.retire') : t('vehicles.reactivate')}
              </Button>
              <Button variant="secondary" onClick={() => setEditing(true)}>
                <Pencil aria-hidden className="size-4" />
                {t('common.edit')}
              </Button>
            </>
          ) : null}
        </div>
      </div>

      <section aria-labelledby="vh-docs" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="vh-docs" className="mb-3 font-semibold">
          {t('vehicles.documents')}
        </h2>
        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {v.documents.map((d) => (
            <li key={d.field} className="flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-3 py-2 text-sm dark:border-slate-800">
              <span>
                {d.renewal_id && can('renewals.view') ? (
                  <Link to={`/renewals/${d.renewal_id}`} className="font-medium text-brand-700 hover:underline">
                    {t(`vehicles.doc.${d.field}`)}
                  </Link>
                ) : (
                  <span className="font-medium">{t(`vehicles.doc.${d.field}`)}</span>
                )}
                <span className="block text-xs text-slate-500">{d.expiry_date ? formatDate(d.expiry_date) : t('vehicles.notRecorded')}</span>
              </span>
              {d.status ? <RenewalStatusBadge status={d.status} days={d.days_left ?? undefined} /> : null}
            </li>
          ))}
        </ul>
      </section>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
        {[
          [t('vehicles.totalKm'), v.stats.total_km.toLocaleString('en-IN')],
          [t('vehicles.totalLiters'), v.stats.total_liters.toLocaleString('en-IN')],
          [t('vehicles.mileage'), v.stats.km_per_liter === null ? '—' : t('vehicles.kmpl', { value: v.stats.km_per_liter })],
          [t('vehicles.fuelCost'), formatMoney(v.stats.fuel_cost)],
          [t('vehicles.serviceCost'), formatMoney(v.stats.service_cost)],
        ].map(([label, value]) => (
          <div key={label} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
            <p className="text-lg font-semibold tabular-nums">{value}</p>
            <p className="text-xs text-slate-500">{label}</p>
          </div>
        ))}
      </div>

      <section aria-labelledby="vh-trend" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="vh-trend" className="mb-3 font-semibold">
          {t('vehicles.trend')}
        </h2>
        <ul className="space-y-1.5">
          {v.trend.map((m) => (
            <li key={m.month} className="grid grid-cols-[4.5rem_1fr_auto] items-center gap-3 text-sm">
              <span className="text-slate-500">{m.month}</span>
              <span className="h-2 rounded-full bg-slate-100 dark:bg-slate-800">
                <span className="block h-2 rounded-full bg-brand-600" style={{ width: `${(m.km / maxKm) * 100}%` }} />
              </span>
              <span className="tabular-nums text-slate-600 dark:text-slate-400">
                {t('vehicles.km', { km: m.km })} · {t('vehicles.litersShort', { liters: m.liters })}
                {m.liters > 0 ? ` · ${t('vehicles.kmpl', { value: Math.round((m.km / m.liters) * 100) / 100 })}` : ''}
              </span>
            </li>
          ))}
        </ul>
      </section>

      <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-3 dark:border-slate-800">
          <div role="tablist" className="inline-flex flex-wrap rounded-lg border border-slate-200 p-1 dark:border-slate-800">
            {TABS.map((k) => (
              <button
                key={k}
                role="tab"
                aria-selected={tab === k}
                onClick={() => setTab(k)}
                className={cn('min-h-9 rounded-md px-4 text-sm font-medium', tab === k ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}
              >
                {t(`vehicles.tabs.${k}`)}
              </button>
            ))}
          </div>
          {v.can_log && v.is_active ? (
            <Button onClick={() => setAdding(tab)}>
              <Plus aria-hidden className="size-4" />
              {t(`vehicles.add.${tab}`)}
            </Button>
          ) : null}
        </div>
        {entries.length === 0 ? <EmptyState message={t('vehicles.noEntries')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {entries.map((e) => {
            const d = describe(e, t)
            const pending = v.pending_corrections.includes(`${e.type}:${e.id}`)
            return (
              <li key={e.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                  <p className="font-medium">{d.title}</p>
                  <p className="text-sm text-slate-500">{[formatDate(e.date), d.sub, e.created_by].filter(Boolean).join(' · ')}</p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-3 text-sm">
                  {d.amount ? <span className="tabular-nums">{d.amount}</span> : null}
                  {pending ? <span className="rounded-md bg-amber-50 px-2 py-0.5 text-xs text-amber-800">{t('vehicles.correctionWaiting')}</span> : null}
                  {v.can_manage ? (
                    <>
                      <button type="button" className="font-medium text-brand-700 hover:underline" onClick={() => setChanging(e)}>
                        {t('common.edit')}
                      </button>
                      <button type="button" className="font-medium text-red-700 hover:underline" onClick={() => setRemoving(e)}>
                        {t('common.delete')}
                      </button>
                    </>
                  ) : v.can_log && !pending ? (
                    <button type="button" className="font-medium text-brand-700 hover:underline" onClick={() => setCorrecting(e)}>
                      {t('vehicles.requestCorrection')}
                    </button>
                  ) : null}
                </div>
              </li>
            )
          })}
        </ul>
      </section>

      {editing ? <VehicleDialog vehicle={v} onClose={() => setEditing(false)} /> : null}
      {adding ? (
        <EntryDialog
          type={adding}
          title={t(`vehicles.add.${adding}`)}
          initial={blankEntry(adding, v.current_odometer)}
          onSubmit={async (input) => {
            store(await addEntry(v.id, adding, input))
            toast.success(t('form.saved'))
          }}
          onClose={() => setAdding(null)}
        />
      ) : null}
      {changing ? (
        <EntryDialog
          type={changing.type}
          title={t('vehicles.editEntry')}
          initial={entryValues(changing.type, changing as unknown as Record<string, unknown>)}
          onSubmit={async (input) => {
            store(await updateEntry(v.id, changing.type, changing.id, input))
            toast.success(t('form.saved'))
          }}
          onClose={() => setChanging(null)}
        />
      ) : null}
      {correcting ? <CorrectionDialog vehicle={v} entry={correcting} onClose={() => setCorrecting(null)} /> : null}
      <ConfirmDialog
        open={removing !== null}
        onOpenChange={(o) => !o && setRemoving(null)}
        title={t('vehicles.deleteEntry')}
        body={t('vehicles.deleteEntryBody')}
        confirmLabel={t('common.delete')}
        danger
        onConfirm={async () => {
          if (!removing) return
          store(await deleteEntry(v.id, removing.type, removing.id))
          setRemoving(null)
        }}
      />
      <ConfirmDialog
        open={retiring}
        onOpenChange={setRetiring}
        title={v.is_active ? t('vehicles.retire') : t('vehicles.reactivate')}
        body={v.is_active ? t('vehicles.retireBody') : t('vehicles.reactivateBody')}
        confirmLabel={v.is_active ? t('vehicles.retire') : t('vehicles.reactivate')}
        danger={v.is_active}
        onConfirm={async () => {
          store(await setVehicleActive(v.id, !v.is_active))
          void queryClient.invalidateQueries({ queryKey: ['renewals'] })
          setRetiring(false)
        }}
      />
    </div>
  )
}
