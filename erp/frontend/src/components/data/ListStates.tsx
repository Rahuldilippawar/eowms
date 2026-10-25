import { AlertTriangle, Inbox } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'

export function LoadingState() {
  const { t } = useTranslation()
  return (
    <div className="flex justify-center py-12 text-slate-500">
      <Spinner label={t('common.loading')} />
    </div>
  )
}

export function ErrorState({ onRetry }: { onRetry: () => void }) {
  const { t } = useTranslation()
  return (
    <div role="alert" className="flex flex-col items-center gap-3 py-12 text-center text-slate-600">
      <AlertTriangle aria-hidden className="size-6 text-amber-500" />
      <p>{t('common.loadFailed')}</p>
      <Button variant="secondary" onClick={onRetry}>
        {t('common.retry')}
      </Button>
    </div>
  )
}

export function EmptyState({ message }: { message: string }) {
  return (
    <div className="flex flex-col items-center gap-2 py-12 text-center text-slate-500">
      <Inbox aria-hidden className="size-6" />
      <p>{message}</p>
    </div>
  )
}
