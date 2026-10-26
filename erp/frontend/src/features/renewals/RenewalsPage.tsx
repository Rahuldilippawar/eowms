import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BarChart3, CalendarDays, Download, Plus, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
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
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatDate, formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { fetchRenewals, REGULATORY_BODIES, renewalsCsvUrl, saveRenewal, type Renewal, type RenewalDetail, type RenewalStatus } from './api'

const FIELDS = ['department_id', 'item_type', 'regulatory_body', 'item_name', 'reference_no', 'issued_date', 'expiry_date', 'renewal_cost', 'monthly_amount', 'recurring_start_month', 'vendor_name', 'vendor_contact', 'responsible_user_id'] as const
const tone: Record<RenewalStatus, string> = {
  expired: 'bg-red-50 text-red-700 ring-red-600/20',
  due_soon: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  inactive: 'bg-slate-100 text-slate-500 ring-slate-500/20',
}

export function RenewalStatusBadge({ status, days }: { status: RenewalStatus; days?: number }) {
  const { t } = useTranslation()
  const label =
    status === 'inactive' || days === undefined
      ? t(`renewals.status.${status}`)
      : days < 0
        ? t('renewals.expiredAgo', { count: -days })
        : days === 0
          ? t('renewals.expiresToday')
          : t('renewals.daysLeft', { count: days })
  return <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tone[status])}>{label}</span>
}

export function RenewalDialog({ item, onClose }: { item: RenewalDetail | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const departments = useQuery({ queryKey: ['department-options', user?.current_organization?.id], queryFn: fetchDepartmentOptions })
  const [v, setV] = useState({
    department_id: item?.department?.id.toString() ?? '',
    item_type: item?.item_type ?? '',
    regulatory_body: item?.regulatory_body ?? '',
    item_name: item?.item_name ?? '',
    reference_no: item?.reference_no ?? '',
    issued_date: item?.issued_date ?? '',
    expiry_date: item?.expiry_date ?? '',
    renewal_cost: item?.renewal_cost?.toString() ?? '',
    is_recurring_monthly: item?.is_recurring_monthly ?? false,
    monthly_amount: item?.monthly_amount?.toString() ?? '',
    recurring_start_month: item?.recurring_start_month ?? '',
    vendor_name: item?.vendor_name ?? '',
    vendor_contact: item?.vendor_contact ?? '',
  })
  const [person, setPerson] = useState<Recipient | null>(item?.responsible ? { user_id: item.responsible.id, name: item.responsible.name, designation: null, department: null } : null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })
  const n = (s: string) => (s.trim() === '' ? null : s.trim())

  const save = useMutation({
    mutationFn: () =>
      saveRenewal(item?.id ?? null, {
        department_id: Number(v.department_id),
        item_type: v.item_type.trim(),
        regulatory_body: v.regulatory_body || null,
        item_name: v.item_name.trim(),
        reference_no: n(v.reference_no),
        issued_date: v.issued_date || null,
        expiry_date: v.expiry_date,
        renewal_cost: v.renewal_cost === '' ? null : Number(v.renewal_cost),
        is_recurring_monthly: v.is_recurring_monthly,
        monthly_amount: v.is_recurring_monthly && v.monthly_amount ? Number(v.monthly_amount) : null,
        recurring_start_month: v.is_recurring_monthly ? v.recurring_start_month || null : null,
        vendor_name: n(v.vendor_name),
        vendor_contact: n(v.vendor_contact),
        responsible_user_id: person?.user_id ?? null,
      }),
    onSuccess: (saved) => {
      queryClient.setQueryData(['renewal', saved.id], saved)
      void queryClient.invalidateQueries({ queryKey: ['renewals'] })
      toast.success(t('form.saved'))
      onClose()
      if (!item) navigate(`/renewals/${saved.id}`)
    },
    onError: (e) => {
      const r = splitServerErrors(e, FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  const submit = () => {
    const local: Record<string, string> = {}
    for (const k of ['department_id', 'item_type', 'item_name', 'expiry_date'] as const) if (!String(v[k]).trim()) local[k] = t('form.required')
    if (v.is_recurring_monthly && !(Number(v.monthly_amount) > 0)) local.monthly_amount = t('letters.amountRule')
    if (v.is_recurring_monthly && !v.recurring_start_month) local.recurring_start_month = t('form.required')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={item ? t('renewals.edit') : t('renewals.new')}
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
          <Field id="rn-name" label={t('renewals.itemName')} error={errors.item_name}>
            <Input id="rn-name" value={v.item_name} maxLength={255} onChange={set('item_name')} />
          </Field>
        </div>
        <Field id="rn-type" label={t('renewals.itemType')} error={errors.item_type}>
          <Input id="rn-type" value={v.item_type} maxLength={100} placeholder={t('renewals.itemTypeHint')} onChange={set('item_type')} />
        </Field>
        <Field id="rn-dept" label={t('people.department')} error={errors.department_id}>
          <Select id="rn-dept" value={v.department_id} onChange={set('department_id')}>
            <option value="">—</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="rn-body" label={t('renewals.regulatoryBody')} error={errors.regulatory_body}>
          <Select id="rn-body" value={v.regulatory_body} onChange={set('regulatory_body')}>
            <option value="">—</option>
            {REGULATORY_BODIES.map((b) => (
              <option key={b} value={b}>
                {b}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="rn-ref" label={t('letters.referenceNo')} error={errors.reference_no}>
          <Input id="rn-ref" value={v.reference_no} maxLength={100} onChange={set('reference_no')} />
        </Field>
        <Field id="rn-issued" label={t('renewals.issuedOn')} error={errors.issued_date}>
          <Input id="rn-issued" type="date" value={v.issued_date} onChange={set('issued_date')} />
        </Field>
        <Field id="rn-expiry" label={t('renewals.expiresOn')} error={errors.expiry_date}>
          <Input id="rn-expiry" type="date" value={v.expiry_date} disabled={!!item} onChange={set('expiry_date')} />
        </Field>
        <Field id="rn-cost" label={t('renewals.cost')} error={errors.renewal_cost}>
          <Input id="rn-cost" type="number" inputMode="decimal" min={0} step="0.01" value={v.renewal_cost} onChange={set('renewal_cost')} />
        </Field>
        <Field id="rn-vendor" label={t('finance.vendor')} error={errors.vendor_name}>
          <Input id="rn-vendor" value={v.vendor_name} maxLength={255} onChange={set('vendor_name')} />
        </Field>
        <label className="flex items-center gap-2 text-sm sm:col-span-2">
          <input type="checkbox" checked={v.is_recurring_monthly} onChange={(e) => setV({ ...v, is_recurring_monthly: e.target.checked })} />
          {t('renewals.monthly')}
        </label>
        {v.is_recurring_monthly ? (
          <>
            <Field id="rn-monthly" label={t('renewals.monthlyAmount')} error={errors.monthly_amount}>
              <Input id="rn-monthly" type="number" inputMode="decimal" min={0} step="0.01" value={v.monthly_amount} onChange={set('monthly_amount')} />
            </Field>
            <Field id="rn-start" label={t('renewals.startMonth')} error={errors.recurring_start_month}>
              <Input id="rn-start" type="month" value={v.recurring_start_month} onChange={set('recurring_start_month')} />
            </Field>
          </>
        ) : null}
        <div className="sm:col-span-2">
          <RecipientPicker label={t('renewals.responsible')} value={person} onChange={setPerson} error={errors.responsible_user_id} />
        </div>
        {item ? <p className="text-xs text-slate-500 sm:col-span-2">{t('renewals.expiryHint')}</p> : null}
      </div>
    </Modal>
  )
}

export function RenewalsPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [status, setStatus] = useState<RenewalStatus | ''>('')
  const [body, setBody] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const debounced = useDebouncedValue(search.trim())
  const params = { status, regulatory_body: body, search: debounced, page }
  const q = useQuery({ queryKey: ['renewals', user?.current_organization?.id, params], queryFn: () => fetchRenewals(params), placeholderData: keepPreviousData })
  const meta = q.data?.meta
  const pick = (s: RenewalStatus | '') => {
    setStatus(s)
    setPage(1)
  }

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.renewals')}</h1>
        <div className="flex flex-wrap items-center gap-4">
          <Link to="/renewals/calendar" className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
            <CalendarDays aria-hidden className="size-4" />
            {t('renewals.calendar')}
          </Link>
          <Link to="/renewals/costs" className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
            <BarChart3 aria-hidden className="size-4" />
            {t('renewals.costs')}
          </Link>
          {can('renewals.export') ? (
            <a href={renewalsCsvUrl} className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
              <Download aria-hidden className="size-4" />
              {t('attendance.downloadCsv')}
            </a>
          ) : null}
          {meta?.can_manage ? (
            <>
              <Link to="/renewals/import" className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
                <Upload aria-hidden className="size-4" />
                {t('renewals.importTitle')}
              </Link>
              <Button onClick={() => setCreating(true)}>
                <Plus aria-hidden className="size-4" />
                {t('renewals.new')}
              </Button>
            </>
          ) : null}
        </div>
      </div>

      {meta ? (
        <div className="grid grid-cols-3 gap-3">
          {(['expired', 'due_soon', 'active'] as const).map((s) => (
            <button
              key={s}
              type="button"
              aria-pressed={status === s}
              onClick={() => pick(status === s ? '' : s)}
              className={cn('rounded-xl border p-3 text-left', status === s ? 'border-brand-600 ring-2 ring-brand-600/30' : 'border-slate-200 dark:border-slate-800', 'bg-white dark:bg-slate-900')}
            >
              <p className="text-2xl font-semibold tabular-nums">{meta.summary[s]}</p>
              <p className="text-sm text-slate-600 dark:text-slate-400">{t(`renewals.status.${s}`)}</p>
            </button>
          ))}
        </div>
      ) : null}

      <div className="flex flex-wrap items-end gap-3">
        <Field id="rn-f-status" label={t('attendance.statusLabel')}>
          <Select id="rn-f-status" value={status} onChange={(e) => pick(e.target.value as RenewalStatus | '')}>
            <option value="">{t('renewals.allActive')}</option>
            {(['expired', 'due_soon', 'active', 'inactive'] as const).map((s) => (
              <option key={s} value={s}>
                {t(`renewals.status.${s}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="rn-f-body" label={t('renewals.regulatoryBody')}>
          <Select
            id="rn-f-body"
            value={body}
            onChange={(e) => {
              setBody(e.target.value)
              setPage(1)
            }}
          >
            <option value="">{t('dashboard.all')}</option>
            {REGULATORY_BODIES.map((b) => (
              <option key={b} value={b}>
                {b}
              </option>
            ))}
          </Select>
        </Field>
        <div className="min-w-48 flex-1">
          <Field id="rn-f-search" label={t('common.search')}>
            <Input
              id="rn-f-search"
              type="search"
              value={search}
              placeholder={t('renewals.searchHint')}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
            />
          </Field>
        </div>
      </div>

      <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('renewals.none')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(q.data?.data ?? []).map((r: Renewal) => (
            <li key={r.id}>
              <Link to={`/renewals/${r.id}`} className="flex flex-col gap-1 px-4 py-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-slate-800">
                <div className="min-w-0">
                  <p className="truncate font-medium">{r.item_name}</p>
                  <p className="text-sm text-slate-500">{[r.item_type, r.regulatory_body, r.department?.name, r.responsible?.name].filter(Boolean).join(' · ')}</p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-3 text-sm">
                  {r.renewal_cost !== null ? <span>{formatMoney(r.renewal_cost)}</span> : null}
                  <span className="text-slate-600 dark:text-slate-400">{formatDate(r.expiry_date)}</span>
                  <RenewalStatusBadge status={r.status} days={r.days_left} />
                </div>
              </Link>
            </li>
          ))}
        </ul>
        {q.data ? (
          <div className="px-4 pb-3">
            <Pagination meta={q.data.meta} onPage={setPage} />
          </div>
        ) : null}
      </section>
      {creating ? <RenewalDialog item={null} onClose={() => setCreating(false)} /> : null}
    </div>
  )
}
