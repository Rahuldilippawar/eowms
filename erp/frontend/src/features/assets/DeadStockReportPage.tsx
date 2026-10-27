import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatMoney } from '@/lib/format'
import { fetchDeadStockReport, type ReportRow } from './api'
import { AssetsNav } from './AssetsNav'
import { DEAD_STOCK_LINKS } from './navLinks'

function Table({ title, rows, label }: { title: string; rows: ReportRow[]; label: (r: ReportRow) => string }) {
  const { t } = useTranslation()
  return (
    <section className="overflow-x-auto rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
      <h2 className="mb-2 font-semibold">{title}</h2>
      <table className="w-full text-sm">
        <thead className="text-left text-slate-500">
          <tr>
            <th className="py-1 font-medium" />
            <th className="py-1 text-right font-medium">{t('assets.entries')}</th>
            <th className="py-1 text-right font-medium">{t('assets.quantity')}</th>
            <th className="py-1 text-right font-medium">{t('assets.value')}</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((r) => (
            <tr key={String(r.key)}>
              <td className="py-1">{label(r)}</td>
              <td className="py-1 text-right tabular-nums">{r.entries}</td>
              <td className="py-1 text-right tabular-nums">{r.quantity}</td>
              <td className="py-1 text-right tabular-nums">{formatMoney(r.value)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}

export function DeadStockReportPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['dead-stock-report', user?.current_organization?.id], queryFn: fetchDeadStockReport })
  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('assets.reportTitle')}</h1>
      <AssetsNav links={DEAD_STOCK_LINKS} />
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data ? (
        <>
          <p className="text-lg font-semibold">{t('assets.totalValue', { value: formatMoney(q.data.total_value) })}</p>
          <div className="grid gap-4 lg:grid-cols-3">
            <Table title={t('assets.byDepartment')} rows={q.data.by_department} label={(r) => r.label ?? '—'} />
            <Table title={t('assets.byCategory')} rows={q.data.by_category} label={(r) => r.label ?? t('assets.noCategory')} />
            <Table title={t('assets.byCondition')} rows={q.data.by_condition} label={(r) => t(`assets.conditionName.${r.key}`)} />
          </div>
        </>
      ) : null}
    </div>
  )
}
