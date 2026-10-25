import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import type { AttendanceStatus } from './api'

const tone: Record<AttendanceStatus, string> = {
  present: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  late: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  half_day: 'bg-orange-50 text-orange-800 ring-orange-600/20',
  absent: 'bg-red-50 text-red-700 ring-red-600/20',
  leave: 'bg-sky-50 text-sky-700 ring-sky-600/20',
}

export function StatusBadge({ status }: { status: AttendanceStatus | null }) {
  const { t } = useTranslation()
  if (!status) return <span className="text-xs text-slate-500">{t('attendance.notMarked')}</span>
  return <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tone[status])}>{t(`attendance.status.${status}`)}</span>
}
