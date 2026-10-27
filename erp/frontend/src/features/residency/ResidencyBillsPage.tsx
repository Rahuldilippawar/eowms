import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatMoney, monthYm } from '@/lib/format'
import { fetchBillsReport } from './api'
import { ResidencyNav } from './ResidencyNav'

export function ResidencyBillsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [month, setMonth] = useState(monthYm())
  const q = useQuery({ queryKey: ['residency-bills', user?.current_organization?.id, month], queryFn: () => fetchBillsReport(month), enabled: /^\d{4}-\d{2}$/.test(month), placeholderData: keepPreviousData })

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('residency.billsTitle')}</h1>
      <ResidencyNav />
      <Field id="rb-month" label={t('residency.month')}>
        <Input id="rb-month" type="month" value={month} onChange={(e) => setMonth(e.target.value)} />
      </Field>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data ? (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            {[
              [t('residency.billed'), formatMoney(q.data.total)],
              [t('residency.pending'), formatMoney(q.data.pending)],
              [t('residency.roomsBilled'), String(q.data.bills.length)],
            ].map(([label, value]) => (
              <div key={label} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                <p className="text-lg font-semibold tabular-nums">{value}</p>
                <p className="text-xs text-slate-500">{label}</p>
              </div>
            ))}
          </div>
          {q.data.bills.length === 0 ? <EmptyState message={t('residency.noBills')} /> : null}
          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            {q.data.bills.length ? (
              <table className="w-full text-sm">
                <thead className="text-left text-slate-500">
                  <tr>
                    <th className="px-3 py-2 font-medium">{t('residency.room')}</th>
                    <th className="px-3 py-2 font-medium">{t('residency.shares')}</th>
                    <th className="px-3 py-2 text-right font-medium">{t('residency.total')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {q.data.bills.map((b) => (
                    <tr key={b.id}>
                      <td className="px-3 py-2">
                        <Link to={`/residency/rooms/${b.location_id}`} className="font-medium text-brand-700 hover:underline">
                          {[b.building, b.room].filter(Boolean).join(' · ')}
                        </Link>
                      </td>
                      <td className="px-3 py-2">
                        {b.shares.map((s) => (
                          <span key={s.id} className="block">
                            {s.occupant}: {formatMoney(s.amount_due)} {s.paid_at ? `✓ ${t('residency.paid')}` : `— ${t('residency.unpaid')}`}
                          </span>
                        ))}
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums">{formatMoney(b.total_amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : null}
          </div>
        </>
      ) : null}
    </div>
  )
}
