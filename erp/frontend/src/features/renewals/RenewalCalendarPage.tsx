import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate, monthYm } from '@/lib/format'
import { fetchRenewalCalendar, type Renewal } from './api'
import { RenewalStatusBadge } from './RenewalsPage'

/** What expires in a month, day by day. */
export function RenewalCalendarPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [month, setMonth] = useState(monthYm())
  const q = useQuery({ queryKey: ['renewal-calendar', user?.current_organization?.id, month], queryFn: () => fetchRenewalCalendar(month), placeholderData: keepPreviousData })
  const byDay = new Map<string, Renewal[]>()
  for (const r of q.data?.data ?? []) byDay.set(r.expiry_date, [...(byDay.get(r.expiry_date) ?? []), r])

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('renewals.calendar')}</h1>
      <Field id="rc-month" label={t('attendance.month')}>
        <Input id="rc-month" type="month" value={month} onChange={(e) => e.target.value && setMonth(e.target.value)} />
      </Field>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('renewals.nothingThisMonth')} /> : null}
        {[...byDay.entries()].map(([day, items]) => (
          <div key={day} className="border-t border-slate-100 py-3 first:border-t-0 first:pt-0 dark:border-slate-800">
            <h2 className="text-sm font-semibold">{formatDate(day)}</h2>
            <ul className="mt-1 space-y-1">
              {items.map((r) => (
                <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                  <Link to={`/renewals/${r.id}`} className="text-brand-700 hover:underline">
                    {r.item_name}
                  </Link>
                  <span className="flex items-center gap-2 text-slate-500">
                    {r.department?.name}
                    <RenewalStatusBadge status={r.status} days={r.days_left} />
                  </span>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </section>
    </div>
  )
}
