import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Download, Plus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
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
import { fetchDepartmentOptions } from '@/features/org/api'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatDate, formatMoney, todayYmd } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import { checkBudget, deletePurchase, fetchPurchase, fetchPurchases, fyLabel, payPurchase, purchasesCsvUrl, savePurchase, type PaymentStatus, type PaymentType, type Purchase } from './api'

const FIELDS = ['department_id', 'material_name', 'vendor_name', 'vendor_contact', 'quantity', 'unit', 'rate', 'transport_cost', 'gst_percent', 'purchase_date', 'remarks'] as const
const tone: Record<PaymentStatus, string> = {
  pending: 'bg-red-50 text-red-700 ring-red-600/20',
  partial: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  paid: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
}
const num = (s: string) => (s.trim() === '' ? 0 : Number(s))

function StatusBadge({ status }: { status: PaymentStatus }) {
  const { t } = useTranslation()
  return <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tone[status])}>{t(`finance.status.${status}`)}</span>
}

function PurchaseDialog({ purchase, onClose }: { purchase: Purchase | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const departments = useQuery({ queryKey: ['department-options', user?.current_organization?.id], queryFn: fetchDepartmentOptions })
  const [v, setV] = useState({
    department_id: purchase?.department?.id.toString() ?? '',
    material_name: purchase?.material_name ?? '',
    vendor_name: purchase?.vendor_name ?? '',
    vendor_contact: purchase?.vendor_contact ?? '',
    quantity: purchase?.quantity.toString() ?? '',
    unit: purchase?.unit ?? '',
    rate: purchase?.rate.toString() ?? '',
    transport_cost: purchase?.transport_cost ? purchase.transport_cost.toString() : '',
    gst_percent: purchase?.gst_percent ? purchase.gst_percent.toString() : '',
    purchase_date: purchase?.purchase_date ?? todayYmd(),
    remarks: purchase?.remarks ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })
  const base = Math.round(num(v.quantity) * num(v.rate) * 100) / 100
  const gst = Math.round(base * num(v.gst_percent)) / 100
  const total = Math.round((base + gst + num(v.transport_cost)) * 100) / 100
  const [budget, setBudget] = useState<{ remaining: number | null; exceeds: boolean } | null>(null)

  // Warn (do not block) when the purchase would take the department over its budget.
  useEffect(() => {
    if (!v.department_id || !v.purchase_date || total <= 0) return setBudget(null)
    const timer = setTimeout(() => {
      const extra = purchase && String(purchase.department?.id) === v.department_id ? purchase.total_amount : 0
      void checkBudget({ department_id: Number(v.department_id), date: v.purchase_date, amount: Math.max(0, total - extra) }).then(setBudget, () => setBudget(null))
    }, 400)
    return () => clearTimeout(timer)
  }, [v.department_id, v.purchase_date, total, purchase])

  const save = useMutation({
    mutationFn: () =>
      savePurchase(purchase?.id ?? null, {
        department_id: Number(v.department_id),
        material_name: v.material_name.trim(),
        vendor_name: v.vendor_name.trim(),
        vendor_contact: v.vendor_contact.trim() || null,
        quantity: num(v.quantity),
        unit: v.unit.trim() || null,
        rate: num(v.rate),
        transport_cost: num(v.transport_cost),
        gst_percent: num(v.gst_percent),
        purchase_date: v.purchase_date,
        remarks: v.remarks.trim() || null,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['finance-purchases'] })
      void queryClient.invalidateQueries({ queryKey: ['finance-purchase'] })
      void queryClient.invalidateQueries({ queryKey: ['finance-budgets'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => {
      const r = splitServerErrors(e, FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  const submit = () => {
    const local: Record<string, string> = {}
    if (!v.department_id) local.department_id = t('form.required')
    if (!v.material_name.trim()) local.material_name = t('form.required')
    if (!v.vendor_name.trim()) local.vendor_name = t('form.required')
    if (!(num(v.quantity) > 0)) local.quantity = t('letters.amountRule')
    if (!(num(v.rate) > 0)) local.rate = t('letters.amountRule')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={purchase ? t('finance.editPurchase') : t('finance.newPurchase')}
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
        <Field id="pu-dept" label={t('people.department')} error={errors.department_id}>
          <Select id="pu-dept" value={v.department_id} onChange={set('department_id')}>
            <option value="">—</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="pu-date" label={t('finance.purchaseDate')} error={errors.purchase_date}>
          <Input id="pu-date" type="date" max={todayYmd()} value={v.purchase_date} onChange={set('purchase_date')} />
        </Field>
        <Field id="pu-material" label={t('finance.material')} error={errors.material_name}>
          <Input id="pu-material" value={v.material_name} maxLength={255} onChange={set('material_name')} />
        </Field>
        <Field id="pu-vendor" label={t('finance.vendor')} error={errors.vendor_name}>
          <Input id="pu-vendor" value={v.vendor_name} maxLength={255} onChange={set('vendor_name')} />
        </Field>
        <Field id="pu-qty" label={t('finance.quantity')} error={errors.quantity}>
          <Input id="pu-qty" type="number" inputMode="decimal" min={0} step="0.01" value={v.quantity} onChange={set('quantity')} />
        </Field>
        <Field id="pu-unit" label={t('finance.unit')} error={errors.unit}>
          <Input id="pu-unit" value={v.unit} maxLength={30} onChange={set('unit')} />
        </Field>
        <Field id="pu-rate" label={t('finance.rate')} error={errors.rate}>
          <Input id="pu-rate" type="number" inputMode="decimal" min={0} step="0.01" value={v.rate} onChange={set('rate')} />
        </Field>
        <Field id="pu-gst" label={t('finance.gstPercent')} error={errors.gst_percent}>
          <Input id="pu-gst" type="number" inputMode="decimal" min={0} max={100} step="0.01" value={v.gst_percent} onChange={set('gst_percent')} />
        </Field>
        <Field id="pu-transport" label={t('finance.transport')} error={errors.transport_cost}>
          <Input id="pu-transport" type="number" inputMode="decimal" min={0} step="0.01" value={v.transport_cost} onChange={set('transport_cost')} />
        </Field>
        <Field id="pu-contact" label={t('finance.vendorContact')} error={errors.vendor_contact}>
          <Input id="pu-contact" value={v.vendor_contact} maxLength={100} onChange={set('vendor_contact')} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="pu-remarks" label={t('tasks.noteOptional')} error={errors.remarks}>
            <Input id="pu-remarks" value={v.remarks} maxLength={500} onChange={set('remarks')} />
          </Field>
        </div>
        <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm sm:col-span-2 dark:bg-slate-800" aria-live="polite">
          {t('finance.totalLine', { base: formatMoney(base), gst: formatMoney(gst), transport: formatMoney(num(v.transport_cost)), total: formatMoney(total) })}
        </p>
        {budget?.exceeds ? (
          <p role="status" className="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 sm:col-span-2">
            <AlertTriangle aria-hidden className="size-4" />
            {t('finance.overBudget', { remaining: formatMoney(budget.remaining) })}
          </p>
        ) : null}
      </div>
    </Modal>
  )
}

function PurchaseDetailDialog({ id, onEdit, onClose }: { id: number; onEdit: (p: Purchase) => void; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const q = useQuery({ queryKey: ['finance-purchase', id], queryFn: () => fetchPurchase(id) })
  const [amount, setAmount] = useState('')
  const [date, setDate] = useState(todayYmd())
  const [type, setType] = useState<PaymentType>('partial')
  const [mode, setMode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['finance-purchases'] })
    void queryClient.invalidateQueries({ queryKey: ['finance-budgets'] })
  }
  const pay = useMutation({
    mutationFn: () => payPurchase(id, { amount: Number(amount), payment_date: date, payment_type: type, payment_mode: mode.trim() || null, remarks: null }),
    onSuccess: (p) => {
      queryClient.setQueryData(['finance-purchase', id], p)
      refresh()
      setAmount('')
      setError(null)
      toast.success(t('finance.paymentSaved'))
    },
    onError: (e) => setError(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const remove = useMutation({
    mutationFn: () => deletePurchase(id),
    onSuccess: () => {
      refresh()
      toast.success(t('finance.deleted'))
      onClose()
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const p = q.data

  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={p ? p.material_name : t('common.loading')} description={p ? `${p.vendor_name} · ${formatDate(p.purchase_date)}` : undefined}>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {p ? (
        <div className="space-y-4 text-sm">
          <dl className="grid grid-cols-2 gap-2">
            <dt className="text-slate-500">{t('finance.quantity')}</dt>
            <dd>
              {p.quantity} {p.unit ?? ''} × {formatMoney(p.rate)}
            </dd>
            <dt className="text-slate-500">{t('finance.total')}</dt>
            <dd className="font-semibold">{formatMoney(p.total_amount)}</dd>
            <dt className="text-slate-500">{t('finance.paid')}</dt>
            <dd>{formatMoney(p.paid_amount)}</dd>
            <dt className="text-slate-500">{t('finance.due')}</dt>
            <dd className={p.due_amount > 0 ? 'font-semibold text-red-700' : ''}>{formatMoney(p.due_amount)}</dd>
          </dl>
          {p.payments.length ? (
            <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
              {p.payments.map((pay) => (
                <li key={pay.id} className="flex justify-between gap-2 px-3 py-2">
                  <span>
                    {formatDate(pay.payment_date)} · {t(`finance.paymentType.${pay.payment_type}`)}
                    {pay.payment_mode ? ` · ${pay.payment_mode}` : ''}
                  </span>
                  <span className="font-medium">{formatMoney(pay.amount)}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-slate-500">{t('finance.noPayments')}</p>
          )}
          {p.can_manage && p.due_amount > 0 ? (
            <form
              className="grid gap-2 sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault()
                if (!(Number(amount) > 0)) return setError(t('letters.amountRule'))
                pay.mutate()
              }}
            >
              <Field id="pp-amount" label={t('finance.payAmount')} error={error ?? undefined}>
                <Input id="pp-amount" type="number" inputMode="decimal" min={0} step="0.01" value={amount} placeholder={String(p.due_amount)} onChange={(e) => setAmount(e.target.value)} />
              </Field>
              <Field id="pp-date" label={t('attendance.date')}>
                <Input id="pp-date" type="date" max={todayYmd()} value={date} onChange={(e) => setDate(e.target.value)} />
              </Field>
              <Field id="pp-type" label={t('finance.paymentTypeLabel')}>
                <Select id="pp-type" value={type} onChange={(e) => setType(e.target.value as PaymentType)}>
                  {(['advance', 'partial', 'final'] as const).map((k) => (
                    <option key={k} value={k}>
                      {t(`finance.paymentType.${k}`)}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field id="pp-mode" label={t('finance.paymentMode')}>
                <Input id="pp-mode" value={mode} maxLength={50} placeholder={t('finance.paymentModeHint')} onChange={(e) => setMode(e.target.value)} />
              </Field>
              <Button type="submit" className="sm:col-span-2" disabled={pay.isPending}>
                {pay.isPending ? <Spinner label={t('common.saving')} /> : t('finance.recordPayment')}
              </Button>
            </form>
          ) : null}
          {p.can_manage ? (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => onEdit(p)}>
                {t('common.edit')}
              </Button>
              {p.payments.length === 0 ? (
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

export function PurchasesPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [fy, setFy] = useState<number | undefined>()
  const [status, setStatus] = useState<PaymentStatus | ''>('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [opened, setOpened] = useState<number | null>(null)
  const [editing, setEditing] = useState<Purchase | null | 'new'>(null)
  const debounced = useDebouncedValue(search.trim())
  const params = { fy, status, search: debounced, page }
  const q = useQuery({ queryKey: ['finance-purchases', user?.current_organization?.id, params], queryFn: () => fetchPurchases(params), placeholderData: keepPreviousData })
  const current = q.data?.meta.current_fy ?? new Date().getFullYear()
  const shown = q.data?.meta.fy ?? current

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.purchases')}</h1>
        <div className="flex flex-wrap items-center gap-4">
          {can('finance.export') ? (
            <a href={purchasesCsvUrl(shown)} className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
              <Download aria-hidden className="size-4" />
              {t('attendance.downloadCsv')}
            </a>
          ) : null}
          {q.data?.meta.can_manage ? (
            <Button onClick={() => setEditing('new')}>
              <Plus aria-hidden className="size-4" />
              {t('finance.newPurchase')}
            </Button>
          ) : null}
        </div>
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <Field id="pl-fy" label={t('letters.financialYear')}>
          <Select
            id="pl-fy"
            value={shown}
            onChange={(e) => {
              setFy(Number(e.target.value))
              setPage(1)
            }}
          >
            {[0, 1, 2, 3, 4].map((n) => (
              <option key={n} value={current - n}>
                {fyLabel(current - n)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="pl-status" label={t('finance.paymentStatus')}>
          <Select
            id="pl-status"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value as PaymentStatus | '')
              setPage(1)
            }}
          >
            <option value="">{t('dashboard.all')}</option>
            {(['pending', 'partial', 'paid'] as const).map((s) => (
              <option key={s} value={s}>
                {t(`finance.status.${s}`)}
              </option>
            ))}
          </Select>
        </Field>
        <div className="min-w-48 flex-1">
          <Field id="pl-search" label={t('common.search')}>
            <Input
              id="pl-search"
              type="search"
              value={search}
              placeholder={t('finance.searchHint')}
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
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('finance.noPurchases')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(q.data?.data ?? []).map((p) => (
            <li key={p.id}>
              <button type="button" onClick={() => setOpened(p.id)} className="flex w-full flex-col gap-1 px-4 py-3 text-left hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-slate-800">
                <div className="min-w-0">
                  <p className="truncate font-medium">{p.material_name}</p>
                  <p className="text-sm text-slate-500">{[p.vendor_name, p.department?.name, formatDate(p.purchase_date)].filter(Boolean).join(' · ')}</p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-3 text-sm">
                  <span className="font-semibold">{formatMoney(p.total_amount)}</span>
                  {p.due_amount > 0 ? <span className="text-slate-600">{t('finance.dueAmount', { amount: formatMoney(p.due_amount) })}</span> : null}
                  <StatusBadge status={p.payment_status} />
                </div>
              </button>
            </li>
          ))}
        </ul>
        {q.data ? (
          <div className="px-4 pb-3">
            <Pagination meta={q.data.meta} onPage={setPage} />
          </div>
        ) : null}
      </section>
      {opened !== null ? (
        <PurchaseDetailDialog
          id={opened}
          onClose={() => setOpened(null)}
          onEdit={(p) => {
            setOpened(null)
            setEditing(p)
          }}
        />
      ) : null}
      {editing ? <PurchaseDialog purchase={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </div>
  )
}
