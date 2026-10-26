import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Paperclip } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import type { PaymentType } from '@/features/finance/api'
import { parseApiError } from '@/lib/api'
import { formatDate, formatMoney, todayYmd } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { attachToRenewal, fetchRenewal, payRenewal, renewalAttachmentUrl, renewItem, setRenewalActive, type RenewalDetail } from './api'
import { RenewalDialog, RenewalStatusBadge } from './RenewalsPage'

const monthLabel = (ym: string) => {
  const [y, m] = ym.split('-').map(Number)
  return new Intl.DateTimeFormat(undefined, { month: 'short', year: 'numeric' }).format(new Date(y, m - 1, 1))
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
      <h2 className="mb-3 font-semibold">{title}</h2>
      {children}
    </section>
  )
}

function useUpdate(id: number) {
  const queryClient = useQueryClient()
  return (saved: RenewalDetail) => {
    queryClient.setQueryData(['renewal', id], saved)
    void queryClient.invalidateQueries({ queryKey: ['renewals'] })
    void queryClient.invalidateQueries({ queryKey: ['finance-budgets'] })
  }
}

function RenewDialog({ item, onClose }: { item: RenewalDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const update = useUpdate(item.id)
  const [expiry, setExpiry] = useState('')
  const [issued, setIssued] = useState(todayYmd())
  const [cost, setCost] = useState(item.renewal_cost?.toString() ?? '')
  const [vendor, setVendor] = useState(item.vendor_name ?? '')
  const [reference, setReference] = useState('')
  const [error, setError] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => renewItem(item.id, { new_expiry_date: expiry, issued_date: issued || null, renewal_cost: cost === '' ? null : Number(cost), vendor_name: vendor.trim() || null, reference_no: reference.trim() || null }),
    onSuccess: (saved) => {
      update(saved)
      toast.success(t('renewals.renewed'))
      onClose()
    },
    onError: (e) => setError(actionErrorMessage(e, t('common.actionFailed'))),
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t('renewals.renewTitle', { name: item.item_name })}
      description={t('renewals.currentExpiry', { date: formatDate(item.expiry_date) })}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={() => (expiry > item.expiry_date ? save.mutate() : setError(t('renewals.laterExpiry')))}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('renewals.renew')}
          </Button>
        </>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {error ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {error}
          </p>
        ) : null}
        <Field id="rr-expiry" label={t('renewals.newExpiry')}>
          <Input id="rr-expiry" type="date" min={item.expiry_date} value={expiry} onChange={(e) => setExpiry(e.target.value)} />
        </Field>
        <Field id="rr-issued" label={t('renewals.issuedOn')}>
          <Input id="rr-issued" type="date" value={issued} onChange={(e) => setIssued(e.target.value)} />
        </Field>
        <Field id="rr-cost" label={t('renewals.cost')}>
          <Input id="rr-cost" type="number" inputMode="decimal" min={0} step="0.01" value={cost} onChange={(e) => setCost(e.target.value)} />
        </Field>
        <Field id="rr-vendor" label={t('finance.vendor')}>
          <Input id="rr-vendor" value={vendor} maxLength={255} onChange={(e) => setVendor(e.target.value)} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="rr-ref" label={t('renewals.newReference')}>
            <Input id="rr-ref" value={reference} maxLength={100} onChange={(e) => setReference(e.target.value)} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}

function PaymentForm({ item }: { item: RenewalDetail }) {
  const { t } = useTranslation()
  const toast = useToast()
  const update = useUpdate(item.id)
  const unpaid = item.months.filter((m) => !m.paid)
  const [amount, setAmount] = useState(item.is_recurring_monthly ? (item.monthly_amount?.toString() ?? '') : '')
  const [date, setDate] = useState(todayYmd())
  const [type, setType] = useState<PaymentType>(item.is_recurring_monthly ? 'final' : 'partial')
  const [mode, setMode] = useState('')
  const [month, setMonth] = useState(unpaid[0]?.month ?? '')
  const [error, setError] = useState<string | null>(null)
  const pay = useMutation({
    mutationFn: () => payRenewal(item.id, { amount: Number(amount), payment_date: date, payment_type: type, payment_mode: mode.trim() || null, for_month: item.is_recurring_monthly ? month : null, remarks: null }),
    onSuccess: (saved) => {
      update(saved)
      setError(null)
      toast.success(t('finance.paymentSaved'))
    },
    onError: (e) => {
      const { errors } = parseApiError(e)
      setError(errors ? (Object.values(errors)[0]?.[0] ?? t('common.actionFailed')) : actionErrorMessage(e, t('common.actionFailed')))
    },
  })
  return (
    <form
      className="mt-4 grid gap-2 sm:grid-cols-3"
      onSubmit={(e) => {
        e.preventDefault()
        if (!(Number(amount) > 0)) return setError(t('letters.amountRule'))
        pay.mutate()
      }}
    >
      {error ? (
        <p role="alert" className="text-sm text-red-600 sm:col-span-3">
          {error}
        </p>
      ) : null}
      {item.is_recurring_monthly ? (
        <Field id="rp-month" label={t('renewals.forMonth')}>
          <Select id="rp-month" value={month} onChange={(e) => setMonth(e.target.value)}>
            {item.months.map((m) => (
              <option key={m.month} value={m.month}>
                {monthLabel(m.month)}
                {m.paid ? ` (${t('finance.status.paid')})` : ''}
              </option>
            ))}
          </Select>
        </Field>
      ) : null}
      <Field id="rp-amount" label={t('finance.payAmount')}>
        <Input id="rp-amount" type="number" inputMode="decimal" min={0} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
      </Field>
      <Field id="rp-date" label={t('attendance.date')}>
        <Input id="rp-date" type="date" max={todayYmd()} value={date} onChange={(e) => setDate(e.target.value)} />
      </Field>
      <Field id="rp-type" label={t('finance.paymentTypeLabel')}>
        <Select id="rp-type" value={type} onChange={(e) => setType(e.target.value as PaymentType)}>
          {(['advance', 'partial', 'final'] as const).map((k) => (
            <option key={k} value={k}>
              {t(`finance.paymentType.${k}`)}
            </option>
          ))}
        </Select>
      </Field>
      <Field id="rp-mode" label={t('finance.paymentMode')}>
        <Input id="rp-mode" value={mode} maxLength={50} placeholder={t('finance.paymentModeHint')} onChange={(e) => setMode(e.target.value)} />
      </Field>
      <div className="flex items-end">
        <Button type="submit" disabled={pay.isPending}>
          {pay.isPending ? <Spinner label={t('common.saving')} /> : t('finance.recordPayment')}
        </Button>
      </div>
    </form>
  )
}

function Documents({ item }: { item: RenewalDetail }) {
  const { t } = useTranslation()
  const toast = useToast()
  const update = useUpdate(item.id)
  const [busy, setBusy] = useState(false)
  return (
    <Section title={t('renewals.documents')}>
      {item.attachments.length ? (
        <ul className="space-y-1 text-sm">
          {item.attachments.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center gap-2">
              <Paperclip aria-hidden className="size-4 text-slate-400" />
              <a href={renewalAttachmentUrl(item.id, a.id)} className="text-brand-700 hover:underline">
                {a.title || a.name}
              </a>
              {a.cycle_expiry_date ? <span className="text-xs text-slate-500">{t('renewals.forCycle', { date: formatDate(a.cycle_expiry_date) })}</span> : null}
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-slate-500">{t('letters.noAttachments')}</p>
      )}
      {item.can_manage ? (
        <div className="mt-3 flex items-center gap-3">
          <label htmlFor="rd-file" className="text-sm font-medium">
            {t('letters.attachFile')}
          </label>
          <input
            id="rd-file"
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
            className="text-sm"
            disabled={busy}
            onChange={async (e) => {
              const f = e.target.files?.[0]
              e.target.value = ''
              if (!f) return
              setBusy(true)
              try {
                update(await attachToRenewal(item.id, f, null))
                toast.success(t('letters.attached'))
              } catch (err) {
                toast.error(actionErrorMessage(err, t('common.actionFailed')))
              } finally {
                setBusy(false)
              }
            }}
          />
          {busy ? <Spinner label={t('common.saving')} /> : null}
        </div>
      ) : null}
    </Section>
  )
}

export function RenewalDetailPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const { id = '0' } = useParams()
  const q = useQuery({ queryKey: ['renewal', Number(id)], queryFn: () => fetchRenewal(Number(id)), retry: (n, e) => parseApiError(e).status !== 404 && n < 2 })
  const update = useUpdate(Number(id))
  const [dialog, setDialog] = useState<'edit' | 'renew' | null>(null)

  if (q.isPending) return <LoadingState />
  if (q.isError) return parseApiError(q.error).status === 404 ? <EmptyState message={t('renewals.notFound')} /> : <ErrorState onRetry={() => void q.refetch()} />
  const r = q.data
  const due = r.renewal_cost !== null ? Math.max(0, r.renewal_cost - r.cycle_paid) : null

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <Link to="/renewals" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.renewals')}
      </Link>
      <header className="space-y-2">
        <h1 className="text-2xl font-semibold">{r.item_name}</h1>
        <div className="flex flex-wrap items-center gap-3 text-sm">
          <RenewalStatusBadge status={r.status} days={r.days_left} />
          <span className="text-slate-600 dark:text-slate-400">{[r.item_type, r.regulatory_body, r.department?.name].filter(Boolean).join(' · ')}</span>
        </div>
      </header>
      {r.can_manage ? (
        <div role="group" aria-label={t('common.actions')} className="flex flex-wrap gap-2">
          {r.is_active ? <Button onClick={() => setDialog('renew')}>{t('renewals.renew')}</Button> : null}
          <Button variant="secondary" onClick={() => setDialog('edit')}>
            {t('common.edit')}
          </Button>
          <Button
            variant="secondary"
            onClick={async () => {
              try {
                update(await setRenewalActive(r.id, !r.is_active))
              } catch (e) {
                toast.error(actionErrorMessage(e, t('common.actionFailed')))
              }
            }}
          >
            {r.is_active ? t('renewals.deactivate') : t('renewals.activate')}
          </Button>
        </div>
      ) : null}

      <Section title={t('letters.details')}>
        <dl className="grid gap-3 text-sm sm:grid-cols-3">
          {[
            [t('renewals.expiresOn'), formatDate(r.expiry_date)],
            [t('renewals.issuedOn'), formatDate(r.issued_date)],
            [t('letters.referenceNo'), r.reference_no ?? '—'],
            [t('renewals.cost'), r.renewal_cost !== null ? formatMoney(r.renewal_cost) : '—'],
            [t('finance.vendor'), [r.vendor_name, r.vendor_contact].filter(Boolean).join(' · ') || '—'],
            [t('renewals.responsible'), r.responsible?.name ?? '—'],
          ].map(([k, v]) => (
            <div key={k}>
              <dt className="text-slate-500">{k}</dt>
              <dd className="font-medium">{v}</dd>
            </div>
          ))}
        </dl>
      </Section>

      <Section title={t('renewals.payments')}>
        {r.is_recurring_monthly ? (
          <p className="mb-3 text-sm">
            {t('renewals.monthlyLine', { amount: formatMoney(r.monthly_amount), count: r.months.filter((m) => !m.paid).length })}
          </p>
        ) : (
          <p className="mb-3 text-sm">{due !== null ? t('renewals.cycleLine', { paid: formatMoney(r.cycle_paid), due: formatMoney(due) }) : t('renewals.cyclePaid', { paid: formatMoney(r.cycle_paid) })}</p>
        )}
        {r.is_recurring_monthly && r.months.length ? (
          <ul className="mb-3 flex flex-wrap gap-2 text-xs" aria-label={t('renewals.months')}>
            {r.months.map((m) => (
              <li key={m.month} className={`rounded-full px-2 py-0.5 ring-1 ring-inset ${m.paid ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'}`}>
                {monthLabel(m.month)} · {m.paid ? t('finance.status.paid') : t('finance.status.pending')}
              </li>
            ))}
          </ul>
        ) : null}
        {r.payments.length ? (
          <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm dark:divide-slate-800 dark:border-slate-700">
            {r.payments.map((p) => (
              <li key={p.id} className="flex flex-wrap justify-between gap-2 px-3 py-2">
                <span>
                  {formatDate(p.payment_date)} · {t(`finance.paymentType.${p.payment_type}`)}
                  {p.for_month ? ` · ${monthLabel(p.for_month)}` : ''}
                  {p.payment_mode ? ` · ${p.payment_mode}` : ''}
                </span>
                <span className="font-medium">{formatMoney(p.amount)}</span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-slate-500">{t('finance.noPayments')}</p>
        )}
        {r.can_manage && r.is_active ? <PaymentForm key={r.payments.length} item={r} /> : null}
      </Section>

      <Documents item={r} />

      {r.history.length ? (
        <Section title={t('renewals.history')}>
          <ul className="space-y-2 text-sm">
            {r.history.map((h, i) => (
              <li key={i}>
                {t('renewals.historyLine', { old: formatDate(h.old_expiry_date), next: formatDate(h.new_expiry_date), name: h.renewed_by ?? '—' })}
                {h.old_renewal_cost !== null ? ` · ${formatMoney(h.old_renewal_cost)}` : ''}
                {h.old_reference_no ? ` · ${h.old_reference_no}` : ''}
              </li>
            ))}
          </ul>
        </Section>
      ) : null}

      {dialog === 'renew' ? <RenewDialog item={r} onClose={() => setDialog(null)} /> : null}
      {dialog === 'edit' ? <RenewalDialog item={r} onClose={() => setDialog(null)} /> : null}
    </div>
  )
}
