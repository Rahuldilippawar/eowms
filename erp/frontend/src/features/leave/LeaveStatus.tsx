import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'

const tone = {
  requested: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  pending: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  approved: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  rejected: 'bg-red-50 text-red-700 ring-red-600/20',
  cancelled: 'bg-slate-100 text-slate-600 ring-slate-500/20',
} as const

export function RequestStatus({ status }: { status: keyof typeof tone }) {
  const { t } = useTranslation()
  return <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tone[status])}>{t(`requests.${status}`)}</span>
}
