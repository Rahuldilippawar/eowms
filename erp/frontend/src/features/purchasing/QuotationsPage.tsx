import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
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
import { formatDate, formatMoney, todayYmd } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { acceptQuotation, fetchQuotations, fetchVendors, rejectQuotation, saveQuotation } from './api'
import { LinesEditor } from './LinesEditor'
import { blankLine, linesForApi, linesProblem, type DraftLine } from './lines'
import { PurchasingNav } from './PurchasingNav'

function QuotationDialog({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const departments = useQuery({ queryKey: ['department-options', user?.current_organization?.id], queryFn: fetchDepartmentOptions })
  const vendors = useQuery({ queryKey: ['vendors', user?.current_organization?.id, '', false], queryFn: () => fetchVendors() })
  const [v, setV] = useState({ department_id: '', vendor_id: '', quotation_date: todayYmd(), reference_no: '', valid_until: '' })
  const [lines, setLines] = useState<DraftLine[]>([blankLine])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () =>
      saveQuotation({
        department_id: Number(v.department_id),
        vendor_id: Number(v.vendor_id),
        quotation_date: v.quotation_date,
        reference_no: v.reference_no.trim() || null,
        valid_until: v.valid_until || null,
        remarks: null,
        lines: linesForApi(lines),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['quotations'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['department_id', 'vendor_id', 'quotation_date', 'reference_no', 'valid_until'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const submit = () => {
    const local: Record<string, string> = {}
    if (!v.department_id) local.department_id = t('form.required')
    if (!v.vendor_id) local.vendor_id = t('form.required')
    const lp = linesProblem(lines, t)
    if (lp) local.lines = lp
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t('purchasing.newQuotation')}
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
        <Field id="qt-dept" label={t('purchasing.department')} error={errors.department_id}>
          <Select id="qt-dept" value={v.department_id} onChange={(e) => setV({ ...v, department_id: e.target.value })}>
            <option value="">—</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="qt-vendor" label={t('purchasing.vendorLabel')} error={errors.vendor_id}>
          <Select id="qt-vendor" value={v.vendor_id} onChange={(e) => setV({ ...v, vendor_id: e.target.value })}>
            <option value="">—</option>
            {(vendors.data?.data ?? []).map((x) => (
              <option key={x.id} value={x.id}>
                {x.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="qt-date" label={t('purchasing.quotationDate')} error={errors.quotation_date}>
          <Input id="qt-date" type="date" max={todayYmd()} value={v.quotation_date} onChange={(e) => setV({ ...v, quotation_date: e.target.value })} />
        </Field>
        <Field id="qt-valid" label={t('purchasing.validUntil')} error={errors.valid_until}>
          <Input id="qt-valid" type="date" min={v.quotation_date} value={v.valid_until} onChange={(e) => setV({ ...v, valid_until: e.target.value })} />
        </Field>
        <Field id="qt-ref" label={t('purchasing.vendorReference')} error={errors.reference_no}>
          <Input id="qt-ref" maxLength={100} value={v.reference_no} onChange={(e) => setV({ ...v, reference_no: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <LinesEditor lines={lines} onChange={setLines} error={errors.lines} />
        </div>
      </div>
    </Modal>
  )
}

export function QuotationsPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const [status, setStatus] = useState('received')
  const [creating, setCreating] = useState(false)
  const q = useQuery({ queryKey: ['quotations', user?.current_organization?.id, status], queryFn: () => fetchQuotations({ status }) })
  const accept = useMutation({
    mutationFn: acceptQuotation,
    onSuccess: (o) => {
      void queryClient.invalidateQueries({ queryKey: ['quotations'] })
      toast.success(t('purchasing.orderDrafted'))
      navigate(`/purchasing/orders/${o.id}`)
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const reject = useMutation({
    mutationFn: rejectQuotation,
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['quotations'] }),
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('purchasing.quotationsTitle')}</h1>
        {q.data?.meta.can_manage ? (
          <Button onClick={() => setCreating(true)}>
            <Plus aria-hidden className="size-4" />
            {t('purchasing.newQuotation')}
          </Button>
        ) : null}
      </div>
      <PurchasingNav />
      <Field id="qt-status" label={t('attendance.statusLabel')}>
        <Select id="qt-status" value={status} onChange={(e) => setStatus(e.target.value)}>
          {(['received', 'accepted', 'rejected'] as const).map((s) => (
            <option key={s} value={s}>
              {t(`purchasing.quotationStatus.${s}`)}
            </option>
          ))}
        </Select>
      </Field>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.data.length === 0 ? <EmptyState message={t('purchasing.noQuotations')} /> : null}
      <ul className="space-y-3">
        {(q.data?.data ?? []).map((x) => (
          <li key={x.id} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className="font-semibold">
                  {x.vendor?.name} <Badge tone={x.status === 'accepted' ? 'green' : 'slate'}>{t(`purchasing.quotationStatus.${x.status}`)}</Badge>
                </p>
                <p className="text-sm text-slate-500">
                  {[x.department?.name, formatDate(x.quotation_date), x.reference_no, x.valid_until ? t('purchasing.validTill', { date: formatDate(x.valid_until) }) : null].filter(Boolean).join(' · ')}
                </p>
              </div>
              <p className="font-semibold tabular-nums">{formatMoney(x.total_amount)}</p>
            </div>
            <ul className="mt-2 text-sm text-slate-700 dark:text-slate-300">
              {x.lines.map((l) => (
                <li key={l.id}>
                  {l.description} — {l.quantity} {l.unit ?? ''} × {formatMoney(l.rate)}
                  {l.gst_percent ? ` + ${l.gst_percent}% GST` : ''}
                </li>
              ))}
            </ul>
            <div className="mt-3 flex flex-wrap gap-2">
              {x.order ? (
                <Link to={`/purchasing/orders/${x.order.id}`} className="text-sm font-medium text-brand-700 hover:underline">
                  {x.order.po_number ?? t('purchasing.draftOrder')}
                </Link>
              ) : null}
              {x.can_manage && x.status === 'received' ? (
                <>
                  <Button disabled={accept.isPending} onClick={() => accept.mutate(x.id)}>
                    {t('purchasing.acceptQuotation')}
                  </Button>
                  <Button variant="secondary" disabled={reject.isPending} onClick={() => reject.mutate(x.id)}>
                    {t('purchasing.rejectQuotation')}
                  </Button>
                </>
              ) : null}
            </div>
          </li>
        ))}
      </ul>
      {creating ? <QuotationDialog onClose={() => setCreating(false)} /> : null}
    </div>
  )
}
