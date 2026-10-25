import { LayoutGrid } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/features/auth/AuthProvider'

export function DashboardPage() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  if (!user) return null

  const lastLogin = user.last_login_at
    ? new Intl.DateTimeFormat(i18n.language === 'mr' ? 'mr-IN' : 'en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(
        new Date(user.last_login_at),
      )
    : null

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('dashboard.welcome', { name: user.name })}</h1>
        <p className="text-sm text-slate-500">{lastLogin ? t('dashboard.lastLogin', { when: lastLogin }) : t('dashboard.firstLogin')}</p>
      </div>

      <section className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center dark:border-slate-700 dark:bg-slate-900">
        <LayoutGrid aria-hidden className="mx-auto mb-3 size-8 text-slate-400" />
        <h2 className="font-medium">{t('dashboard.emptyTitle')}</h2>
        <p className="mx-auto mt-1 max-w-lg text-sm text-slate-500">{t('dashboard.emptyBody')}</p>
      </section>
    </div>
  )
}
