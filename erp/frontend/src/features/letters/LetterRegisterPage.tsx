import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { fetchRegister, registerCsvUrl } from './api'
import { LetterStatusBadge } from './LetterBits'

const fyLabel = (y: number) => `${y}-${String((y + 1) % 100).padStart(2, '0')}`

/** Outward and inward registers by financial year (April–March). */
export function LetterRegisterPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [type, setType] = useState<'outward' | 'inward'>('outward')
  const [fy, setFy] = useState<number | undefined>()
  const q = useQuery({ queryKey: ['letter-register', user?.current_organization?.id, type, fy], queryFn: () => fetchRegister({ type, fy }), placeholderData: keepPreviousData })
  const current = q.data?.meta.current_fy ?? new Date().getFullYear()
  const shown = q.data?.meta.fy ?? current

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('letters.registerTitle')}</h1>
        {can('letters.export') ? (
          <a href={registerCsvUrl({ type, fy: shown })} className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
            <Download aria-hidden className="size-4" />
            {t('attendance.downloadCsv')}
          </a>
        ) : null}
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <div role="tablist" aria-label={t('letters.registerTitle')} className="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm dark:bg-slate-800">
          {(['outward', 'inward'] as const).map((k) => (
            <button key={k} role="tab" aria-selected={type === k} onClick={() => setType(k)} className={cn('rounded-md px-3 py-1.5 font-medium', type === k ? 'bg-white shadow-sm dark:bg-slate-900' : 'text-slate-600 dark:text-slate-400')}>
              {t(`letters.register.${k}`)}
            </button>
          ))}
        </div>
        <Field id="lr-fy" label={t('letters.financialYear')}>
          <Select id="lr-fy" value={shown} onChange={(e) => setFy(Number(e.target.value))}>
            {[0, 1, 2, 3, 4].map((n) => (
              <option key={n} value={current - n}>
                {fyLabel(current - n)}
              </option>
            ))}
          </Select>
        </Field>
      </div>
      <section className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('letters.registerEmpty')} /> : null}
        {q.data && q.data.data.length > 0 ? (
          <table className="w-full min-w-[48rem] text-left text-sm">
            <thead className="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800">
              <tr>
                <th className="px-3 py-2">{t(`letters.register.${type}No`)}</th>
                <th className="px-3 py-2">{t('attendance.date')}</th>
                <th className="px-3 py-2">{t('letters.fileNumber')}</th>
                <th className="px-3 py-2">{t('letters.subject')}</th>
                <th className="px-3 py-2">{t('people.department')}</th>
                <th className="px-3 py-2">{t(type === 'outward' ? 'letters.sentTo' : 'letters.from')}</th>
                <th className="px-3 py-2">{t('attendance.statusLabel')}</th>
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((r) => (
                <tr key={`${r.number}-${r.letter_id}`} className="border-t border-slate-100 dark:border-slate-800">
                  <td className="px-3 py-2 font-mono text-xs">{r.number}</td>
                  <td className="px-3 py-2">{formatDate(r.date?.slice(0, 10))}</td>
                  <td className="px-3 py-2">
                    <Link to={`/letters/${r.letter_id}`} className="font-mono text-xs text-brand-700 hover:underline">
                      {r.file_number}
                    </Link>
                  </td>
                  <td className="px-3 py-2">{r.subject}</td>
                  <td className="px-3 py-2">{r.department ?? '—'}</td>
                  <td className="px-3 py-2">{r.party ?? '—'}</td>
                  <td className="px-3 py-2">
                    <LetterStatusBadge status={r.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </section>
    </div>
  )
}
