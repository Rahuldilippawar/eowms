import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, XCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { LanguageSwitcher } from '@/components/layout/LanguageSwitcher'
import { Spinner } from '@/components/ui/Spinner'
import { fetchPublicEmployee, publicPhotoUrl } from './profileApi'

/** Public page behind the QR code on ID cards — no login. */
export function VerifyEmployeePage() {
  const { t } = useTranslation()
  const { token = '' } = useParams()
  const q = useQuery({ queryKey: ['verify-employee', token], queryFn: () => fetchPublicEmployee(token), retry: false })

  return (
    <div className="flex min-h-full items-start justify-center bg-slate-50 p-4 sm:items-center dark:bg-slate-950">
      <div className="w-full max-w-sm space-y-4">
        <div className="flex items-center justify-between">
          <p className="text-lg font-bold text-brand-700">{t('app.name')}</p>
          <LanguageSwitcher />
        </div>
        <section className="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h1 className="mb-4 text-lg font-semibold">{t('verify.title')}</h1>
          {q.isPending ? <Spinner label={t('common.loading')} /> : null}
          {q.isError ? (
            <p role="alert" className="flex items-center justify-center gap-2 text-red-700">
              <XCircle aria-hidden className="size-5" />
              {t('verify.notFound')}
            </p>
          ) : null}
          {q.data ? (
            <div className="space-y-2">
              {q.data.has_photo ? <img src={publicPhotoUrl(token)} alt={q.data.name} className="mx-auto size-28 rounded-xl object-cover" /> : null}
              <p className="text-xl font-semibold">{q.data.name}</p>
              <p className="text-sm text-slate-600 dark:text-slate-400">{[q.data.designation, q.data.department].filter(Boolean).join(' · ')}</p>
              <p className="text-sm text-slate-600 dark:text-slate-400">{q.data.institution ?? q.data.organization}</p>
              <p className="font-mono text-xs text-slate-500">{q.data.employee_code}</p>
              <p role="status" className={`mt-3 inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium ${q.data.is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'}`}>
                {q.data.is_active ? <CheckCircle2 aria-hidden className="size-4" /> : <XCircle aria-hidden className="size-4" />}
                {q.data.is_active ? t('verify.active') : t('verify.inactive')}
              </p>
            </div>
          ) : null}
        </section>
      </div>
    </div>
  )
}
