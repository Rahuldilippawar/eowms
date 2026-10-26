import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { BookOpen, Plus, Settings2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { PriorityLabel } from '@/features/tasks/TaskBadges'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { fetchLetters, type LetterBox, type LetterStatus } from './api'
import { LetterStatusBadge } from './LetterBits'

export function LettersPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [box, setBox] = useState<LetterBox>('with_me')
  const [status, setStatus] = useState<LetterStatus | ''>('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const debounced = useDebouncedValue(search.trim())
  const params = { box, status, search: debounced, page }
  const q = useQuery({ queryKey: ['letters', user?.current_organization?.id, params], queryFn: () => fetchLetters(params), placeholderData: keepPreviousData })
  const reset = () => setPage(1)

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.letters')}</h1>
        <div className="flex flex-wrap items-center gap-4">
          {can('letters.view') ? (
            <Link to="/letters/register" className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
              <BookOpen aria-hidden className="size-4" />
              {t('letters.registerTitle')}
            </Link>
          ) : null}
          {can('letters.manage') ? (
            <Link to="/letters/settings" className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
              <Settings2 aria-hidden className="size-4" />
              {t('letters.settingsTitle')}
            </Link>
          ) : null}
          <Link to="/letters/new" className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-brand-700 px-4 text-sm font-medium text-white hover:bg-brand-800">
            <Plus aria-hidden className="size-4" />
            {t('letters.new')}
          </Link>
        </div>
      </div>

      <div role="tablist" aria-label={t('letters.boxes')} className="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm dark:bg-slate-800">
        {(['with_me', 'created', 'all'] as const).map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={box === k}
            onClick={() => {
              setBox(k)
              reset()
            }}
            className={cn('flex-1 rounded-md px-3 py-1.5 font-medium', box === k ? 'bg-white shadow-sm dark:bg-slate-900' : 'text-slate-600 dark:text-slate-400')}
          >
            {t(`letters.box.${k}`)}
            {k === 'with_me' && q.data?.meta.with_me ? <span className="ml-1 rounded-full bg-brand-600 px-1.5 text-xs text-white">{q.data.meta.with_me}</span> : null}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <Field id="lt-status" label={t('attendance.statusLabel')}>
          <Select
            id="lt-status"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value as LetterStatus | '')
              reset()
            }}
          >
            <option value="">{t('dashboard.all')}</option>
            {(['draft', 'in_progress', 'returned', 'completed'] as const).map((s) => (
              <option key={s} value={s}>
                {t(`letters.status.${s}`)}
              </option>
            ))}
          </Select>
        </Field>
        <div className="min-w-48 flex-1">
          <Field id="lt-search" label={t('common.search')}>
            <Input
              id="lt-search"
              type="search"
              value={search}
              placeholder={t('letters.searchHint')}
              onChange={(e) => {
                setSearch(e.target.value)
                reset()
              }}
            />
          </Field>
        </div>
      </div>

      <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t(box === 'with_me' ? 'letters.noneWithMe' : 'letters.none')} /> : null}
        {q.data && q.data.data.length > 0 ? (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {q.data.data.map((l) => (
              <li key={l.id}>
                <Link to={`/letters/${l.id}`} className="flex flex-col gap-1 px-4 py-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-slate-800">
                  <div className="min-w-0">
                    <p className={cn('truncate', l.holder && !l.holder_seen_at && box === 'with_me' ? 'font-semibold' : 'font-medium')}>
                      <span className="font-mono text-xs text-slate-500">{l.file_number}</span> {l.subject}
                    </p>
                    <p className="text-sm text-slate-500">
                      {[l.outward_no, l.creator ? t('letters.fromName', { name: l.creator.name }) : null, l.holder ? t('letters.withName', { name: l.holder.name }) : null].filter(Boolean).join(' · ')}
                    </p>
                  </div>
                  <div className="flex shrink-0 flex-wrap items-center gap-3 text-sm">
                    <PriorityLabel priority={l.priority} />
                    <span className="text-slate-600 dark:text-slate-400">{formatDate(l.created_at?.slice(0, 10))}</span>
                    <LetterStatusBadge status={l.status} />
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        ) : null}
        {q.data ? (
          <div className="px-4 pb-3">
            <Pagination meta={q.data.meta} onPage={setPage} />
          </div>
        ) : null}
      </section>
    </div>
  )
}
