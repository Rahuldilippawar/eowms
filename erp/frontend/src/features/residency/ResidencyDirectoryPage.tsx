import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { RecipientPicker } from '@/features/letters/LetterBits'
import type { Recipient } from '@/features/letters/api'
import { formatDate } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import { fetchDirectory, fetchUnlinked, linkOccupant } from './api'
import { ResidencyNav } from './ResidencyNav'

const card = 'rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900'

/** Imported staff occupants with no account yet: pick the account to link. */
function Unlinked() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['residency-unlinked', user?.current_organization?.id], queryFn: fetchUnlinked })
  const [picks, setPicks] = useState<Record<number, Recipient | null>>({})
  const link = useMutation({
    mutationFn: ({ id, userId }: { id: number; userId: number }) => linkOccupant(id, userId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['residency-unlinked'] })
      void queryClient.invalidateQueries({ queryKey: ['residency-directory'] })
      toast.success(t('residency.linked'))
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  if (q.data.length === 0) return <EmptyState message={t('residency.allLinked')} />
  return (
    <ul className="divide-y divide-slate-100 dark:divide-slate-800">
      {q.data.map((o) => (
        <li key={o.id} className="flex flex-wrap items-end gap-3 px-4 py-3">
          <span className="min-w-40 flex-1 text-sm">
            <span className="block font-medium">{o.name}</span>
            <span className="block text-slate-500">{[o.building, o.room].filter(Boolean).join(' · ')}</span>
          </span>
          <div className="min-w-56 flex-1">
            <RecipientPicker label={t('residency.linkTo')} value={picks[o.id] ?? null} onChange={(r) => setPicks({ ...picks, [o.id]: r })} />
          </div>
          <Button disabled={!picks[o.id] || link.isPending} onClick={() => link.mutate({ id: o.id, userId: picks[o.id]!.user_id })}>
            {t('residency.link')}
          </Button>
        </li>
      ))}
    </ul>
  )
}

export function ResidencyDirectoryPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [tab, setTab] = useState<'resident' | 'non_resident' | 'unlinked'>('resident')
  const q = useQuery({ queryKey: ['residency-directory', user?.current_organization?.id], queryFn: fetchDirectory })
  const tabs = ['resident', 'non_resident', ...(can('residency.manage') ? (['unlinked'] as const) : [])] as const

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('residency.directoryTitle')}</h1>
      <ResidencyNav />
      <div role="tablist" className="inline-flex flex-wrap rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
        {tabs.map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={tab === k}
            onClick={() => setTab(k)}
            className={cn('min-h-9 rounded-md px-4 text-sm font-medium', tab === k ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}
          >
            {t(`residency.dirTabs.${k}`, { count: k === 'unlinked' ? (q.data?.unlinked ?? 0) : (q.data?.[k].length ?? 0) })}
          </button>
        ))}
      </div>
      <div className={card}>
        {tab === 'unlinked' ? (
          <Unlinked />
        ) : (
          <>
            {q.isPending ? <LoadingState /> : null}
            {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
            {q.data && q.data[tab].length === 0 ? <EmptyState message={t('common.noResults')} /> : null}
            <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
              {tab === 'resident'
                ? (q.data?.resident ?? []).map((p) => (
                    <li key={p.user_id} className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:justify-between">
                      <span>
                        <span className="block font-medium">{p.name}</span>
                        <span className="block text-slate-500">{[p.designation, p.department, p.phone].filter(Boolean).join(' · ')}</span>
                      </span>
                      <span className="sm:text-right">
                        <span className="block">{[p.building, p.room].filter(Boolean).join(' · ')}</span>
                        <span className="block text-slate-500">
                          {t('residency.since', { date: formatDate(p.moved_in_date) })}
                          {p.family_count ? ` · ${t('residency.familyCount', { count: p.family_count })}` : ''}
                        </span>
                      </span>
                    </li>
                  ))
                : (q.data?.non_resident ?? []).map((p) => (
                    <li key={p.user_id} className="px-4 py-3">
                      <span className="block font-medium">{p.name}</span>
                      <span className="block text-slate-500">{[p.designation, p.department, p.phone].filter(Boolean).join(' · ')}</span>
                    </li>
                  ))}
            </ul>
          </>
        )}
      </div>
    </div>
  )
}
