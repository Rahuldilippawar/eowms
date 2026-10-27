import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Pencil, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import type { PaymentType } from '@/features/finance/api'
import { formatDate, formatMoney, todayYmd } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import { addInvoice, cancelOrder, deleteInvoice, fetchOrder, issueOrder, payInvoice, receiveGoods, type Invoice, type OrderDetail } from './api'
import { OrderStatusBadge } from './OrderStatusBadge'

const card = 'rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900'

function useOrderForm(fields: string[], run: () => Promise<OrderDetail>, onSaved: (o: OrderDetail) => void) {
  const { t } = useTranslation()
  const toast = useToast()
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const m = useMutation({
    mutationFn: run,
    onSuccess: (o) => {
      onSaved(o)
      toast.success(t('form.saved'))
    },
    onError: (e) => {
      const r = splitServerErrors(e, fields, t('common.actionFailed'))
      setErrors(r.byField)
      // Line errors come back as lines.<id>; show them at the top.
      const lineError = Object.entries(r.byField).find(([k]) => k.startsWith('lines'))?.[1]
      setGeneral(r.general ?? lineError ?? r.byField.status ?? null)
    },
  })
  return { errors, general, m }
}

function DialogFooter({ onClose, busy, onSave, label }: { onClose: () => void; busy: boolean; onSave: () => void; label: string }) {
  const { t } = useTranslation()
  return (
    <>
      <Button variant="secondary" onClick={onClose}>
        {t('common.cancel')}
      </Button>
      <Button disabled={busy} onClick={onSave}>
        {busy ? <Spinner label={t('common.saving')} /> : label}
      </Button>
    </>
  )
}

function ReceiveDialog({ order, onClose, onSaved }: { order: OrderDetail; onClose: () => void; onSaved: (o: OrderDetail) => void }) {
  const { t } = useTranslation()
  const open = order.lines.filter((l) => l.received_quantity < l.quantity)
  const [qty, setQty] = useState<Record<number, string>>(Object.fromEntries(open.map((l) => [l.id, String(Math.round((l.quantity - l.received_quantity) * 100) / 100)])))
  const [date, setDate] = useState(todayYmd())
  const [remarks, setRemarks] = useState('')
  const f = useOrderForm(
    ['received_date', 'remarks'],
    () => receiveGoods(order.id, { received_date: date, remarks: remarks.trim() || null, lines: open.map((l) => ({ line_id: l.id, quantity: Number(qty[l.id]) || 0 })) }),
    (o) => {
      onSaved(o)
      onClose()
    },
  )
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={t('purchasing.receiveGoods')} footer={<DialogFooter onClose={onClose} busy={f.m.isPending} onSave={() => f.m.mutate()} label={t('purchasing.saveReceipt')} />}>
      <div className="grid gap-3">
        {f.general ? (
          <p role="alert" className="text-sm text-red-600">
            {f.general}
          </p>
        ) : null}
        <Field id="gr-date" label={t('purchasing.receivedOn')} error={f.errors.received_date}>
          <Input id="gr-date" type="date" min={order.order_date} max={todayYmd()} value={date} onChange={(e) => setDate(e.target.value)} />
        </Field>
        {open.map((l) => (
          <Field key={l.id} id={`gr-${l.id}`} label={t('purchasing.receivedQty', { item: l.description, left: Math.round((l.quantity - l.received_quantity) * 100) / 100, unit: l.unit ?? '' })}>
            <Input id={`gr-${l.id}`} type="number" inputMode="decimal" min={0} step="0.01" value={qty[l.id] ?? ''} onChange={(e) => setQty({ ...qty, [l.id]: e.target.value })} />
          </Field>
        ))}
        <Field id="gr-remarks" label={t('store.remarks')} error={f.errors.remarks}>
          <Input id="gr-remarks" maxLength={500} value={remarks} onChange={(e) => setRemarks(e.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}

function InvoiceDialog({ order, onClose, onSaved }: { order: OrderDetail; onClose: () => void; onSaved: (o: OrderDetail) => void }) {
  const { t } = useTranslation()
  const [v, setV] = useState({ invoice_number: '', invoice_date: todayYmd(), goods_amount: String(order.invoiceable_amount), other_charges: '' })
  const f = useOrderForm(
    ['invoice_number', 'invoice_date', 'goods_amount', 'other_charges'],
    () => addInvoice(order.id, { invoice_number: v.invoice_number.trim(), invoice_date: v.invoice_date, goods_amount: Number(v.goods_amount), other_charges: v.other_charges === '' ? null : Number(v.other_charges), remarks: null }),
    (o) => {
      onSaved(o)
      onClose()
    },
  )
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={t('purchasing.recordInvoice')} footer={<DialogFooter onClose={onClose} busy={f.m.isPending} onSave={() => f.m.mutate()} label={t('common.save')} />}>
      <div className="grid gap-3 sm:grid-cols-2">
        {f.general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {f.general}
          </p>
        ) : null}
        <p className="text-sm text-slate-600 sm:col-span-2">{t('purchasing.invoiceableHint', { amount: formatMoney(order.invoiceable_amount) })}</p>
        <Field id="iv-no" label={t('purchasing.invoiceNumber')} error={f.errors.invoice_number}>
          <Input id="iv-no" maxLength={100} value={v.invoice_number} onChange={(e) => setV({ ...v, invoice_number: e.target.value })} />
        </Field>
        <Field id="iv-date" label={t('purchasing.invoiceDate')} error={f.errors.invoice_date}>
          <Input id="iv-date" type="date" min={order.order_date} max={todayYmd()} value={v.invoice_date} onChange={(e) => setV({ ...v, invoice_date: e.target.value })} />
        </Field>
        <Field id="iv-goods" label={t('purchasing.goodsAmount')} error={f.errors.goods_amount}>
          <Input id="iv-goods" type="number" inputMode="decimal" min={0} step="0.01" value={v.goods_amount} onChange={(e) => setV({ ...v, goods_amount: e.target.value })} />
        </Field>
        <Field id="iv-other" label={t('purchasing.otherCharges')} error={f.errors.other_charges}>
          <Input id="iv-other" type="number" inputMode="decimal" min={0} step="0.01" value={v.other_charges} onChange={(e) => setV({ ...v, other_charges: e.target.value })} />
        </Field>
      </div>
    </Modal>
  )
}

function PayDialog({ invoice, onClose, onSaved }: { invoice: Invoice; onClose: () => void; onSaved: (o: OrderDetail) => void }) {
  const { t } = useTranslation()
  const [v, setV] = useState({ amount: String(invoice.due_amount), payment_date: todayYmd(), payment_type: 'final' as PaymentType, payment_mode: '' })
  const f = useOrderForm(
    ['amount', 'payment_date', 'payment_type', 'payment_mode'],
    () => payInvoice(invoice.id, { amount: Number(v.amount), payment_date: v.payment_date, payment_type: v.payment_type, payment_mode: v.payment_mode.trim() || null, remarks: null }),
    (o) => {
      onSaved(o)
      onClose()
    },
  )
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t('purchasing.payInvoice', { number: invoice.invoice_number })}
      footer={<DialogFooter onClose={onClose} busy={f.m.isPending} onSave={() => f.m.mutate()} label={t('finance.recordPayment')} />}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {f.general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {f.general}
          </p>
        ) : null}
        <Field id="ip-amount" label={t('purchasing.payAmount')} error={f.errors.amount}>
          <Input id="ip-amount" type="number" inputMode="decimal" min={0} step="0.01" value={v.amount} onChange={(e) => setV({ ...v, amount: e.target.value })} />
        </Field>
        <Field id="ip-date" label={t('purchasing.paidOn')} error={f.errors.payment_date}>
          <Input id="ip-date" type="date" max={todayYmd()} value={v.payment_date} onChange={(e) => setV({ ...v, payment_date: e.target.value })} />
        </Field>
        <Field id="ip-type" label={t('finance.paymentTypeLabel')}>
          <Select id="ip-type" value={v.payment_type} onChange={(e) => setV({ ...v, payment_type: e.target.value as PaymentType })}>
            {(['advance', 'partial', 'final'] as const).map((k) => (
              <option key={k} value={k}>
                {t(`finance.paymentType.${k}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="ip-mode" label={t('finance.paymentMode')} error={f.errors.payment_mode}>
          <Input id="ip-mode" maxLength={50} placeholder={t('finance.paymentModeHint')} value={v.payment_mode} onChange={(e) => setV({ ...v, payment_mode: e.target.value })} />
        </Field>
      </div>
    </Modal>
  )
}

type Dialog = { kind: 'receive' | 'invoice' | 'cancel' } | { kind: 'pay' | 'deleteInvoice'; invoice: Invoice }

export function OrderPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const { id } = useParams()
  const key = ['purchase-order', user?.current_organization?.id, Number(id)]
  const q = useQuery({ queryKey: key, queryFn: () => fetchOrder(Number(id)) })
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const store = (o: OrderDetail) => {
    queryClient.setQueryData(key, o)
    void queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
    void queryClient.invalidateQueries({ queryKey: ['store-items'] })
  }
  const issue = useMutation({
    mutationFn: () => issueOrder(Number(id)),
    onSuccess: (o) => {
      store(o)
      toast.success(t('purchasing.issued', { number: o.po_number }))
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const o = q.data
  const close = () => setDialog(null)

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/purchasing/orders" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('purchasing.backToOrders')}
      </Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex flex-wrap items-center gap-2 text-2xl font-semibold">
            {o.po_number ?? t('purchasing.draftOrder')} <OrderStatusBadge status={o.status} />
          </h1>
          <p className="text-sm text-slate-500">
            {[o.vendor.name, o.department?.name, t('purchasing.orderedOn', { date: formatDate(o.order_date) }), o.expected_date ? t('purchasing.expectedBy', { date: formatDate(o.expected_date) }) : null]
              .filter(Boolean)
              .join(' · ')}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {o.can_manage && o.status === 'draft' ? (
            <>
              <Link to={`/purchasing/orders/${o.id}/edit`} className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 px-4 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                <Pencil aria-hidden className="size-4" />
                {t('common.edit')}
              </Link>
              <Button disabled={issue.isPending} onClick={() => issue.mutate()}>
                {t('purchasing.issueOrder')}
              </Button>
            </>
          ) : null}
          {o.can_receive ? <Button onClick={() => setDialog({ kind: 'receive' })}>{t('purchasing.receiveGoods')}</Button> : null}
          {o.can_manage && o.invoiceable_amount > 0 ? (
            <Button variant="secondary" onClick={() => setDialog({ kind: 'invoice' })}>
              {t('purchasing.recordInvoice')}
            </Button>
          ) : null}
          {o.can_manage && (o.status === 'draft' || o.status === 'issued') ? (
            <Button variant="secondary" onClick={() => setDialog({ kind: 'cancel' })}>
              {t('purchasing.cancelOrder')}
            </Button>
          ) : null}
        </div>
      </div>
      {o.budget_left !== null && o.status === 'draft' && o.total_amount > o.budget_left ? (
        <p role="status" className="rounded-xl bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
          {t('purchasing.overBudget', { left: formatMoney(o.budget_left) })}
        </p>
      ) : null}

      <section aria-labelledby="po-lines" className={cn(card, 'overflow-x-auto')}>
        <h2 id="po-lines" className="mb-2 font-semibold">
          {t('purchasing.lines')}
        </h2>
        <table className="w-full text-sm">
          <thead className="text-left text-slate-500">
            <tr>
              <th className="py-1 pe-2 font-medium">{t('purchasing.description')}</th>
              <th className="py-1 pe-2 text-right font-medium">{t('purchasing.qty')}</th>
              <th className="py-1 pe-2 text-right font-medium">{t('purchasing.rate')}</th>
              <th className="py-1 pe-2 text-right font-medium">{t('purchasing.received')}</th>
              <th className="py-1 text-right font-medium">{t('purchasing.amount')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {o.lines.map((l) => (
              <tr key={l.id}>
                <td className="py-1 pe-2">{l.description}</td>
                <td className="py-1 pe-2 text-right tabular-nums">
                  {l.quantity} {l.unit ?? ''}
                </td>
                <td className="py-1 pe-2 text-right tabular-nums">
                  {formatMoney(l.rate)}
                  {l.gst_percent ? <span className="block text-xs text-slate-500">+{l.gst_percent}% GST</span> : null}
                </td>
                <td className={cn('py-1 pe-2 text-right tabular-nums', l.received_quantity >= l.quantity ? 'text-emerald-700' : '')}>{l.received_quantity}</td>
                <td className="py-1 text-right tabular-nums">{formatMoney(l.amount)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot className="text-sm">
            <tr>
              <td colSpan={4} className="pt-2 text-right text-slate-500">
                {t('purchasing.subtotalGst', { subtotal: formatMoney(o.subtotal), gst: formatMoney(o.gst_amount) })}
              </td>
              <td className="pt-2 text-right font-semibold tabular-nums">{formatMoney(o.total_amount)}</td>
            </tr>
          </tfoot>
        </table>
        {o.terms ? <p className="mt-3 text-sm whitespace-pre-line text-slate-600">{o.terms}</p> : null}
      </section>

      {o.receipts.length ? (
        <section aria-labelledby="po-receipts" className={card}>
          <h2 id="po-receipts" className="mb-2 font-semibold">
            {t('purchasing.receipts')}
          </h2>
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {o.receipts.map((r) => (
              <li key={r.id} className="py-2">
                <span className="font-medium">{r.grn_number}</span> · {formatDate(r.received_date)} · {r.received_by}
                <span className="block text-slate-500">{r.lines.map((l) => `${l.description}: ${l.quantity}`).join(', ')}</span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {o.invoices.length ? (
        <section aria-labelledby="po-invoices" className={card}>
          <h2 id="po-invoices" className="mb-2 font-semibold">
            {t('purchasing.invoices')}
          </h2>
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {o.invoices.map((i) => (
              <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span>
                  <span className="font-medium">{i.invoice_number}</span> · {formatDate(i.invoice_date)}
                  <span className="block text-slate-500">
                    {t('purchasing.invoiceLine', { total: formatMoney(i.total_amount), paid: formatMoney(i.paid_amount), due: formatMoney(i.due_amount) })}
                  </span>
                </span>
                {o.can_manage ? (
                  <span className="flex gap-2">
                    {i.due_amount > 0 ? (
                      <Button variant="secondary" onClick={() => setDialog({ kind: 'pay', invoice: i })}>
                        {t('purchasing.pay')}
                      </Button>
                    ) : null}
                    {i.payments.length === 0 ? (
                      <Button variant="ghost" aria-label={t('purchasing.deleteInvoice', { number: i.invoice_number })} onClick={() => setDialog({ kind: 'deleteInvoice', invoice: i })}>
                        <Trash2 aria-hidden className="size-4" />
                      </Button>
                    ) : null}
                  </span>
                ) : null}
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {dialog?.kind === 'receive' ? <ReceiveDialog order={o} onClose={close} onSaved={store} /> : null}
      {dialog?.kind === 'invoice' ? <InvoiceDialog order={o} onClose={close} onSaved={store} /> : null}
      {dialog?.kind === 'pay' ? <PayDialog invoice={dialog.invoice} onClose={close} onSaved={store} /> : null}
      <ConfirmDialog
        open={dialog?.kind === 'cancel'}
        onOpenChange={(open) => !open && close()}
        title={t('purchasing.cancelTitle')}
        body={t('purchasing.cancelBody')}
        confirmLabel={t('purchasing.cancelOrder')}
        danger
        withReason
        onConfirm={async (reason) => {
          store(await cancelOrder(o.id, reason || null))
          close()
        }}
      />
      <ConfirmDialog
        open={dialog?.kind === 'deleteInvoice'}
        onOpenChange={(open) => !open && close()}
        title={t('purchasing.deleteInvoiceTitle')}
        body={t('purchasing.deleteInvoiceBody')}
        confirmLabel={t('common.delete')}
        danger
        onConfirm={async () => {
          if (dialog?.kind === 'deleteInvoice') store(await deleteInvoice(dialog.invoice.id))
          close()
        }}
      />
    </div>
  )
}
