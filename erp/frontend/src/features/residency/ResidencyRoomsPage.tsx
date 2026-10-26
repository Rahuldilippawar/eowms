import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { cn } from '@/lib/utils'
import { fetchRooms } from './api'
import { ResidencyNav } from './ResidencyNav'

export function ResidencyRoomsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [building, setBuilding] = useState('')
  const [search, setSearch] = useState('')
  const [vacant, setVacant] = useState(false)
  const debounced = useDebouncedValue(search.trim(), 300)
  const q = useQuery({
    queryKey: ['residency-rooms', user?.current_organization?.id, building, debounced, vacant],
    queryFn: () => fetchRooms({ building_id: building, search: debounced, vacant }),
    placeholderData: keepPreviousData,
  })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('nav.residency')}</h1>
      <ResidencyNav />
      <div className="flex flex-wrap items-end gap-3">
        <Field id="rr-building" label={t('residency.building')}>
          <Select id="rr-building" value={building} onChange={(e) => setBuilding(e.target.value)}>
            <option value="">{t('residency.allBuildings')}</option>
            {(q.data?.meta.buildings ?? []).map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </Select>
        </Field>
        <div className="min-w-48 flex-1">
          <Field id="rr-search" label={t('common.search')}>
            <Input id="rr-search" type="search" value={search} placeholder={t('residency.searchHint')} onChange={(e) => setSearch(e.target.value)} />
          </Field>
        </div>
        <label className="inline-flex min-h-10 items-center gap-2 text-sm">
          <input type="checkbox" checked={vacant} onChange={(e) => setVacant(e.target.checked)} />
          {t('residency.vacantOnly')}
        </label>
      </div>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.data.length === 0 ? <EmptyState message={t('residency.noRooms')} /> : null}
      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {(q.data?.data ?? []).map((r) => {
          const full = r.capacity !== null && r.occupants_count >= r.capacity
          return (
            <li key={r.id}>
              <Link to={`/residency/rooms/${r.id}`} className="block rounded-2xl border border-slate-200 bg-white p-4 hover:border-brand-600 dark:border-slate-800 dark:bg-slate-900">
                <p className="font-semibold">{r.name}</p>
                <p className="text-sm text-slate-500">{[r.building?.name, r.location_code].filter(Boolean).join(' · ')}</p>
                <p className={cn('mt-2 text-sm', r.occupants_count === 0 ? 'text-emerald-700' : full ? 'text-red-700' : 'text-slate-700 dark:text-slate-300')}>
                  {r.occupants_count === 0
                    ? t('residency.vacant')
                    : r.capacity !== null
                      ? t('residency.occupancy', { count: r.occupants_count, capacity: r.capacity })
                      : t('residency.occupants', { count: r.occupants_count })}
                </p>
                {r.staff.length ? <p className="truncate text-sm">{r.staff.join(', ')}</p> : null}
              </Link>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
