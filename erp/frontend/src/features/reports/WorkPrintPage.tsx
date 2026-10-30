import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { addDays, formatDate, todayYmd } from '@/lib/format'
import { fetchWorkPrint } from './api'
import { PrintButton } from './PrintButton'

export function WorkPrintPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [p, setP] = useState(() => ({ from: addDays(todayYmd(), -6), to: todayYmd(), status: '' }))
  const q = useQuery({ queryKey: ['work-print', user?.current_organization?.id, p], queryFn: () => fetchWorkPrint(p), enabled: p.from <= p.to })
  const d = q.data
  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-end gap-3 print:hidden">
        <Field id="wp-from" label={t('audit.from')}>
          <Input id="wp-from" type="date" value={p.from} max={p.to} onChange={(e) => setP({ ...p, from: e.target.value })} />
        </Field>
        <Field id="wp-to" label={t('audit.to')}>
          <Input id="wp-to" type="date" value={p.to} min={p.from} onChange={(e) => setP({ ...p, to: e.target.value })} />
        </Field>
        <div className="w-44">
          <Field id="wp-status" label={t('reports.status')}>
            <Select id="wp-status" value={p.status} onChange={(e) => setP({ ...p, status: e.target.value })}>
              <option value="">{t('reports.allStatuses')}</option>
              {(['completed', 'in_progress', 'pending'] as const).map((s) => (
                <option key={s} value={s}>
                  {t(`work.status.${s}`)}
                </option>
              ))}
            </Select>
          </Field>
        </div>
        <PrintButton />
      </div>

      <header>
        <p className="text-sm text-slate-500">{user?.current_organization?.name}</p>
        <h1 className="text-2xl font-semibold">{t('reports.items.workPrint.title')}</h1>
        {d ? <p className="text-sm text-slate-600">{t('reports.period', { from: formatDate(d.from), to: formatDate(d.to) })} · {t('reports.entryCount', { count: d.total })}</p> : null}
      </header>
      {q.isPending && p.from <= p.to ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {d ? (
        <>
          {d.truncated ? <p className="text-sm text-amber-700">{t('reports.truncated', { count: d.entries.length })}</p> : null}
          <section aria-labelledby="wp-summary" className="break-inside-avoid">
            <h2 id="wp-summary" className="mb-2 font-semibold">
              {t('reports.byDepartment')}
            </h2>
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr className="border-b border-slate-300 text-left">
                  <th className="py-1 pr-3 font-medium">{t('reports.department')}</th>
                  <th className="py-1 pr-3 text-right font-medium">{t('reports.entries')}</th>
                  <th className="py-1 pr-3 text-right font-medium">{t('work.status.completed')}</th>
                  <th className="py-1 pr-3 text-right font-medium">{t('work.status.in_progress')}</th>
                  <th className="py-1 pr-3 text-right font-medium">{t('reports.hours')}</th>
                  <th className="py-1 text-right font-medium">{t('reports.completion')}</th>
                </tr>
              </thead>
              <tbody>
                {d.summary.map((r) => (
                  <tr key={r.department ?? '-'} className="border-b border-slate-100">
                    <td className="py-1 pr-3">{r.department ?? '—'}</td>
                    <td className="py-1 pr-3 text-right tabular-nums">{r.total}</td>
                    <td className="py-1 pr-3 text-right tabular-nums">{r.completed}</td>
                    <td className="py-1 pr-3 text-right tabular-nums">{r.in_progress}</td>
                    <td className="py-1 pr-3 text-right tabular-nums">{r.hours}</td>
                    <td className="py-1 text-right tabular-nums">{r.completion}%</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
          <section aria-labelledby="wp-entries">
            <h2 id="wp-entries" className="mb-2 font-semibold">
              {t('reports.entriesTitle')}
            </h2>
            {d.entries.length === 0 ? <p className="text-sm text-slate-500">{t('reports.noEntries')}</p> : null}
            <div className="overflow-x-auto print:overflow-visible">
              <table className="w-full min-w-[48rem] border-collapse text-sm print:min-w-0">
                <thead>
                  <tr className="border-b border-slate-300 text-left">
                    <th className="py-1 pr-3 font-medium">{t('reports.date')}</th>
                    <th className="py-1 pr-3 font-medium">{t('reports.employee')}</th>
                    <th className="py-1 pr-3 font-medium">{t('reports.work')}</th>
                    <th className="py-1 pr-3 font-medium">{t('reports.status')}</th>
                    <th className="py-1 text-right font-medium">{t('reports.hours')}</th>
                  </tr>
                </thead>
                <tbody>
                  {d.entries.map((e) => (
                    <tr key={e.id} className="break-inside-avoid border-b border-slate-100 align-top">
                      <td className="py-1 pr-3 whitespace-nowrap">{formatDate(e.work_date)}</td>
                      <td className="py-1 pr-3">
                        {e.employee?.name ?? '—'}
                        <span className="block text-xs text-slate-500">{e.department ?? ''}</span>
                      </td>
                      <td className="py-1 pr-3">
                        <span className="font-medium">{e.title}</span>
                        {e.description ? <span className="block text-xs text-slate-600">{e.description}</span> : null}
                        {e.team.length ? <span className="block text-xs text-slate-500">{t('reports.with', { names: e.team.join(', ') })}</span> : null}
                        {e.materials.length ? (
                          <span className="block text-xs text-slate-500">
                            {t('reports.materialsUsed', { list: e.materials.map((m) => `${m.item ?? '—'} ${m.quantity} ${m.unit ?? ''}`.trim()).join(', ') })}
                          </span>
                        ) : null}
                      </td>
                      <td className="py-1 pr-3">
                        {t(`work.status.${e.status}`)}
                        {e.reviewed ? <span className="block text-xs text-slate-500">{t('reports.reviewed')}</span> : null}
                      </td>
                      <td className="py-1 text-right tabular-nums">{e.hours ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </>
      ) : null}
    </div>
  )
}
