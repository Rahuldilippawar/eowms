import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDateTime } from '@/lib/format'
import { auditCsvUrl, fetchActivity, type AuditFilters as Filters, type AuditRow } from './api'
import { AuditFilters } from './AuditFilters'
import { AuditNav } from './AuditNav'

function Changes({ row }: { row: AuditRow }) {
  const { t } = useTranslation()
  const keys = [...new Set([...Object.keys(row.old_values ?? {}), ...Object.keys(row.new_values ?? {})])]
  if (keys.length === 0) return row.reason ? <span className="text-slate-600">{row.reason}</span> : <span className="text-slate-400">—</span>
  const show = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v))
  return (
    <details>
      <summary className="cursor-pointer text-brand-700">{t('audit.fieldsChanged', { count: keys.length })}</summary>
      <dl className="mt-1 space-y-0.5 text-xs">
        {keys.map((k) => (
          <div key={k} className="break-all">
            <dt className="inline font-medium">{k}: </dt>
            <dd className="inline">
              {row.old_values && k in row.old_values ? <span className="text-red-700 line-through">{show(row.old_values[k])}</span> : null} {show(row.new_values?.[k])}
            </dd>
          </div>
        ))}
      </dl>
      {row.reason ? <p className="mt-1 text-xs text-slate-600">{row.reason}</p> : null}
    </details>
  )
}

export function ActivityLogPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [f, setF] = useState<Filters>({ page: 1 })
  const q = useQuery({ queryKey: ['audit-activity', user?.current_organization?.id, f], queryFn: () => fetchActivity(f), placeholderData: keepPreviousData })
  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('nav.activityLog')}</h1>
      <AuditNav />
      <AuditFilters id="al" value={f} onChange={setF} csvUrl={q.data?.meta.can_export ? auditCsvUrl('activity', f) : undefined}>
        <div className="w-full sm:w-48">
          <Field id="al-module" label={t('audit.module')}>
            <Select id="al-module" value={f.module ?? ''} onChange={(e) => setF({ ...f, module: e.target.value, page: 1 })}>
              <option value="">{t('audit.allModules')}</option>
              {q.data?.meta.modules.map((m) => (
                <option key={m} value={m}>
                  {m}
                </option>
              ))}
            </Select>
          </Field>
        </div>
      </AuditFilters>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.data.length === 0 ? <EmptyState message={t('audit.empty')} /> : null}
      {q.data && q.data.data.length > 0 ? (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <table className="w-full min-w-[44rem] text-left text-sm">
            <thead className="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800">
              <tr>
                <th className="px-3 py-2 font-medium">{t('audit.when')}</th>
                <th className="px-3 py-2 font-medium">{t('audit.who')}</th>
                <th className="px-3 py-2 font-medium">{t('audit.what')}</th>
                <th className="px-3 py-2 font-medium">{t('audit.record')}</th>
                <th className="px-3 py-2 font-medium">{t('audit.details')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 align-top dark:divide-slate-800">
              {q.data.data.map((r) => (
                <tr key={r.id}>
                  <td className="px-3 py-2 whitespace-nowrap tabular-nums">{formatDateTime(r.created_at)}</td>
                  <td className="px-3 py-2">{r.user?.name ?? t('audit.system')}</td>
                  <td className="px-3 py-2">
                    <span className="font-medium">{r.module}</span> · {r.action}
                  </td>
                  <td className="px-3 py-2 text-xs">{r.subject ? `${r.subject.type} #${r.subject.id ?? '—'}` : '—'}</td>
                  <td className="max-w-md px-3 py-2">
                    <Changes row={r} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {q.data ? <Pagination meta={q.data.meta} onPage={(page) => setF({ ...f, page })} /> : null}
    </div>
  )
}
