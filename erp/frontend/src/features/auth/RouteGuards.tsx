import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { useAuth } from './AuthProvider'

function FullPageState() {
  const { t } = useTranslation()
  const { isError, refetch } = useAuth()
  return (
    <div className="flex h-full items-center justify-center p-6 text-slate-600">
      {isError ? (
        <div className="space-y-3 text-center">
          <p>{t('auth.errors.network')}</p>
          <Button variant="secondary" onClick={refetch}>
            {t('common.retry')}
          </Button>
        </div>
      ) : (
        <Spinner label={t('common.loading')} />
      )}
    </div>
  )
}

/** Signed-in area: guests are sent to /login and brought back afterwards. */
export function RequireAuth() {
  const { user, isLoading, isError } = useAuth()
  const location = useLocation()
  if (isLoading || isError) return <FullPageState />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  return <Outlet />
}

/** Login page: an already signed-in user goes straight to the app. */
export function GuestOnly() {
  const { user, isLoading, isError } = useAuth()
  if (isLoading || isError) return <FullPageState />
  if (user) return <Navigate to="/" replace />
  return <Outlet />
}
