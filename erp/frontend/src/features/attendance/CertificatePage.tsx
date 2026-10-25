import { useQuery } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { parseApiError } from '@/lib/api'
import { formatDate, todayYmd } from '@/lib/format'
import { fetchCertificate } from './parityApi'

/** Printable attendance certificate (old staff/attendance-certificate.php). */
export function CertificatePage({ mine = false }: { mine?: boolean }) {
  const { t } = useTranslation()
  const params = useParams()
  const [search] = useSearchParams()
  const [from, setFrom] = useState(search.get('from') ?? `${todayYmd().slice(0, 4)}-01-01`)
  const [to, setTo] = useState(search.get('to') ?? todayYmd())
  const id = mine ? 'me' : Number(params.id)
  const q = useQuery({ queryKey: ['certificate', id, from, to], queryFn: () => fetchCertificate(id, { from, to }) })

  return (
    <div className="mx-auto max-w-3xl space-y-5">
      <div className="flex flex-wrap items-end gap-3 print:hidden">
        <Field id="c-from" label={t('leave.from')}>
          <Input id="c-from" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
        </Field>
        <Field id="c-to" label={t('leave.to')}>
          <Input id="c-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
        </Field>
        <Button variant="secondary" disabled={!q.data} onClick={() => window.print()}>
          <Printer aria-hidden className="size-4" />
          {t('idCards.print')}
        </Button>
      </div>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? parseApiError(q.error).status === 404 ? <EmptyState message={t('profile.notFound')} /> : <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data ? (
        <article className="space-y-5 rounded-2xl border border-slate-200 bg-white p-8 text-slate-900 print:border-0 print:p-0">
          <header className="text-center">
            <p className="text-lg font-bold">{q.data.organization}</p>
            {q.data.employee.institution ? <p className="text-sm">{q.data.employee.institution}</p> : null}
            <h1 className="mt-4 text-xl font-semibold underline">{t('certificate.title')}</h1>
          </header>
          <p className="leading-relaxed">
            {t('certificate.body', {
              name: q.data.employee.name,
              code: q.data.employee.employee_code,
              designation: q.data.employee.designation ?? '—',
              department: q.data.employee.department ?? '—',
              from: formatDate(q.data.from),
              to: formatDate(q.data.to),
            })}
          </p>
          <table className="w-full text-sm">
            <tbody>
              {(
                [
                  ['working_days', q.data.totals.working_days],
                  ['present', q.data.totals.present],
                  ['late', q.data.totals.late],
                  ['half_day', q.data.totals.half_day],
                  ['leave', q.data.totals.leave + q.data.totals.unpaid_leave],
                  ['absent', q.data.totals.absent],
                  ['unmarked', q.data.totals.unmarked],
                  ['holiday', q.data.totals.holiday],
                  ['weekly_off', q.data.totals.weekly_off],
                ] as const
              ).map(([k, n]) => (
                <tr key={k} className="border-b border-slate-200">
                  <th scope="row" className="py-1.5 text-left font-normal">
                    {t(`certificate.rows.${k}`)}
                  </th>
                  <td className="py-1.5 text-right font-semibold tabular-nums">{n}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <footer className="flex items-end justify-between pt-10 text-sm">
            <p>
              {t('certificate.issuedOn')}: {formatDate(q.data.issued_on)}
            </p>
            <p className="border-t border-slate-400 pt-1">{t('certificate.signature')}</p>
          </footer>
        </article>
      ) : null}
    </div>
  )
}
