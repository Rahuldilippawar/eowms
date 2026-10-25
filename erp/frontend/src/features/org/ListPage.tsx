import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import type { Paginated } from '@/lib/pagination'
import type { ListParams } from './api'

export interface Column<T> {
  key: string
  header: string
  cell: (row: T) => ReactNode
}

interface ListPageProps<T extends { id: number }> {
  title: string
  queryKey: string
  fetcher: (params: ListParams) => Promise<Paginated<T>>
  columns: Column<T>[]
  emptyMessage: string
  /** Heading of each card on small screens. */
  cardTitle: (row: T) => ReactNode
  /** Buttons next to the title (e.g. "New"). */
  toolbar?: ReactNode
  /** Per-row action buttons; omitted when the user can do nothing with rows. */
  actions?: (row: T) => ReactNode
}

/** Read-only, searchable, paginated list: a table on wide screens, cards on phones. */
export function ListPage<T extends { id: number }>({ title, queryKey, fetcher, columns, emptyMessage, cardTitle, toolbar, actions }: ListPageProps<T>) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const debounced = useDebouncedValue(search.trim())

  const query = useQuery({
    queryKey: [queryKey, user?.current_organization?.id, debounced, page],
    queryFn: () => fetcher({ search: debounced, page }),
    placeholderData: keepPreviousData,
  })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-2xl font-semibold">{title}</h1>
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        {toolbar}
        <div className="relative sm:w-72">
          <Search aria-hidden className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
          <Input
            type="search"
            aria-label={t('common.search')}
            placeholder={t('common.search')}
            className="pl-9"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
        </div>
        </div>
      </div>

      <section className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        {query.isPending ? (
          <LoadingState />
        ) : query.isError ? (
          <ErrorState onRetry={() => void query.refetch()} />
        ) : query.data.data.length === 0 ? (
          <EmptyState message={debounced ? t('common.noResults') : emptyMessage} />
        ) : (
          <>
            <div className="hidden overflow-x-auto md:block">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-200 text-xs text-slate-500 uppercase dark:border-slate-800">
                  <tr>
                    {columns.map((c) => (
                      <th key={c.key} scope="col" className="px-3 py-2 font-semibold">
                        {c.header}
                      </th>
                    ))}
                    {actions ? (
                      <th scope="col" className="px-3 py-2">
                        <span className="sr-only">{t('common.actions')}</span>
                      </th>
                    ) : null}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {query.data.data.map((row) => (
                    <tr key={row.id}>
                      {columns.map((c) => (
                        <td key={c.key} className="px-3 py-2.5">
                          {c.cell(row)}
                        </td>
                      ))}
                      {actions ? <td className="px-3 py-2 text-right whitespace-nowrap">{actions(row)}</td> : null}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <ul className="space-y-3 md:hidden">
              {query.data.data.map((row) => (
                <li key={row.id} className="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                  <p className="font-medium">{cardTitle(row)}</p>
                  <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                    {columns.slice(1).map((c) => (
                      <div key={c.key} className="contents">
                        <dt className="text-slate-500">{c.header}</dt>
                        <dd>{c.cell(row)}</dd>
                      </div>
                    ))}
                  </dl>
                  {actions ? <div className="mt-3 flex flex-wrap gap-2">{actions(row)}</div> : null}
                </li>
              ))}
            </ul>

            <Pagination meta={query.data.meta} onPage={setPage} />
          </>
        )}
      </section>
    </div>
  )
}
