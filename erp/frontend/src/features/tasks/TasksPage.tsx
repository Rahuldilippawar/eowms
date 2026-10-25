import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { fetchAssignable, fetchTasks, type TaskStatus, type TaskView } from './api'
import { OverdueFlag, PriorityLabel, TaskStatusBadge } from './TaskBadges'
import { TaskDialog } from './TaskDialog'

export function TasksPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const orgKey = user?.current_organization?.id
  const [view, setView] = useState<TaskView>('mine')
  const [status, setStatus] = useState<TaskStatus | 'active' | ''>('active')
  const [overdue, setOverdue] = useState(false)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const debounced = useDebouncedValue(search.trim())
  const params = { view, status, overdue, search: debounced, page }
  const q = useQuery({ queryKey: ['tasks', orgKey, params], queryFn: () => fetchTasks(params), placeholderData: keepPreviousData })
  // Anyone with somebody to assign to (reporting officer or tasks.create) gets the button.
  const assignable = useQuery({ queryKey: ['task-assignable', orgKey], queryFn: fetchAssignable })
  const reset = () => setPage(1)

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.tasks')}</h1>
        {(assignable.data?.length ?? 0) > 0 ? (
          <Button onClick={() => setCreating(true)}>
            <Plus aria-hidden className="size-4" />
            {t('tasks.newTask')}
          </Button>
        ) : null}
      </div>

      <div role="tablist" aria-label={t('tasks.views')} className="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm dark:bg-slate-800">
        {(['mine', 'assigned', 'all'] as const).map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={view === k}
            onClick={() => {
              setView(k)
              reset()
            }}
            className={cn('flex-1 rounded-md px-3 py-1.5 font-medium', view === k ? 'bg-white shadow-sm dark:bg-slate-900' : 'text-slate-600 dark:text-slate-400')}
          >
            {t(`tasks.view.${k}`)}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <Field id="tk-status" label={t('attendance.statusLabel')}>
          <Select
            id="tk-status"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value as TaskStatus | 'active' | '')
              reset()
            }}
          >
            <option value="active">{t('tasks.status.active')}</option>
            <option value="">{t('dashboard.all')}</option>
            {(['open', 'in_progress', 'submitted', 'completed', 'cancelled'] as const).map((s) => (
              <option key={s} value={s}>
                {t(`tasks.status.${s}`)}
              </option>
            ))}
          </Select>
        </Field>
        <label className="flex min-h-10 items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={overdue}
            onChange={(e) => {
              setOverdue(e.target.checked)
              reset()
            }}
          />
          {t('tasks.overdueOnly')}
        </label>
        <div className="min-w-48 flex-1">
          <Field id="tk-search" label={t('common.search')}>
            <Input
              id="tk-search"
              type="search"
              value={search}
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
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('tasks.none')} /> : null}
        {q.data && q.data.data.length > 0 ? (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {q.data.data.map((task) => (
              <li key={task.id}>
                <Link to={`/tasks/${task.id}`} className="flex flex-col gap-1 px-4 py-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-slate-800">
                  <div className="min-w-0">
                    <p className="truncate font-medium">
                      <span className="font-mono text-xs text-slate-500">{task.code}</span> {task.title}
                    </p>
                    <p className="text-sm text-slate-500">
                      {view === 'mine' ? t('tasks.fromName', { name: task.assigned_by.name }) : t('tasks.toName', { name: task.assignee.name })}
                    </p>
                  </div>
                  <div className="flex shrink-0 flex-wrap items-center gap-3 text-sm">
                    <PriorityLabel priority={task.priority} />
                    <span className="text-slate-600 dark:text-slate-400">{t('tasks.dueOn', { date: formatDate(task.due_date) })}</span>
                    {task.is_overdue ? <OverdueFlag /> : null}
                    <TaskStatusBadge status={task.status} />
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
      <TaskDialog open={creating} onOpenChange={setCreating} />
    </div>
  )
}
