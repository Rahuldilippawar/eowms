import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchSmartboards } from './api'
import { ItNav } from './ItNav'
import { StatusText } from './StatusText'

export function SmartboardsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['it-smartboards', user?.current_organization?.id], queryFn: fetchSmartboards })
  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('it.smartboardsTitle')}</h1>
      <ItNav />
      <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.length === 0 ? <EmptyState message={t('it.noSmartboards')} /> : null}
        {q.data && q.data.length ? (
          <table className="w-full text-sm">
            <thead className="text-left text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">{t('it.tag')}</th>
                <th className="px-3 py-2 font-medium">{t('it.lab')}</th>
                <th className="px-3 py-2 font-medium">{t('it.statusLabel')}</th>
                <th className="px-3 py-2 font-medium">{t('it.powerLabel')}</th>
                <th className="px-3 py-2 text-right font-medium">{t('it.problemsCount')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {q.data.map((a) => (
                <tr key={a.id}>
                  <td className="px-3 py-2">
                    <Link to={`/it/assets/${a.id}`} className="font-medium text-brand-700 hover:underline">
                      {a.asset_tag}
                    </Link>
                  </td>
                  <td className="px-3 py-2">{a.lab?.name}</td>
                  <td className="px-3 py-2">
                    <StatusText status={a.status} />
                  </td>
                  <td className="px-3 py-2">{t(`it.power.${a.power_status}`)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {a.tickets ?? 0}
                    {a.open_tickets ? <span className="text-red-700"> ({t('it.openCount', { count: a.open_tickets })})</span> : null}
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
