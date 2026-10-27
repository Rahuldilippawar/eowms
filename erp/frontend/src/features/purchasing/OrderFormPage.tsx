import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchDepartmentOptions } from '@/features/org/api'
import { todayYmd } from '@/lib/format'
import { fetchOrder, fetchVendors, saveOrder, type OrderDetail } from './api'
import { LinesEditor } from './LinesEditor'
import { blankLine, linesForApi, linesProblem, type DraftLine } from './lines'

function OrderForm({ order }: { order: OrderDetail | null }) {
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const departments = useQuery({ queryKey: ['department-options', user?.current_organization?.id], queryFn: fetchDepartmentOptions })
  const vendors = useQuery({ queryKey: ['vendors', user?.current_organization?.id, '', false], queryFn: () => fetchVendors() })
  const [v, setV] = useState({
    department_id: order?.department?.id.toString() ?? '',
    vendor_id: order?.vendor.id.toString() ?? '',
    order_date: order?.order_date ?? todayYmd(),
    expected_date: order?.expected_date ?? '',
    terms: order?.terms ?? '',
  })
  const [lines, setLines] = useState<DraftLine[]>(
    order?.lines.map((l) => ({ item_id: l.item_id?.toString() ?? '', description: l.description, quantity: String(l.quantity), unit: l.unit ?? '', rate: String(l.rate), gst_percent: l.gst_percent ? String(l.gst_percent) : '' })) ?? [blankLine],
  )
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () =>
      saveOrder(order?.id ?? null, {
        department_id: Number(v.department_id),
        vendor_id: Number(v.vendor_id),
        order_date: v.order_date,
        expected_date: v.expected_date || null,
        terms: v.terms.trim() || null,
        lines: linesForApi(lines),
      }),
    onSuccess: (o) => {
      queryClient.setQueryData(['purchase-order', user?.current_organization?.id, o.id], o)
      void queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
      toast.success(t('form.saved'))
      navigate(`/purchasing/orders/${o.id}`)
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['department_id', 'vendor_id', 'order_date', 'expected_date', 'terms', 'status'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general ?? r.byField.status ?? null)
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
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to={order ? `/purchasing/orders/${order.id}` : '/purchasing/orders'} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('purchasing.backToOrders')}
      </Link>
      <h1 className="text-2xl font-semibold">{order ? t('purchasing.editOrder') : t('purchasing.newOrder')}</h1>
      <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 dark:border-slate-800 dark:bg-slate-900">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <Field id="of-dept" label={t('purchasing.department')} error={errors.department_id}>
          <Select id="of-dept" value={v.department_id} onChange={(e) => setV({ ...v, department_id: e.target.value })}>
            <option value="">—</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="of-vendor" label={t('purchasing.vendorLabel')} error={errors.vendor_id}>
          <Select id="of-vendor" value={v.vendor_id} onChange={(e) => setV({ ...v, vendor_id: e.target.value })}>
            <option value="">—</option>
            {(vendors.data?.data ?? []).map((x) => (
              <option key={x.id} value={x.id}>
                {x.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="of-date" label={t('purchasing.orderDate')} error={errors.order_date}>
          <Input id="of-date" type="date" max={todayYmd()} value={v.order_date} onChange={(e) => setV({ ...v, order_date: e.target.value })} />
        </Field>
        <Field id="of-expected" label={t('purchasing.expectedDate')} error={errors.expected_date}>
          <Input id="of-expected" type="date" min={v.order_date} value={v.expected_date} onChange={(e) => setV({ ...v, expected_date: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <LinesEditor lines={lines} onChange={setLines} error={errors.lines} />
        </div>
        <div className="sm:col-span-2">
          <Field id="of-terms" label={t('purchasing.terms')} error={errors.terms}>
            <Textarea id="of-terms" maxLength={5000} value={v.terms} onChange={(e) => setV({ ...v, terms: e.target.value })} />
          </Field>
        </div>
        <div>
          <Button disabled={save.isPending} onClick={submit}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('purchasing.saveDraft')}
          </Button>
        </div>
      </div>
    </div>
  )
}

export function OrderFormPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['purchase-order', user?.current_organization?.id, Number(id)], queryFn: () => fetchOrder(Number(id)), enabled: !!id })
  if (!id) return <OrderForm order={null} />
  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  return <OrderForm order={q.data} />
}
