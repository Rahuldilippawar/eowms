import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { LanguageSwitcher } from '@/components/layout/LanguageSwitcher'

/** Shared frame for the signed-out pages (sign in, forgot / reset password). */
export function AuthCard({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  const { t } = useTranslation()
  return (
    <div className="flex min-h-full flex-col items-center justify-center px-4 py-10">
      <div className="mb-6 flex w-full max-w-sm items-center justify-between">
        <div>
          <p className="text-xl font-bold text-brand-700">{t('app.name')}</p>
          <p className="text-sm text-slate-500">{t('app.tagline')}</p>
        </div>
        <LanguageSwitcher />
      </div>
      <main className="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h1 className="text-lg font-semibold">{title}</h1>
        {subtitle ? <p className="mb-5 text-sm text-slate-500">{subtitle}</p> : <div className="mb-5" />}
        {children}
      </main>
    </div>
  )
}
