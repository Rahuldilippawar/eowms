import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchTickets } from './api'
import { ItNav } from './ItNav'
import { TicketRow } from './TicketRow'

export function ItTicketsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [status, setStatus] = useState('open')
  const q = useQuery({ queryKey: ['it-tickets', user?.current_organization?.id, status], queryFn: () => fetchTickets(status) })
  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('it.ticketsTitle')}</h1>
      <ItNav />
      <Field id="it-status" label={t('it.statusLabel')}>
        <Select id="it-status" value={status} onChange={(e) => setStatus(e.target.value)}>
          {(['open', 'pending', 'in_progress', 'resolved', 'all'] as const).map((s) => (
            <option key={s} value={s}>
              {t(`it.ticketFilter.${s}`)}
            </option>
          ))}
        </Select>
      </Field>
      <div className="rounded-2xl border border-slate-200 bg-white px-4 dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('it.noTickets')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(q.data?.data ?? []).map((tk) => (
            <TicketRow key={tk.id} ticket={tk} canResolve={q.data?.meta.can_resolve ?? false} onChanged={() => void q.refetch()} />
          ))}
        </ul>
      </div>
    </div>
  )
}
