import { AlertTriangle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import type { TaskPriority, TaskStatus } from './api'

const statusTone: Record<TaskStatus, string> = {
  open: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  in_progress: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  submitted: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  cancelled: 'bg-slate-100 text-slate-500 ring-slate-500/20',
}

const priorityTone: Record<TaskPriority, string> = {
  low: 'text-slate-500',
  normal: 'text-slate-700 dark:text-slate-300',
  high: 'text-orange-700',
  urgent: 'text-red-700 font-semibold',
}

export function TaskStatusBadge({ status }: { status: TaskStatus }) {
  const { t } = useTranslation()
  return <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', statusTone[status])}>{t(`tasks.status.${status}`)}</span>
}

export function PriorityLabel({ priority }: { priority: TaskPriority }) {
  const { t } = useTranslation()
  return <span className={cn('text-xs', priorityTone[priority])}>{t(`tasks.priority.${priority}`)}</span>
}

/** Overdue carries an icon and a word, never colour alone. */
export function OverdueFlag() {
  const { t } = useTranslation()
  return (
    <span className="inline-flex items-center gap-1 text-xs font-medium text-red-700">
      <AlertTriangle aria-hidden className="size-3.5" />
      {t('tasks.overdue')}
    </span>
  )
}
