import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/features/auth/AuthProvider'
import { addDays, formatDate, todayYmd } from '@/lib/format'
import { fetchMaterialsReport } from './api'

/** The old daily-work material usage report: what was used, by department and item. */
export function MaterialUsagePage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [from, setFrom] = useState(addDays(todayYmd(), -30))
  const [to, setTo] = useState(todayYmd())
  const q = useQuery({ queryKey: ['work-materials', user?.current_organization?.id, from, to], queryFn: () => fetchMaterialsReport({ from, to }), enabled: !!from && !!to && from <= to, placeholderData: keepPreviousData })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('work.materialUsage')}</h1>
      <div className="flex flex-wrap gap-3">
        <Field id="mu-from" label={t('store.from')}>
          <Input id="mu-from" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
        </Field>
        <Field id="mu-to" label={t('store.to')}>
          <Input id="mu-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
        </Field>
      </div>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.totals.length === 0 ? <EmptyState message={t('work.noMaterialUse')} /> : null}
      {q.data && q.data.totals.length ? (
        <>
          <section aria-labelledby="mu-totals" className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <h2 id="mu-totals" className="px-4 pt-4 font-semibold">
              {t('work.materialTotals')}
            </h2>
            <table className="w-full text-sm">
              <thead className="text-left text-slate-500">
                <tr>
                  <th className="px-3 py-2 font-medium">{t('store.department')}</th>
                  <th className="px-3 py-2 font-medium">{t('store.itemName')}</th>
                  <th className="px-3 py-2 text-right font-medium">{t('work.totalUsed')}</th>
                  <th className="px-3 py-2 text-right font-medium">{t('work.forEntries')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {q.data.totals.map((r) => (
                  <tr key={`${r.department}-${r.item_name}`}>
                    <td className="px-3 py-2">{r.department ?? t('store.noDepartment')}</td>
                    <td className="px-3 py-2">{r.item_name}</td>
                    <td className="px-3 py-2 text-right tabular-nums">
                      {r.quantity} {r.unit}
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums">{r.entries}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
          <section aria-labelledby="mu-rows" className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <h2 id="mu-rows" className="px-4 pt-4 font-semibold">
              {t('work.materialEntries')}
            </h2>
            <table className="w-full text-sm">
              <thead className="text-left text-slate-500">
                <tr>
                  <th className="px-3 py-2 font-medium">{t('store.date')}</th>
                  <th className="px-3 py-2 font-medium">{t('work.work')}</th>
                  <th className="hidden px-3 py-2 font-medium sm:table-cell">{t('work.employee')}</th>
                  <th className="px-3 py-2 font-medium">{t('store.itemName')}</th>
                  <th className="px-3 py-2 text-right font-medium">{t('work.quantity')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {q.data.rows.map((r, i) => (
                  <tr key={`${r.entry_id}-${i}`}>
                    <td className="px-3 py-2 whitespace-nowrap">{formatDate(r.work_date)}</td>
                    <td className="px-3 py-2">{r.title}</td>
                    <td className="hidden px-3 py-2 sm:table-cell">{r.employee}</td>
                    <td className="px-3 py-2">{r.item_name}</td>
                    <td className="px-3 py-2 text-right tabular-nums">
                      {r.quantity} {r.unit}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        </>
      ) : null}
    </div>
  )
}
