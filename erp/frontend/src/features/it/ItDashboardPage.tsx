import { useQuery } from '@tanstack/react-query'
import { Plus, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate } from '@/lib/format'
import { ASSET_STATUSES, fetchItDashboard, type Lab } from './api'
import { LabDialog } from './ItDialogs'
import { ItNav } from './ItNav'

export function ItDashboardPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [editing, setEditing] = useState<Lab | 'new' | null>(null)
  const q = useQuery({ queryKey: ['it-dashboard', user?.current_organization?.id], queryFn: fetchItDashboard })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.it')}</h1>
        {q.data?.can_manage ? (
          <div className="flex flex-wrap items-center gap-3">
            <Link to="/it/import" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
              <Upload aria-hidden className="size-4" />
              {t('it.import')}
            </Link>
            <Button onClick={() => setEditing('new')}>
              <Plus aria-hidden className="size-4" />
              {t('it.newLab')}
            </Button>
          </div>
        ) : null}
      </div>
      <ItNav />
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data ? (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
            {ASSET_STATUSES.map((s) => (
              <Link key={s} to={`/it/assets?status=${s}`} className="rounded-xl border border-slate-200 bg-white p-3 hover:border-brand-600 dark:border-slate-800 dark:bg-slate-900">
                <p className="text-lg font-semibold tabular-nums">{q.data.by_status[s] ?? 0}</p>
                <p className="text-xs text-slate-500">{t(`it.status.${s}`)}</p>
              </Link>
            ))}
            <Link to="/it/tickets" className="rounded-xl border border-slate-200 bg-white p-3 hover:border-brand-600 dark:border-slate-800 dark:bg-slate-900">
              <p className="text-lg font-semibold tabular-nums">{q.data.open_tickets}</p>
              <p className="text-xs text-slate-500">{t('it.openTickets')}</p>
            </Link>
          </div>
          <p className="text-sm">
            <Link to="/it/smartboards" className="font-medium text-brand-700 hover:underline">
              {t('it.smartboardLine', { total: q.data.smartboards.total, on: q.data.smartboards.on, faulty: q.data.smartboards.faulty })}
            </Link>
          </p>
          <section aria-labelledby="it-labs" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="it-labs" className="mb-2 font-semibold">
              {t('it.labs')}
            </h2>
            <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
              {q.data.labs.map((l) => (
                <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span>
                    <Link to={`/it/assets?lab_id=${l.id}`} className="font-medium text-brand-700 hover:underline">
                      {l.name}
                    </Link>
                    <span className="block text-slate-500">{[l.location, l.assistant ? t('it.assistantName', { name: l.assistant.name }) : null].filter(Boolean).join(' · ')}</span>
                  </span>
                  <span className="flex items-center gap-3">
                    <span>{t('it.labCounts', { total: l.assets_count ?? 0, working: l.working_count ?? 0 })}</span>
                    {l.issue_count ? <span className="font-medium text-red-700">{t('it.withProblems', { count: l.issue_count })}</span> : null}
                    {q.data.can_manage ? (
                      <button type="button" className="font-medium text-brand-700 hover:underline" aria-label={t('common.editNamed', { name: l.name })} onClick={() => setEditing(l)}>
                        {t('common.edit')}
                      </button>
                    ) : null}
                  </span>
                </li>
              ))}
            </ul>
          </section>
          {q.data.expiring.length ? (
            <section aria-labelledby="it-expiring" className="rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
              <h2 id="it-expiring" className="mb-2 font-semibold">
                {t('it.expiringSoon')}
              </h2>
              <ul className="space-y-1 text-sm">
                {q.data.expiring.map((e) => (
                  <li key={e.id}>
                    <Link to={`/it/assets/${e.id}`} className="font-medium text-brand-700 hover:underline">
                      {e.asset_tag}
                    </Link>{' '}
                    · {e.lab}
                    {e.warranty_expiry ? ` · ${t('it.warrantyUntil', { date: formatDate(e.warranty_expiry) })}` : ''}
                    {e.license_expiry ? ` · ${t('it.licenseUntil', { date: formatDate(e.license_expiry) })}` : ''}
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
        </>
      ) : null}
      {editing ? <LabDialog lab={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </div>
  )
}
