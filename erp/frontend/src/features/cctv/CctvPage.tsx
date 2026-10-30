import { useQuery } from '@tanstack/react-query'
import { Plus, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { fetchCctvDashboard } from './api'
import { CctvNav } from './CctvNav'
import { DvrDialog } from './DvrDialog'

export function CctvPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [creating, setCreating] = useState(false)
  const q = useQuery({ queryKey: ['cctv-dashboard', user?.current_organization?.id], queryFn: fetchCctvDashboard })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.cctv')}</h1>
        {q.data?.can_manage ? (
          <div className="flex flex-wrap items-center gap-3">
            <Link to="/cctv/import" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
              <Upload aria-hidden className="size-4" />
              {t('cctv.import')}
            </Link>
            <Button onClick={() => setCreating(true)}>
              <Plus aria-hidden className="size-4" />
              {t('cctv.newDvr')}
            </Button>
          </div>
        ) : null}
      </div>
      <CctvNav />
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data ? (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
              [t('cctv.checkedToday'), `${q.data.checked_today} / ${q.data.dvrs.length}`],
              [t('cctv.camera.working'), q.data.cameras.working],
              [t('cctv.camera.not_working'), q.data.cameras.not_working],
              [t('cctv.camera.needs_attention'), q.data.cameras.needs_attention],
            ].map(([label, value]) => (
              <div key={String(label)} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                <p className="text-lg font-semibold tabular-nums">{value}</p>
                <p className="text-xs text-slate-500">{label}</p>
              </div>
            ))}
          </div>
          <p className="text-xs text-slate-500">{t('cctv.statusFromLatest')}</p>
          {q.data.dvrs.length === 0 ? <EmptyState message={t('cctv.noDvrs')} /> : null}
          <ul className="grid gap-3 sm:grid-cols-2">
            {q.data.dvrs.map((d) => (
              <li key={d.id} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <Link to={`/cctv/dvrs/${d.id}`} className="font-semibold text-brand-700 hover:underline">
                  {d.name}
                </Link>
                <p className="text-sm text-slate-500">
                  {[d.location, t('cctv.camerasOf', { count: d.cameras_count, capacity: d.channel_capacity ?? '—' })].filter(Boolean).join(' · ')}
                </p>
                <p className={cn('mt-2 text-sm', d.late ? 'font-medium text-red-700' : 'text-slate-700 dark:text-slate-300')}>
                  {d.last_checkup ? t('cctv.lastChecked', { date: formatDate(d.last_checkup.date) }) : t('cctv.neverChecked')}
                  {d.last_checkup && d.late ? ` — ${t('cctv.daysAgo', { count: d.days_since_checkup ?? 0 })}` : ''}
                </p>
                {d.last_checkup ? (
                  <p className="text-sm text-slate-500">
                    {[
                      d.last_checkup.storage_used_percent !== null ? t('cctv.storageUsed', { percent: d.last_checkup.storage_used_percent }) : null,
                      d.last_checkup.footage_days !== null ? t('cctv.footageDays', { count: d.last_checkup.footage_days }) : null,
                      d.last_checkup.backup_done ? t('cctv.backupDone') : t('cctv.noBackup'),
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                ) : null}
                {q.data.can_check ? (
                  <Link to={`/cctv/dvrs/${d.id}/checkup`} className="mt-3 inline-flex min-h-9 items-center rounded-lg bg-brand-700 px-3 text-sm font-medium text-white hover:bg-brand-800">
                    {t('cctv.checkNow')}
                  </Link>
                ) : null}
              </li>
            ))}
          </ul>
        </>
      ) : null}
      {creating ? <DvrDialog dvr={null} onClose={() => setCreating(false)} /> : null}
    </div>
  )
}
