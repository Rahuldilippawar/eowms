import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/features/auth/AuthProvider'
import { todayYmd } from '@/lib/format'
import { fetchIssues } from './api'

export function StoreIssuesPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [from, setFrom] = useState(`${todayYmd().slice(0, 8)}01`)
  const [to, setTo] = useState(todayYmd())
  const q = useQuery({ queryKey: ['store-issues', user?.current_organization?.id, from, to], queryFn: () => fetchIssues(from, to), enabled: !!from && !!to && from <= to, placeholderData: keepPreviousData })

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/store" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.store')}
      </Link>
      <h1 className="text-2xl font-semibold">{t('store.issuesReport')}</h1>
      <div className="flex flex-wrap gap-3">
        <Field id="is-from" label={t('store.from')}>
          <Input id="is-from" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
        </Field>
        <Field id="is-to" label={t('store.to')}>
          <Input id="is-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
        </Field>
      </div>
      <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.length === 0 ? <EmptyState message={t('store.noIssues')} /> : null}
        {q.data && q.data.length ? (
          <table className="w-full text-sm">
            <thead className="text-left text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">{t('store.department')}</th>
                <th className="px-3 py-2 font-medium">{t('store.itemName')}</th>
                <th className="px-3 py-2 text-right font-medium">{t('store.given')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {q.data.map((r) => (
                <tr key={`${r.department}-${r.item_id}`}>
                  <td className="px-3 py-2">{r.department ?? t('store.noDepartment')}</td>
                  <td className="px-3 py-2">
                    <Link to={`/store/items/${r.item_id}`} className="text-brand-700 hover:underline">
                      {r.item_name}
                    </Link>
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {r.quantity} {r.unit} <span className="text-xs text-slate-500">({t('store.times', { count: r.issues })})</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>
    </div>
  )
}
