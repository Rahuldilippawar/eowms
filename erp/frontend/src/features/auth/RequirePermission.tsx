import { ShieldAlert } from 'lucide-react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from './AuthProvider'

/** Hides a page the user cannot use; the API refuses the data regardless. */
export function RequirePermission({ permission, children }: { permission: string; children: ReactNode }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  if (can(permission)) return <>{children}</>
  return (
    <div className="mx-auto max-w-md py-16 text-center">
      <ShieldAlert aria-hidden className="mx-auto size-8 text-slate-400" />
      <h1 className="mt-3 text-lg font-semibold">{t('errors.forbiddenTitle')}</h1>
      <p className="mt-1 text-sm text-slate-500">{t('errors.forbiddenBody')}</p>
    </div>
  )
}
