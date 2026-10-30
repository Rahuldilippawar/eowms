import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Badge } from '@/components/ui/Badge'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDateTime } from '@/lib/format'
import { auditCsvUrl, fetchLogins, LOGIN_ACTIONS, type AuditFilters as Filters } from './api'
import { AuditFilters } from './AuditFilters'
import { AuditNav } from './AuditNav'

export function LoginActivityPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [f, setF] = useState<Filters>({ page: 1 })
  const q = useQuery({ queryKey: ['audit-logins', user?.current_organization?.id, f], queryFn: () => fetchLogins(f), placeholderData: keepPreviousData })
  const s = q.data?.meta.summary
  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('audit.loginsTitle')}</h1>
      <AuditNav />
      {s ? (
        <div className="grid gap-3 sm:grid-cols-3">
          {(
            [
              ['audit.logins7d', s.logins_7d, false],
              ['audit.users7d', s.users_7d, false],
              ['audit.failed7d', s.failed_7d, s.failed_7d > 0],
            ] as const
          ).map(([key, n, warn]) => (
            <div key={key} className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
              <p className="text-sm text-slate-500">{t(key)}</p>
              <p className={warn ? 'text-2xl font-semibold text-red-700' : 'text-2xl font-semibold'}>{n}</p>
            </div>
          ))}
        </div>
      ) : null}
      <AuditFilters id="la" value={f} onChange={setF} csvUrl={q.data?.meta.can_export ? auditCsvUrl('logins', f) : undefined}>
        <div className="w-full sm:w-48">
          <Field id="la-action" label={t('audit.event')}>
            <Select id="la-action" value={f.action ?? ''} onChange={(e) => setF({ ...f, action: e.target.value, page: 1 })}>
              <option value="">{t('audit.allEvents')}</option>
              {LOGIN_ACTIONS.map((a) => (
                <option key={a} value={a}>
                  {t(`audit.actions.${a}`)}
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
          <table className="w-full min-w-[40rem] text-left text-sm">
            <thead className="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800">
              <tr>
                <th className="px-3 py-2 font-medium">{t('audit.when')}</th>
                <th className="px-3 py-2 font-medium">{t('audit.who')}</th>
                <th className="px-3 py-2 font-medium">{t('audit.event')}</th>
                <th className="px-3 py-2 font-medium">{t('audit.ip')}</th>
                <th className="hidden px-3 py-2 font-medium md:table-cell">{t('audit.device')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {q.data.data.map((r) => (
                <tr key={r.id}>
                  <td className="px-3 py-2 whitespace-nowrap tabular-nums">{formatDateTime(r.created_at)}</td>
                  <td className="px-3 py-2">{r.user?.name ?? '—'}</td>
                  <td className="px-3 py-2">
                    {r.action === 'login_failed' ? (
                      <span className="text-xs font-semibold text-red-700">{t('audit.actions.login_failed')}</span>
                    ) : (
                      <Badge tone={r.action === 'login' ? 'green' : 'slate'}>{t(`audit.actions.${r.action}`, { defaultValue: r.action })}</Badge>
                    )}
                  </td>
                  <td className="px-3 py-2 font-mono text-xs">{r.ip_address ?? '—'}</td>
                  <td className="hidden max-w-xs truncate px-3 py-2 text-xs text-slate-500 md:table-cell" title={r.user_agent ?? undefined}>
                    {r.user_agent ?? '—'}
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
