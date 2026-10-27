import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Home } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate, formatMoney } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cancelHousingRequest, fetchMyResidency, requestHousing, type MyResidency } from './api'

const card = 'rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900'

function RequestForm({ data, onDone }: { data: MyResidency; onDone: (d: MyResidency) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [v, setV] = useState({ building: '', family: '1', reason: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const send = useMutation({
    mutationFn: () => requestHousing({ preferred_building_id: v.building ? Number(v.building) : null, family_size: Number(v.family) || 1, reason: v.reason.trim() || null }),
    onSuccess: (d) => {
      onDone(d)
      setErrors({})
      toast.success(t('residency.requestSent'))
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['preferred_building_id', 'family_size', 'reason'], t('common.actionFailed'))
      setErrors({ ...r.byField, ...(r.general ? { reason: r.general } : {}) })
    },
  })
  return (
    <form
      className="grid gap-3 sm:grid-cols-2"
      onSubmit={(e) => {
        e.preventDefault()
        send.mutate()
      }}
    >
      <Field id="mr-building" label={t('residency.preferredBuilding')} error={errors.preferred_building_id}>
        <Select id="mr-building" value={v.building} onChange={(e) => setV({ ...v, building: e.target.value })}>
          <option value="">{t('residency.anyBuilding')}</option>
          {data.buildings.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </Select>
      </Field>
      <Field id="mr-family" label={t('residency.familySize')} error={errors.family_size}>
        <Input id="mr-family" type="number" inputMode="numeric" min={1} max={20} value={v.family} onChange={(e) => setV({ ...v, family: e.target.value })} />
      </Field>
      <div className="sm:col-span-2">
        <Field id="mr-reason" label={t('residency.reason')} error={errors.reason}>
          <Textarea id="mr-reason" maxLength={2000} value={v.reason} onChange={(e) => setV({ ...v, reason: e.target.value })} />
        </Field>
      </div>
      <div>
        <Button type="submit" disabled={send.isPending}>
          {send.isPending ? <Spinner label={t('common.saving')} /> : t('residency.sendRequest')}
        </Button>
      </div>
    </form>
  )
}

export function MyResidencyPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const key = ['my-residency', user?.current_organization?.id]
  const q = useQuery({ queryKey: key, queryFn: fetchMyResidency })
  const store = (d: MyResidency) => queryClient.setQueryData(key, d)
  const cancel = useMutation({
    mutationFn: cancelHousingRequest,
    onSuccess: (d) => {
      store(d)
      toast.success(t('residency.requestCancelled'))
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const d = q.data
  const open = d.requests.find((r) => r.status === 'pending' || r.status === 'waitlisted')

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('nav.myResidency')}</h1>

      <section aria-labelledby="mr-home" className={card}>
        <h2 id="mr-home" className="mb-2 flex items-center gap-2 font-semibold">
          <Home aria-hidden className="size-4" />
          {t('residency.myHome')}
        </h2>
        {d.home ? (
          <div className="space-y-2 text-sm">
            <p className="text-lg font-semibold">{[d.home.room, d.home.building].filter(Boolean).join(' · ')}</p>
            {d.home.moved_in_date ? <p className="text-slate-500">{t('residency.since', { date: formatDate(d.home.moved_in_date) })}</p> : null}
            <h3 className="pt-2 font-medium">{t('residency.family')}</h3>
            {d.home.family.length === 0 ? (
              <p className="text-slate-500">{t('residency.noFamily')}</p>
            ) : (
              <ul className="list-inside list-disc">
                {d.home.family.map((f) => (
                  <li key={f.id}>{[f.name, f.relation, f.age !== null ? t('residency.ageYears', { age: f.age }) : null].filter(Boolean).join(' · ')}</li>
                ))}
              </ul>
            )}
          </div>
        ) : (
          <p className="text-sm text-slate-500">{t('residency.notHoused')}</p>
        )}
      </section>

      {d.bills.length > 0 ? (
        <section aria-labelledby="mr-bills" className={card}>
          <h2 id="mr-bills" className="mb-2 font-semibold">
            {t('residency.myBills')}
          </h2>
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {d.bills.map((b) => (
              <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span>
                  <span className="font-medium">{b.billing_month}</span>
                  {b.shared_by && b.shared_by > 1 ? <span className="block text-slate-500">{t('residency.sharedOf', { total: formatMoney(b.bill_total), count: b.shared_by })}</span> : null}
                </span>
                <span className="flex items-center gap-2">
                  <span className="font-semibold tabular-nums">{formatMoney(b.amount_due)}</span>
                  <Badge tone={b.paid_at ? 'green' : 'slate'}>{b.paid_at ? t('residency.paid') : t('residency.unpaid')}</Badge>
                </span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {!d.home ? (
        <section aria-labelledby="mr-request" className={card}>
          <h2 id="mr-request" className="mb-2 font-semibold">
            {t('residency.askForHousing')}
          </h2>
          {open ? (
            <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
              <p>
                {t('residency.requestOpen', { date: formatDate(open.created_at?.slice(0, 10)) })} <Badge tone="blue">{t(`residency.status.${open.status}`)}</Badge>
              </p>
              <Button variant="secondary" disabled={cancel.isPending} onClick={() => cancel.mutate(open.id)}>
                {t('residency.cancelRequest')}
              </Button>
            </div>
          ) : (
            <RequestForm data={d} onDone={store} />
          )}
        </section>
      ) : null}

      {d.requests.length > 0 ? (
        <section aria-labelledby="mr-history" className={card}>
          <h2 id="mr-history" className="mb-2 font-semibold">
            {t('residency.myRequests')}
          </h2>
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {d.requests.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span>
                  {formatDate(r.created_at?.slice(0, 10))}
                  {r.allotted_room ? ` · ${r.allotted_room}` : ''}
                  {r.remarks ? <span className="block text-slate-500">{r.remarks}</span> : null}
                </span>
                <Badge tone={r.status === 'approved' ? 'green' : 'slate'}>{t(`residency.status.${r.status}`)}</Badge>
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  )
}
