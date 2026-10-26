import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate, formatMoney } from '@/lib/format'
import { fetchLocation, fetchLocations, type LogEntry } from './api'
import { LogDetailDialog, LogDialog, WorkTypeBadge } from './MaintenancePage'

/** Everything done at one location, newest first (the old location-history page). */
export function MaintenanceLocationPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const locationId = Number(id)
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['maintenance-location', locationId], queryFn: () => fetchLocation(locationId) })
  const all = useQuery({ queryKey: ['maintenance-locations', user?.current_organization?.id, 'all'], queryFn: () => fetchLocations({}) })
  const [logging, setLogging] = useState(false)
  const [editing, setEditing] = useState<LogEntry | null>(null)
  const [viewing, setViewing] = useState<number | null>(null)
  const meta = all.data?.meta

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const l = q.data

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/maintenance" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.maintenance')}
      </Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{l.name}</h1>
          <p className="text-sm text-slate-500">{[l.location_code, l.building?.name, t('maintenance.floorN', { floor: l.floor })].filter(Boolean).join(' · ')}</p>
          <p className="mt-1 text-sm">{t('maintenance.totalSpent', { amount: formatMoney(l.total_cost) })}</p>
        </div>
        {meta?.can_log && meta.log_departments.length > 0 && l.is_active ? (
          <Button onClick={() => setLogging(true)}>
            <Plus aria-hidden className="size-4" />
            {t('maintenance.newEntry')}
          </Button>
        ) : null}
      </div>
      <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {l.logs.length === 0 ? <EmptyState message={t('maintenance.noEntries')} /> : null}
        <ol className="divide-y divide-slate-100 dark:divide-slate-800">
          {l.logs.map((e) => (
            <li key={e.id}>
              <button type="button" onClick={() => setViewing(e.id)} className="flex w-full flex-col gap-1 px-4 py-3 text-left hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-slate-800">
                <span className="min-w-0">
                  <span className="block font-medium">{e.item_description}</span>
                  <span className="block text-sm text-slate-500">{[formatDate(e.work_date), e.department?.name, e.performed_by].filter(Boolean).join(' · ')}</span>
                </span>
                <span className="flex shrink-0 items-center gap-3 text-sm">
                  <WorkTypeBadge type={e.work_type} />
                  {e.cost ? <span className="tabular-nums">{formatMoney(e.cost)}</span> : null}
                </span>
              </button>
            </li>
          ))}
        </ol>
      </section>
      {(logging || editing) && meta ? (
        <LogDialog
          log={editing}
          locations={all.data?.data ?? []}
          meta={meta}
          fixedLocation={editing ? undefined : l.id}
          onClose={() => {
            setLogging(false)
            setEditing(null)
          }}
        />
      ) : null}
      {viewing !== null ? (
        <LogDetailDialog
          id={viewing}
          onEdit={(e) => {
            setViewing(null)
            setEditing(e)
          }}
          onClose={() => setViewing(null)}
        />
      ) : null}
    </div>
  )
}
