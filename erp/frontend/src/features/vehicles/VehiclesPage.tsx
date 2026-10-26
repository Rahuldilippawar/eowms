import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Plus, Upload, Wrench } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { RecipientPicker } from '@/features/letters/LetterBits'
import type { Recipient } from '@/features/letters/api'
import { fetchDepartmentOptions } from '@/features/org/api'
import { RenewalStatusBadge } from '@/features/renewals/RenewalsPage'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { DOCUMENT_FIELDS, fetchVehicles, FUEL_TYPES, saveVehicle, vehiclesCsvUrl, type DocumentField, type FuelType, type Vehicle, type VehicleDetail } from './api'

const FIELDS = ['department_id', 'vehicle_number', 'vehicle_type', 'model', 'registration_date', 'chassis_no', 'engine_no', 'vehicle_class', 'passenger_capacity', 'driver_user_id', 'fuel_type', 'current_odometer'] as const

export function ServiceDue({ vehicle }: { vehicle: Vehicle }) {
  const { t } = useTranslation()
  const s = vehicle.next_service
  if (!s) return null
  const parts = [
    s.days_left === null ? null : s.days_left < 0 ? t('vehicles.serviceLate', { count: -s.days_left }) : t('vehicles.serviceIn', { count: s.days_left }),
    s.km_left === null ? null : s.km_left < 0 ? t('vehicles.kmOver', { km: -s.km_left }) : t('vehicles.kmLeft', { km: s.km_left }),
  ].filter(Boolean)
  return (
    <span className={s.due ? 'inline-flex items-center gap-1 text-amber-700' : 'inline-flex items-center gap-1 text-slate-500'}>
      <Wrench aria-hidden className="size-3.5" />
      {parts.join(' · ')}
    </span>
  )
}

export function VehicleDialog({ vehicle, onClose }: { vehicle: VehicleDetail | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const departments = useQuery({ queryKey: ['department-options', user?.current_organization?.id], queryFn: fetchDepartmentOptions })
  const [v, setV] = useState({
    department_id: vehicle?.department?.id.toString() ?? '',
    vehicle_number: vehicle?.vehicle_number ?? '',
    vehicle_type: vehicle?.vehicle_type ?? '',
    model: vehicle?.model ?? '',
    registration_date: vehicle?.registration_date ?? '',
    chassis_no: vehicle?.chassis_no ?? '',
    engine_no: vehicle?.engine_no ?? '',
    vehicle_class: vehicle?.vehicle_class ?? '',
    passenger_capacity: vehicle?.passenger_capacity?.toString() ?? '',
    fuel_type: (vehicle?.fuel_type ?? 'diesel') as FuelType,
    current_odometer: '',
  })
  const [docs, setDocs] = useState<Record<DocumentField, string>>(
    () => Object.fromEntries(DOCUMENT_FIELDS.map((f) => [f, vehicle?.documents.find((d) => d.field === f)?.expiry_date ?? ''])) as Record<DocumentField, string>,
  )
  const [driver, setDriver] = useState<Recipient | null>(vehicle?.driver ? { user_id: vehicle.driver.id, name: vehicle.driver.name, designation: null, department: null } : null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })
  const n = (s: string) => (s.trim() === '' ? null : s.trim())

  const save = useMutation({
    mutationFn: () =>
      saveVehicle(vehicle?.id ?? null, {
        department_id: Number(v.department_id),
        vehicle_number: v.vehicle_number.trim(),
        vehicle_type: n(v.vehicle_type),
        model: n(v.model),
        registration_date: v.registration_date || null,
        chassis_no: n(v.chassis_no),
        engine_no: n(v.engine_no),
        vehicle_class: n(v.vehicle_class),
        passenger_capacity: v.passenger_capacity === '' ? null : Number(v.passenger_capacity),
        driver_user_id: driver?.user_id ?? null,
        fuel_type: v.fuel_type,
        ...(vehicle ? {} : { current_odometer: v.current_odometer === '' ? 0 : Number(v.current_odometer) }),
        documents: Object.fromEntries(DOCUMENT_FIELDS.map((f) => [f, docs[f] || null])),
      }),
    onSuccess: (saved) => {
      queryClient.setQueryData(['vehicle', saved.id], saved)
      void queryClient.invalidateQueries({ queryKey: ['vehicles'] })
      void queryClient.invalidateQueries({ queryKey: ['renewals'] })
      toast.success(t('form.saved'))
      onClose()
      if (!vehicle) navigate(`/vehicles/${saved.id}`)
    },
    onError: (e) => {
      const r = splitServerErrors(e, FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  const submit = () => {
    const local: Record<string, string> = {}
    for (const k of ['department_id', 'vehicle_number'] as const) if (!v[k].trim()) local[k] = t('form.required')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={vehicle ? t('vehicles.edit') : t('vehicles.new')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={submit}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <Field id="vh-number" label={t('vehicles.number')} error={errors.vehicle_number}>
          <Input id="vh-number" value={v.vehicle_number} maxLength={50} placeholder="MH12AB1234" onChange={set('vehicle_number')} />
        </Field>
        <Field id="vh-dept" label={t('people.department')} error={errors.department_id}>
          <Select id="vh-dept" value={v.department_id} onChange={set('department_id')}>
            <option value="">—</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="vh-type" label={t('vehicles.type')} error={errors.vehicle_type}>
          <Input id="vh-type" value={v.vehicle_type} maxLength={100} placeholder={t('vehicles.typeHint')} onChange={set('vehicle_type')} />
        </Field>
        <Field id="vh-model" label={t('vehicles.model')} error={errors.model}>
          <Input id="vh-model" value={v.model} maxLength={100} onChange={set('model')} />
        </Field>
        <Field id="vh-fuel" label={t('vehicles.fuelType')} error={errors.fuel_type}>
          <Select id="vh-fuel" value={v.fuel_type} onChange={(e) => setV({ ...v, fuel_type: e.target.value as FuelType })}>
            {FUEL_TYPES.map((f) => (
              <option key={f} value={f}>
                {t(`vehicles.fuel.${f}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="vh-capacity" label={t('vehicles.capacity')} error={errors.passenger_capacity}>
          <Input id="vh-capacity" type="number" inputMode="numeric" min={0} value={v.passenger_capacity} onChange={set('passenger_capacity')} />
        </Field>
        {vehicle ? null : (
          <Field id="vh-odo" label={t('vehicles.odometer')} error={errors.current_odometer}>
            <Input id="vh-odo" type="number" inputMode="decimal" min={0} step="0.1" value={v.current_odometer} onChange={set('current_odometer')} />
          </Field>
        )}
        <Field id="vh-reg" label={t('vehicles.registrationDate')} error={errors.registration_date}>
          <Input id="vh-reg" type="date" value={v.registration_date} onChange={set('registration_date')} />
        </Field>
        <Field id="vh-class" label={t('vehicles.class')} error={errors.vehicle_class}>
          <Input id="vh-class" value={v.vehicle_class} maxLength={100} onChange={set('vehicle_class')} />
        </Field>
        <Field id="vh-chassis" label={t('vehicles.chassis')} error={errors.chassis_no}>
          <Input id="vh-chassis" value={v.chassis_no} maxLength={100} onChange={set('chassis_no')} />
        </Field>
        <Field id="vh-engine" label={t('vehicles.engine')} error={errors.engine_no}>
          <Input id="vh-engine" value={v.engine_no} maxLength={100} onChange={set('engine_no')} />
        </Field>
        <div className="sm:col-span-2">
          <RecipientPicker label={t('vehicles.driver')} value={driver} onChange={setDriver} error={errors.driver_user_id} />
        </div>
        <fieldset className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
          <legend className="mb-1 text-sm font-medium">{t('vehicles.documents')}</legend>
          {DOCUMENT_FIELDS.map((f) => (
            <Field key={f} id={`vh-doc-${f}`} label={t('vehicles.validTill', { doc: t(`vehicles.doc.${f}`) })}>
              <Input id={`vh-doc-${f}`} type="date" value={docs[f]} onChange={(e) => setDocs({ ...docs, [f]: e.target.value })} />
            </Field>
          ))}
          <p className="text-xs text-slate-500 sm:col-span-2">{t('vehicles.documentsHint')}</p>
        </fieldset>
      </div>
    </Modal>
  )
}

export function VehiclesPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('active')
  const [creating, setCreating] = useState(false)
  const debounced = useDebouncedValue(search.trim())
  const params = { search: debounced, status }
  const q = useQuery({ queryKey: ['vehicles', user?.current_organization?.id, params], queryFn: () => fetchVehicles(params), placeholderData: keepPreviousData })
  const meta = q.data?.meta

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.vehicles')}</h1>
        <div className="flex flex-wrap items-center gap-4">
          {meta?.can_export ? (
            <a href={vehiclesCsvUrl} className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
              <Download aria-hidden className="size-4" />
              {t('attendance.downloadCsv')}
            </a>
          ) : null}
          {meta?.can_create ? (
            <>
              <Link to="/vehicles/import" className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
                <Upload aria-hidden className="size-4" />
                {t('vehicles.importTitle')}
              </Link>
              <Button onClick={() => setCreating(true)}>
                <Plus aria-hidden className="size-4" />
                {t('vehicles.new')}
              </Button>
            </>
          ) : null}
        </div>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <Field id="vh-f-status" label={t('attendance.statusLabel')}>
          <Select id="vh-f-status" value={status} onChange={(e) => setStatus(e.target.value)}>
            {(['active', 'inactive', 'all'] as const).map((s) => (
              <option key={s} value={s}>
                {t(`vehicles.filter.${s}`)}
              </option>
            ))}
          </Select>
        </Field>
        <div className="min-w-48 flex-1">
          <Field id="vh-f-search" label={t('common.search')}>
            <Input id="vh-f-search" type="search" value={search} placeholder={t('vehicles.searchHint')} onChange={(e) => setSearch(e.target.value)} />
          </Field>
        </div>
      </div>

      <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('vehicles.none')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(q.data?.data ?? []).map((v) => (
            <li key={v.id}>
              <Link to={`/vehicles/${v.id}`} className="flex flex-col gap-1 px-4 py-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-slate-800">
                <div className="min-w-0">
                  <p className="truncate font-medium">{v.vehicle_number}</p>
                  <p className="text-sm text-slate-500">{[v.vehicle_type, v.model, v.department?.name, v.driver ? t('vehicles.drivenBy', { name: v.driver.name }) : null].filter(Boolean).join(' · ')}</p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-3 text-sm">
                  <span className="tabular-nums text-slate-600 dark:text-slate-400">{t('vehicles.km', { km: v.current_odometer.toLocaleString('en-IN') })}</span>
                  <ServiceDue vehicle={v} />
                  {v.next_document && v.documents_status ? (
                    <span className="inline-flex items-center gap-1">
                      <span className="text-slate-500">{t(`vehicles.doc.${v.next_document.field}`)}</span>
                      <RenewalStatusBadge status={v.documents_status} days={v.next_document.days_left} />
                    </span>
                  ) : null}
                  {!v.is_active ? <RenewalStatusBadge status="inactive" /> : null}
                </div>
              </Link>
            </li>
          ))}
        </ul>
      </section>
      {creating ? <VehicleDialog vehicle={null} onClose={() => setCreating(false)} /> : null}
    </div>
  )
}
