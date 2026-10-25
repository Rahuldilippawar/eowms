import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/Button'

export function NotFoundPage() {
  const { t } = useTranslation()
  return (
    <div className="mx-auto max-w-md py-16 text-center">
      <p className="text-5xl font-bold text-slate-300">404</p>
      <h1 className="mt-2 text-lg font-semibold">{t('notFound.title')}</h1>
      <Button asChild variant="secondary" className="mt-6">
        <Link to="/">{t('notFound.back')}</Link>
      </Button>
    </div>
  )
}
