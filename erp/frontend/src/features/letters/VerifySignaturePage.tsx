import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, ShieldAlert, XCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { LanguageSwitcher } from '@/components/layout/LanguageSwitcher'
import { Spinner } from '@/components/ui/Spinner'
import { formatDate, formatTime } from '@/lib/format'
import { fetchPublicSignature } from './api'

/** Public page behind the QR code on a signed file — no login. */
export function VerifySignaturePage() {
  const { t } = useTranslation()
  const { code = '' } = useParams()
  const q = useQuery({ queryKey: ['verify-signature', code], queryFn: () => fetchPublicSignature(code), retry: false })

  return (
    <div className="flex min-h-full items-start justify-center bg-slate-50 p-4 sm:items-center dark:bg-slate-950">
      <div className="w-full max-w-sm space-y-4">
        <div className="flex items-center justify-between">
          <p className="text-lg font-bold text-brand-700">{t('app.name')}</p>
          <LanguageSwitcher />
        </div>
        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h1 className="mb-4 text-center text-lg font-semibold">{t('signatureCheck.title')}</h1>
          {q.isPending ? <Spinner label={t('common.loading')} /> : null}
          {q.isError ? (
            <p role="alert" className="flex items-center justify-center gap-2 text-red-700">
              <XCircle aria-hidden className="size-5" />
              {t('signatureCheck.notFound')}
            </p>
          ) : null}
          {q.data ? (
            <div className="space-y-3">
              <p role="status" className={`flex items-center justify-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium ${q.data.intact ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'}`}>
                {q.data.intact ? <CheckCircle2 aria-hidden className="size-4" /> : <ShieldAlert aria-hidden className="size-4" />}
                {q.data.intact ? t('signatureCheck.valid') : t('signatureCheck.changed')}
              </p>
              <dl className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                {[
                  [t('signatureCheck.signedBy'), [q.data.signer_name, q.data.signer_designation].filter(Boolean).join(', ')],
                  [t('signatureCheck.organization'), q.data.organization],
                  [t('letters.fileNumber'), [q.data.file_number, q.data.outward_no].filter(Boolean).join(' · ')],
                  [t('signatureCheck.when'), `${formatDate(q.data.signed_at.slice(0, 10))} ${formatTime(q.data.signed_at)}`],
                  [t('signatureCheck.method'), t(`signatureCheck.methods.${q.data.method}`, { defaultValue: q.data.method })],
                  [t('signatureCheck.code'), `#${code.toUpperCase()}`],
                ].map(([k, v]) => (
                  <div key={k} className="flex justify-between gap-3 py-2">
                    <dt className="text-slate-500">{k}</dt>
                    <dd className="text-right font-medium">{v}</dd>
                  </div>
                ))}
              </dl>
            </div>
          ) : null}
        </section>
      </div>
    </div>
  )
}
