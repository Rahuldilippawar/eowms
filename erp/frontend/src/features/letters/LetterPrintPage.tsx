import { useQuery } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import QRCode from 'qrcode'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate, formatMoney, formatTime } from '@/lib/format'
import { fetchLetter } from './api'

function Qr({ value, label }: { value: string; label: string }) {
  const [src, setSrc] = useState<string | null>(null)
  useEffect(() => {
    void QRCode.toDataURL(value, { margin: 0, width: 140, errorCorrectionLevel: 'M' }).then(setSrc)
  }, [value])
  return src ? <img src={src} alt={label} className="size-24" /> : null
}

/**
 * The file as a printable page; "Print" → "Save as PDF" in the browser gives the PDF. Each signature
 * carries a QR code to the public verification page. (The browser renders Marathi correctly, which
 * server-side PDF libraries on shared hosting do not.)
 */
export function LetterPrintPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { id = '0' } = useParams()
  const q = useQuery({ queryKey: ['letter', Number(id)], queryFn: () => fetchLetter(Number(id)) })

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const l = q.data

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex items-center justify-between gap-3 print:hidden">
        <Link to={`/letters/${l.id}`} className="text-sm text-brand-700 hover:underline">
          {t('letters.backToFile')}
        </Link>
        <Button variant="secondary" onClick={() => window.print()}>
          <Printer aria-hidden className="size-4" />
          {t('letters.printOrPdf')}
        </Button>
      </div>
      <article className="space-y-5 rounded-2xl border border-slate-200 bg-white p-8 text-slate-900 print:border-0 print:p-0">
        <header className="border-b border-slate-300 pb-3 text-center">
          <p className="text-lg font-bold">{user?.current_organization?.name}</p>
          {l.department ? <p className="text-sm">{l.department.name}</p> : null}
        </header>
        <div className="flex flex-wrap justify-between gap-2 text-sm">
          <p>
            {t('letters.fileNumber')}: <span className="font-mono">{l.file_number}</span>
            {l.outward_no ? (
              <>
                <br />
                {t('letters.outwardShort')}: <span className="font-mono">{l.outward_no}</span>
              </>
            ) : null}
            {l.reference_no ? (
              <>
                <br />
                {t('letters.referenceNo')}: {l.reference_no}
              </>
            ) : null}
          </p>
          <p>
            {t('attendance.date')}: {formatDate((l.completed_at ?? l.created_at)?.slice(0, 10))}
          </p>
        </div>
        <h1 className="text-lg font-semibold">
          {t('letters.subject')}: {l.subject}
        </h1>
        {l.description ? <p className="leading-relaxed whitespace-pre-line">{l.description}</p> : null}
        {l.is_budget_expense ? (
          <p className="text-sm">
            {t('letters.requested')} {formatMoney(l.requested_amount)}
            {l.approved_amount !== null ? ` · ${t('letters.approved')} ${formatMoney(l.approved_amount)}` : ''}
          </p>
        ) : null}
        {l.attachments.length ? (
          <p className="text-sm">
            {t('letters.attachments')}: {l.attachments.map((a) => a.name).join(', ')}
          </p>
        ) : null}
        {l.signatures.length ? (
          <section className="flex flex-wrap justify-end gap-6 pt-6">
            {l.signatures.map((s) => (
              <div key={s.verification_code} className="flex items-end gap-3 text-sm">
                <Qr value={`${window.location.origin}/verify/signature/${s.verification_code}`} label={t('letters.qrLabel', { code: s.verification_code })} />
                <div>
                  <p className="font-semibold">{s.signer_name}</p>
                  {s.signer_designation ? <p>{s.signer_designation}</p> : null}
                  <p>
                    {t('letters.signedDigitally')} {formatDate(s.signed_at.slice(0, 10))} {formatTime(s.signed_at)}
                  </p>
                  <p className="font-mono text-xs">#{s.verification_code}</p>
                </div>
              </div>
            ))}
          </section>
        ) : (
          <p className="pt-6 text-right text-sm text-slate-500">{t('letters.notSignedYet')}</p>
        )}
      </article>
    </div>
  )
}
