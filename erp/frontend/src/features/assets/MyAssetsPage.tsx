import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchMyAssets, type Allocation } from './api'
import { when } from './when'

export function AllocationRow({ a }: { a: Allocation }) {
  const { t } = useTranslation()
  return (
    <li className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
      <span>
        <span className="font-medium">{a.item?.name}</span> <span className="text-slate-500">· {a.type?.name}</span>
        <span className="block text-slate-500">
          {[t('assets.givenOn', { when: when(a.allocated_at) }), a.purpose, a.allocated_by ? t('assets.givenBy', { name: a.allocated_by }) : null].filter(Boolean).join(' · ')}
        </span>
        {a.fields.length ? (
          <span className="block text-slate-500">
            {a.fields
              .filter((f) => f.key && a.custom[f.key])
              .map((f) => `${f.label}: ${a.custom[f.key!]}`)
              .join(' · ')}
          </span>
        ) : null}
      </span>
      {a.returned_at ? (
        <span className="text-slate-500">{t('assets.returnedOn', { when: when(a.returned_at) })}</span>
      ) : a.due_at ? (
        <Badge tone={a.overdue ? 'slate' : 'blue'}>
          <span className={a.overdue ? 'text-red-700' : undefined}>{a.overdue ? t('assets.overdueSince', { when: when(a.due_at) }) : t('assets.returnBy', { when: when(a.due_at) })}</span>
        </Badge>
      ) : null}
    </li>
  )
}

export function MyAssetsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['my-assets', user?.current_organization?.id], queryFn: fetchMyAssets })
  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const now = q.data.filter((a) => !a.returned_at)
  const before = q.data.filter((a) => a.returned_at)
  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('nav.myAssets')}</h1>
      <section aria-labelledby="ma-now" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="ma-now" className="mb-1 font-semibold">
          {t('assets.withMeNow')}
        </h2>
        {now.length === 0 ? <EmptyState message={t('assets.nothingWithYou')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {now.map((a) => (
            <AllocationRow key={a.id} a={a} />
          ))}
        </ul>
      </section>
      {before.length ? (
        <section aria-labelledby="ma-before" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
          <h2 id="ma-before" className="mb-1 font-semibold">
            {t('assets.returnedBefore')}
          </h2>
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {before.map((a) => (
              <AllocationRow key={a.id} a={a} />
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  )
}
