import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import type { OrderStatus } from './api'

const tone: Record<OrderStatus, string> = {
  draft: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  issued: 'bg-sky-50 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
  partly_received: 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
  received: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
  cancelled: 'bg-slate-100 text-slate-500 line-through dark:bg-slate-800',
}

export function OrderStatusBadge({ status }: { status: OrderStatus }) {
  const { t } = useTranslation()
  return <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium', tone[status])}>{t(`purchasing.status.${status}`)}</span>
}
