import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import type { AssetStatus } from './api'

const tone: Record<AssetStatus, string> = { working: 'text-emerald-700', faulty: 'text-red-700', under_repair: 'text-amber-700', scrapped: 'text-slate-500' }

export function StatusText({ status }: { status: AssetStatus }) {
  const { t } = useTranslation()
  return <span className={cn('font-medium', tone[status])}>{t(`it.status.${status}`)}</span>
}
