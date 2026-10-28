import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Pencil } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useToast } from '@/components/ui/Toast'
import { when } from '@/features/assets/when'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate, formatMoney } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { ASSET_STATUSES, fetchItAsset, fetchLabs, setItAssetStatus, transferItAsset, type AssetStatus, type ItAssetDetail } from './api'
import { AssetDialog } from './ItDialogs'
import { StatusText } from './StatusText'
import { TicketRow } from './TicketRow'

export function ItAssetPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const { id } = useParams()
  const key = ['it-asset', user?.current_organization?.id, Number(id)]
  const q = useQuery({ queryKey: key, queryFn: () => fetchItAsset(Number(id)) })
  const labs = useQuery({ queryKey: ['it-labs', user?.current_organization?.id], queryFn: fetchLabs, enabled: !!q.data?.can_manage })
  const [editing, setEditing] = useState(false)
  const [to, setTo] = useState('')
  const [remarks, setRemarks] = useState('')
  const store = (a: ItAssetDetail) => {
    queryClient.setQueryData(key, a)
    void queryClient.invalidateQueries({ queryKey: ['it-assets'] })
    void queryClient.invalidateQueries({ queryKey: ['it-dashboard'] })
  }
  const onError = (e: unknown) => toast.error(actionErrorMessage(e, t('common.actionFailed')))
  const status = useMutation({ mutationFn: (input: { status?: AssetStatus; power_status?: 'on' | 'off' }) => setItAssetStatus(Number(id), input), onSuccess: store, onError })
  const move = useMutation({
    mutationFn: () => transferItAsset(Number(id), Number(to), remarks.trim() || null),
    onSuccess: (a) => {
      store(a)
      setTo('')
      setRemarks('')
      toast.success(t('it.moved', { lab: a.lab?.name ?? '' }))
    },
    onError,
  })

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const a = q.data
  const facts: [string, string | null][] = [
    [t('it.lab'), a.lab?.name ?? null],
    [t('it.field.workstation_no'), a.workstation_no],
    [t('it.field.serial_no'), a.serial_no],
    [t('it.field.purchase_date'), a.purchase_date ? formatDate(a.purchase_date) : null],
    [t('it.field.purchase_cost'), a.purchase_cost !== null ? formatMoney(a.purchase_cost) : null],
    [t('it.field.warranty_expiry'), a.warranty_expiry ? formatDate(a.warranty_expiry) : null],
    [t('it.field.os_license'), a.os_license],
    [t('it.field.license_expiry'), a.license_expiry ? formatDate(a.license_expiry) : null],
    [t('it.field.remarks'), a.remarks],
  ]

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/it/assets" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('it.assetsTitle')}
      </Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{a.asset_tag}</h1>
          <p className="text-sm text-slate-500">{[t(`it.type.${a.asset_type}`), a.brand, a.model].filter(Boolean).join(' · ')}</p>
        </div>
        {a.can_keep ? (
          <Button variant="secondary" onClick={() => setEditing(true)}>
            <Pencil aria-hidden className="size-4" />
            {t('common.edit')}
          </Button>
        ) : null}
      </div>
      <section className="flex flex-wrap items-end gap-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex-1 text-sm">
          <StatusText status={a.status} />
          <span className="block text-slate-500">
            {t(`it.power.${a.power_status}`)}
            {a.power_updated_at ? ` · ${when(a.power_updated_at)}` : ''}
          </span>
        </div>
        {a.can_keep ? (
          <>
            <Field id="ad-status" label={t('it.changeStatus')}>
              <Select id="ad-status" value={a.status} disabled={status.isPending} onChange={(e) => status.mutate({ status: e.target.value as AssetStatus })}>
                {ASSET_STATUSES.map((s) => (
                  <option key={s} value={s}>
                    {t(`it.status.${s}`)}
                  </option>
                ))}
              </Select>
            </Field>
            <Button variant="secondary" disabled={status.isPending} onClick={() => status.mutate({ power_status: a.power_status === 'on' ? 'off' : 'on' })}>
              {a.power_status === 'on' ? t('it.turnedOff') : t('it.turnedOn')}
            </Button>
          </>
        ) : null}
      </section>
      <dl className="grid gap-x-6 gap-y-2 rounded-2xl border border-slate-200 bg-white p-4 text-sm sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900">
        {facts
          .filter(([, v]) => v)
          .map(([k, v]) => (
            <div key={k}>
              <dt className="text-slate-500">{k}</dt>
              <dd className="font-medium">{v}</dd>
            </div>
          ))}
      </dl>
      <section aria-labelledby="ad-tickets" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="ad-tickets" className="font-semibold">
          {t('it.problems')}
        </h2>
        {a.tickets.length === 0 ? <EmptyState message={t('it.noProblems')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {a.tickets.map((tk) => (
            <TicketRow key={tk.id} ticket={tk} canResolve={a.can_manage} showAsset={false} onChanged={() => void q.refetch()} />
          ))}
        </ul>
      </section>
      {a.can_manage ? (
        <section aria-labelledby="ad-move" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
          <h2 id="ad-move" className="mb-2 font-semibold">
            {t('it.moveToLab')}
          </h2>
          <div className="flex flex-wrap items-end gap-2">
            <Field id="ad-to" label={t('it.toLab')}>
              <Select id="ad-to" value={to} onChange={(e) => setTo(e.target.value)}>
                <option value="">—</option>
                {(labs.data ?? [])
                  .filter((l) => l.is_active && l.id !== a.lab?.id)
                  .map((l) => (
                    <option key={l.id} value={l.id}>
                      {l.name}
                    </option>
                  ))}
              </Select>
            </Field>
            <div className="min-w-48 flex-1">
              <Field id="ad-remarks" label={t('it.field.remarks')}>
                <Input id="ad-remarks" maxLength={255} value={remarks} onChange={(e) => setRemarks(e.target.value)} />
              </Field>
            </div>
            <Button disabled={!to || move.isPending} onClick={() => move.mutate()}>
              {t('it.move')}
            </Button>
          </div>
          {a.transfers.length ? (
            <ul className="mt-3 space-y-1 text-sm text-slate-600">
              {a.transfers.map((x, i) => (
                <li key={i}>{t('it.transferLine', { when: when(x.at), from: x.from ?? '—', to: x.to ?? '—', by: x.by ?? '' })}</li>
              ))}
            </ul>
          ) : null}
        </section>
      ) : null}
      {editing ? (
        <AssetDialog
          asset={a}
          onClose={() => setEditing(false)}
          onSaved={(x) => {
            store(x)
            setEditing(false)
          }}
        />
      ) : null}
    </div>
  )
}
