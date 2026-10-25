import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import type { WorkStatus } from './api'

const tone: Record<WorkStatus, string> = {
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  in_progress: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  pending: 'bg-amber-50 text-amber-800 ring-amber-600/20',
}

export function WorkStatusBadge({ status }: { status: WorkStatus }) {
  const { t } = useTranslation()
  return <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tone[status])}>{t(`work.status.${status}`)}</span>
}
