import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, PieChart, Plus, Settings } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import type { PaymentType } from '@/features/finance/api'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatDate, formatMoney, todayYmd } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import {
  deleteLog,
  fetchBuildings,
  fetchLocations,
  fetchLog,
  fetchLogs,
  logsCsvUrl,
  payLog,
  saveLocation,
  saveLog,
  WORK_TYPES,
  type LocationRow,
  type LocationsMeta,
  type LogEntry,
  type WorkType,
} from './api'

const LOG_FIELDS = ['location_id', 'department_id', 'item_description', 'work_type', 'work_date', 'cost', 'remarks'] as const
const workTone: Record<WorkType, string> = {
  installed: 'bg-sky-50 text-sky-700',
  repaired: 'bg-amber-50 text-amber-800',
  replaced: 'bg-violet-50 text-violet-700',
  inspected: 'bg-slate-100 text-slate-600',
}

export function WorkTypeBadge({ type }: { type: WorkType }) {
  const { t } = useTranslation()
  return <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium', workTone[type])}>{t(`maintenance.work.${type}`)}</span>
}

const locationLabel = (l: { name?: string; location_code?: string; building?: string | null }) => [l.building, l.name, l.location_code ? `(${l.location_code})` : null].filter(Boolean).join(' · ')

export function LogDialog({ log, locations, meta, fixedLocation, onClose }: { log: LogEntry | null; locations: LocationRow[]; meta: LocationsMeta; fixedLocation?: number; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [v, setV] = useState({
    location_id: String(log?.location.id ?? fixedLocation ?? ''),
    department_id: String(log?.department?.id ?? (meta.log_departments.length === 1 ? meta.log_departments[0].id : '')),
    item_description: log?.item_description ?? '',
    work_type: (log?.work_type ?? 'repaired') as WorkType,
    work_date: log?.work_date ?? todayYmd(),
    cost: log?.cost?.toString() ?? '',
    remarks: log?.remarks ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })
  const departments = log?.department && !meta.log_departments.some((d) => d.id === log.department?.id) ? [...meta.log_departments, log.department] : meta.log_departments
  const save = useMutation({
    mutationFn: () =>
      saveLog(log?.id ?? null, {
        location_id: Number(v.location_id),
        department_id: Number(v.department_id),
        item_description: v.item_description.trim(),
        work_type: v.work_type,
        work_date: v.work_date,
        cost: v.cost === '' ? null : Number(v.cost),
        remarks: v.remarks.trim() || null,
      }),
    onSuccess: (saved) => {
      queryClient.setQueryData(['maintenance-log', saved.id], saved)
      void queryClient.invalidateQueries({ queryKey: ['maintenance-logs'] })
      void queryClient.invalidateQueries({ queryKey: ['maintenance-locations'] })
      void queryClient.invalidateQueries({ queryKey: ['maintenance-location'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => {
      const r = splitServerErrors(e, LOG_FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const submit = () => {
    const local: Record<string, string> = {}
    for (const k of ['location_id', 'department_id', 'item_description', 'work_date'] as const) if (!v[k].trim()) local[k] = t('form.required')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={log ? t('maintenance.editEntry') : t('maintenance.newEntry')}
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
        <div className="sm:col-span-2">
          <Field id="ml-location" label={t('maintenance.location')} error={errors.location_id}>
            <Select id="ml-location" value={v.location_id} disabled={fixedLocation !== undefined} onChange={set('location_id')}>
              <option value="">—</option>
              {locations.map((l) => (
                <option key={l.id} value={l.id}>
                  {locationLabel({ building: l.building?.name, name: l.name, location_code: l.location_code })}
                </option>
              ))}
            </Select>
          </Field>
        </div>
        <Field id="ml-dept" label={t('people.department')} error={errors.department_id}>
          <Select id="ml-dept" value={v.department_id} onChange={set('department_id')}>
            <option value="">—</option>
            {departments.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="ml-type" label={t('maintenance.workType')} error={errors.work_type}>
          <Select id="ml-type" value={v.work_type} onChange={(e) => setV({ ...v, work_type: e.target.value as WorkType })}>
            {WORK_TYPES.map((w) => (
              <option key={w} value={w}>
                {t(`maintenance.work.${w}`)}
              </option>
            ))}
          </Select>
        </Field>
        <div className="sm:col-span-2">
          <Field id="ml-item" label={t('maintenance.item')} error={errors.item_description}>
            <Input id="ml-item" value={v.item_description} maxLength={255} placeholder={t('maintenance.itemHint')} onChange={set('item_description')} />
          </Field>
        </div>
        <Field id="ml-date" label={t('attendance.date')} error={errors.work_date}>
          <Input id="ml-date" type="date" max={todayYmd()} value={v.work_date} onChange={set('work_date')} />
        </Field>
        <Field id="ml-cost" label={t('vehicles.costRs')} error={errors.cost}>
          <Input id="ml-cost" type="number" inputMode="decimal" min={0} step="0.01" value={v.cost} onChange={set('cost')} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="ml-remarks" label={t('maintenance.remarks')} error={errors.remarks}>
            <Textarea id="ml-remarks" rows={2} maxLength={2000} value={v.remarks} onChange={set('remarks')} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}

export function LogDetailDialog({ id, onEdit, onClose }: { id: number; onEdit: (l: LogEntry) => void; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const q = useQuery({ queryKey: ['maintenance-log', id], queryFn: () => fetchLog(id) })
  const [amount, setAmount] = useState('')
  const [date, setDate] = useState(todayYmd())
  const [type, setType] = useState<PaymentType>('partial')
  const [mode, setMode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['maintenance-logs'] })
    void queryClient.invalidateQueries({ queryKey: ['maintenance-location'] })
    void queryClient.invalidateQueries({ queryKey: ['maintenance-locations'] })
  }
  const pay = useMutation({
    mutationFn: () => payLog(id, { amount: Number(amount), payment_date: date, payment_type: type, payment_mode: mode.trim() || null, remarks: null }),
    onSuccess: (l) => {
      queryClient.setQueryData(['maintenance-log', id], l)
      refresh()
      setAmount('')
      setError(null)
      toast.success(t('finance.paymentSaved'))
    },
    onError: (e) => setError(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const remove = useMutation({
    mutationFn: () => deleteLog(id),
    onSuccess: () => {
      refresh()
      toast.success(t('finance.deleted'))
      onClose()
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const l = q.data

  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={l ? l.item_description : t('common.loading')} description={l ? `${locationLabel(l.location)} · ${formatDate(l.work_date)}` : undefined}>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {l ? (
        <div className="space-y-4 text-sm">
          <dl className="grid grid-cols-2 gap-2">
            <dt className="text-slate-500">{t('maintenance.workType')}</dt>
            <dd>
              <WorkTypeBadge type={l.work_type} />
            </dd>
            <dt className="text-slate-500">{t('people.department')}</dt>
            <dd>{l.department?.name}</dd>
            <dt className="text-slate-500">{t('maintenance.doneBy')}</dt>
            <dd>{l.performed_by ?? '—'}</dd>
            {l.cost ? (
              <>
                <dt className="text-slate-500">{t('finance.total')}</dt>
                <dd className="font-semibold">{formatMoney(l.cost)}</dd>
                <dt className="text-slate-500">{t('finance.paid')}</dt>
                <dd>{formatMoney(l.paid_amount)}</dd>
                <dt className="text-slate-500">{t('finance.due')}</dt>
                <dd className={l.due_amount > 0 ? 'font-semibold text-red-700' : ''}>{formatMoney(l.due_amount)}</dd>
              </>
            ) : null}
          </dl>
          {l.remarks ? <p className="whitespace-pre-line text-slate-600 dark:text-slate-300">{l.remarks}</p> : null}
          {l.payments.length ? (
            <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
              {l.payments.map((p) => (
                <li key={p.id} className="flex justify-between gap-2 px-3 py-2">
                  <span>
                    {formatDate(p.payment_date)} · {t(`finance.paymentType.${p.payment_type}`)}
                    {p.payment_mode ? ` · ${p.payment_mode}` : ''}
                  </span>
                  <span className="font-medium">{formatMoney(p.amount)}</span>
                </li>
              ))}
            </ul>
          ) : null}
          {l.can_manage && l.due_amount > 0 ? (
            <form
              className="grid gap-2 sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault()
                if (!(Number(amount) > 0)) return setError(t('letters.amountRule'))
                pay.mutate()
              }}
            >
              <Field id="mp-amount" label={t('finance.payAmount')} error={error ?? undefined}>
                <Input id="mp-amount" type="number" inputMode="decimal" min={0} step="0.01" value={amount} placeholder={String(l.due_amount)} onChange={(e) => setAmount(e.target.value)} />
              </Field>
              <Field id="mp-date" label={t('attendance.date')}>
                <Input id="mp-date" type="date" max={todayYmd()} value={date} onChange={(e) => setDate(e.target.value)} />
              </Field>
              <Field id="mp-type" label={t('finance.paymentTypeLabel')}>
                <Select id="mp-type" value={type} onChange={(e) => setType(e.target.value as PaymentType)}>
                  {(['advance', 'partial', 'final'] as const).map((k) => (
                    <option key={k} value={k}>
                      {t(`finance.paymentType.${k}`)}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field id="mp-mode" label={t('finance.paymentMode')}>
                <Input id="mp-mode" value={mode} maxLength={50} placeholder={t('finance.paymentModeHint')} onChange={(e) => setMode(e.target.value)} />
              </Field>
              <Button type="submit" className="sm:col-span-2" disabled={pay.isPending}>
                {pay.isPending ? <Spinner label={t('common.saving')} /> : t('finance.recordPayment')}
              </Button>
            </form>
          ) : null}
          {l.can_manage ? (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => onEdit(l)}>
                {t('common.edit')}
              </Button>
              {l.payments.length === 0 ? (
                <Button variant="secondary" className="text-red-700" disabled={remove.isPending} onClick={() => remove.mutate()}>
                  {t('common.delete')}
                </Button>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}
    </Modal>
  )
}

export function LocationDialog({ location, onClose }: { location: LocationRow | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const buildings = useQuery({ queryKey: ['maintenance-buildings'], queryFn: fetchBuildings })
  const [v, setV] = useState({
    name: location?.name ?? '',
    location_code: location?.location_code ?? '',
    building_id: location?.building?.id.toString() ?? '',
    floor: location?.floor.toString() ?? '0',
    capacity: location?.capacity?.toString() ?? '',
    is_residential: location?.is_residential ?? false,
    is_active: location?.is_active ?? true,
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const set = (k: 'name' | 'location_code' | 'building_id' | 'floor' | 'capacity') => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () =>
      saveLocation(location?.id ?? null, {
        name: v.name.trim(),
        location_code: v.location_code.trim(),
        building_id: v.building_id ? Number(v.building_id) : null,
        floor: v.floor === '' ? 0 : Number(v.floor),
        capacity: v.capacity === '' ? null : Number(v.capacity),
        is_residential: v.is_residential,
        is_active: v.is_active,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['maintenance-locations'] })
      void queryClient.invalidateQueries({ queryKey: ['maintenance-location'] })
      void queryClient.invalidateQueries({ queryKey: ['maintenance-buildings'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['name', 'location_code', 'building_id', 'floor', 'capacity'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const submit = () => {
    const local: Record<string, string> = {}
    for (const k of ['name', 'location_code'] as const) if (!v[k].trim()) local[k] = t('form.required')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={location ? t('maintenance.editLocation') : t('maintenance.newLocation')}
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
        <Field id="lc-name" label={t('maintenance.locationName')} error={errors.name}>
          <Input id="lc-name" value={v.name} maxLength={255} placeholder={t('maintenance.locationNameHint')} onChange={set('name')} />
        </Field>
        <Field id="lc-code" label={t('maintenance.code')} error={errors.location_code}>
          <Input id="lc-code" value={v.location_code} maxLength={50} onChange={set('location_code')} />
        </Field>
        <Field id="lc-building" label={t('maintenance.building')} error={errors.building_id}>
          <Select id="lc-building" value={v.building_id} onChange={set('building_id')}>
            <option value="">—</option>
            {(buildings.data ?? []).map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="lc-floor" label={t('maintenance.floor')} error={errors.floor}>
          <Input id="lc-floor" type="number" inputMode="numeric" value={v.floor} onChange={set('floor')} />
        </Field>
        <Field id="lc-capacity" label={t('maintenance.capacity')} error={errors.capacity}>
          <Input id="lc-capacity" type="number" inputMode="numeric" min={0} value={v.capacity} onChange={set('capacity')} />
        </Field>
        <div className="flex flex-col justify-end gap-2 text-sm">
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={v.is_residential} onChange={(e) => setV({ ...v, is_residential: e.target.checked })} />
            {t('maintenance.residential')}
          </label>
          {location ? (
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={v.is_active} onChange={(e) => setV({ ...v, is_active: e.target.checked })} />
              {t('maintenance.inUse')}
            </label>
          ) : null}
        </div>
      </div>
    </Modal>
  )
}

export function MaintenancePage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [tab, setTab] = useState<'log' | 'locations'>('log')
  const [f, setF] = useState({ location_id: '', department_id: '', work_type: '', from: '', to: '', search: '' })
  const [page, setPage] = useState(1)
  const [locSearch, setLocSearch] = useState('')
  const [logging, setLogging] = useState(false)
  const [editing, setEditing] = useState<LogEntry | null>(null)
  const [viewing, setViewing] = useState<number | null>(null)
  const [locating, setLocating] = useState<LocationRow | 'new' | null>(null)
  const debounced = useDebouncedValue(f.search.trim())
  const debouncedLoc = useDebouncedValue(locSearch.trim())
  const orgId = user?.current_organization?.id
  const locations = useQuery({ queryKey: ['maintenance-locations', orgId, debouncedLoc], queryFn: () => fetchLocations({ search: debouncedLoc, include_inactive: true }), placeholderData: keepPreviousData })
  const allLocations = useQuery({ queryKey: ['maintenance-locations', orgId, 'all'], queryFn: () => fetchLocations({}) })
  const params = { ...f, search: debounced, page }
  const logs = useQuery({ queryKey: ['maintenance-logs', orgId, params], queryFn: () => fetchLogs(params), placeholderData: keepPreviousData, enabled: tab === 'log' })
  const meta = allLocations.data?.meta
  const setFilter = (k: keyof typeof f) => (e: { target: { value: string } }) => {
    setF({ ...f, [k]: e.target.value })
    setPage(1)
  }

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.maintenance')}</h1>
        <div className="flex flex-wrap items-center gap-4">
          <Link to="/maintenance/report" className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
            <PieChart aria-hidden className="size-4" />
            {t('nav.maintenanceReport')}
          </Link>
          {meta?.can_export ? (
            <a href={logsCsvUrl} className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
              <Download aria-hidden className="size-4" />
              {t('attendance.downloadCsv')}
            </a>
          ) : null}
          {meta?.can_manage ? (
            <Link to="/maintenance/settings" className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
              <Settings aria-hidden className="size-4" />
              {t('maintenance.settings')}
            </Link>
          ) : null}
          {tab === 'log' && meta?.can_log && meta.log_departments.length > 0 ? (
            <Button onClick={() => setLogging(true)}>
              <Plus aria-hidden className="size-4" />
              {t('maintenance.newEntry')}
            </Button>
          ) : null}
          {tab === 'locations' && meta?.can_manage ? (
            <Button onClick={() => setLocating('new')}>
              <Plus aria-hidden className="size-4" />
              {t('maintenance.newLocation')}
            </Button>
          ) : null}
        </div>
      </div>
      {meta?.can_log && meta.log_departments.length === 0 ? <p className="text-sm text-slate-500">{t('maintenance.noRegister')}</p> : null}

      <div role="tablist" className="inline-flex flex-wrap rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
        {(['log', 'locations'] as const).map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={tab === k}
            onClick={() => setTab(k)}
            className={cn('min-h-9 rounded-md px-4 text-sm font-medium', tab === k ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}
          >
            {t(`maintenance.tabs.${k}`)}
          </button>
        ))}
      </div>

      {tab === 'log' ? (
        <>
          <div className="flex flex-wrap items-end gap-3">
            <Field id="mf-location" label={t('maintenance.location')}>
              <Select id="mf-location" value={f.location_id} onChange={setFilter('location_id')}>
                <option value="">{t('dashboard.all')}</option>
                {(allLocations.data?.data ?? []).map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.name} ({l.location_code})
                  </option>
                ))}
              </Select>
            </Field>
            <Field id="mf-type" label={t('maintenance.workType')}>
              <Select id="mf-type" value={f.work_type} onChange={setFilter('work_type')}>
                <option value="">{t('dashboard.all')}</option>
                {WORK_TYPES.map((w) => (
                  <option key={w} value={w}>
                    {t(`maintenance.work.${w}`)}
                  </option>
                ))}
              </Select>
            </Field>
            <Field id="mf-from" label={t('vehicles.fromDate')}>
              <Input id="mf-from" type="date" value={f.from} onChange={setFilter('from')} />
            </Field>
            <Field id="mf-to" label={t('vehicles.toDate')}>
              <Input id="mf-to" type="date" value={f.to} onChange={setFilter('to')} />
            </Field>
            <div className="min-w-48 flex-1">
              <Field id="mf-search" label={t('common.search')}>
                <Input id="mf-search" type="search" value={f.search} placeholder={t('maintenance.searchHint')} onChange={setFilter('search')} />
              </Field>
            </div>
          </div>
          <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            {logs.isPending ? <LoadingState /> : null}
            {logs.isError ? <ErrorState onRetry={() => void logs.refetch()} /> : null}
            {logs.data && logs.data.data.length === 0 ? <EmptyState message={t('maintenance.noEntries')} /> : null}
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {(logs.data?.data ?? []).map((l) => (
                <li key={l.id}>
                  <button type="button" onClick={() => setViewing(l.id)} className="flex w-full flex-col gap-1 px-4 py-3 text-left hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-slate-800">
                    <span className="min-w-0">
                      <span className="block truncate font-medium">{l.item_description}</span>
                      <span className="block text-sm text-slate-500">{[locationLabel(l.location), l.department?.name, l.performed_by].filter(Boolean).join(' · ')}</span>
                    </span>
                    <span className="flex shrink-0 flex-wrap items-center gap-3 text-sm">
                      <WorkTypeBadge type={l.work_type} />
                      {l.cost ? <span className="tabular-nums">{formatMoney(l.cost)}</span> : null}
                      {l.payment_status && l.payment_status !== 'paid' ? <span className="text-xs text-red-700">{t('maintenance.duePay', { amount: formatMoney(l.due_amount) })}</span> : null}
                      <span className="text-slate-600 dark:text-slate-400">{formatDate(l.work_date)}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
            {logs.data ? (
              <div className="px-4 pb-3">
                <Pagination meta={logs.data.meta} onPage={setPage} />
              </div>
            ) : null}
          </section>
        </>
      ) : (
        <>
          <Field id="lf-search" label={t('common.search')}>
            <Input id="lf-search" type="search" value={locSearch} placeholder={t('maintenance.locationSearchHint')} onChange={(e) => setLocSearch(e.target.value)} />
          </Field>
          <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            {locations.isPending ? <LoadingState /> : null}
            {locations.isError ? <ErrorState onRetry={() => void locations.refetch()} /> : null}
            {locations.data && locations.data.data.length === 0 ? <EmptyState message={t('maintenance.noLocations')} /> : null}
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {(locations.data?.data ?? []).map((l) => (
                <li key={l.id} className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                  <div className="min-w-0">
                    <Link to={`/maintenance/locations/${l.id}`} className="font-medium text-brand-700 hover:underline">
                      {l.name}
                    </Link>
                    <p className="text-sm text-slate-500">
                      {[l.location_code, l.building?.name, t('maintenance.floorN', { floor: l.floor }), l.is_residential ? t('maintenance.residential') : null, !l.is_active ? t('maintenance.notInUse') : null]
                        .filter(Boolean)
                        .join(' · ')}
                    </p>
                  </div>
                  <div className="flex shrink-0 flex-wrap items-center gap-3 text-sm text-slate-600 dark:text-slate-400">
                    <span>{t('maintenance.entries', { count: l.total_logs })}</span>
                    {l.recent_issues >= 3 ? <span className="rounded-md bg-red-50 px-2 py-0.5 text-xs text-red-700">{t('maintenance.frequent', { count: l.recent_issues })}</span> : null}
                    {l.last_work_date ? <span>{t('maintenance.lastWork', { date: formatDate(l.last_work_date) })}</span> : null}
                    {locations.data?.meta.can_manage ? (
                      <button type="button" className="font-medium text-brand-700 hover:underline" onClick={() => setLocating(l)}>
                        {t('common.edit')}
                      </button>
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
          </section>
        </>
      )}

      {(logging || editing) && meta ? (
        <LogDialog
          log={editing}
          locations={allLocations.data?.data ?? []}
          meta={meta}
          onClose={() => {
            setLogging(false)
            setEditing(null)
          }}
        />
      ) : null}
      {viewing !== null ? (
        <LogDetailDialog
          id={viewing}
          onEdit={(l) => {
            setViewing(null)
            setEditing(l)
          }}
          onClose={() => setViewing(null)}
        />
      ) : null}
      {locating ? <LocationDialog location={locating === 'new' ? null : locating} onClose={() => setLocating(null)} /> : null}
    </div>
  )
}
