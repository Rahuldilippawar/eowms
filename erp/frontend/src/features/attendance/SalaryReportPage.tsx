import { useQuery } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/features/auth/AuthProvider'
import { monthYm } from '@/lib/format'
import { fetchSalary, salaryCsvUrl } from './parityApi'

const money = (n: number | null, lang: string) => (n === null ? '—' : new Intl.NumberFormat(lang === 'mr' ? 'mr-IN' : 'en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 }).format(n))

export function SalaryReportPage() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const [month, setMonth] = useState(monthYm())
  const q = useQuery({ queryKey: ['salary-report', user?.current_organization?.id, month], queryFn: () => fetchSalary(month) })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('nav.salaryReport')}</h1>
      <div className="flex flex-wrap items-end gap-3">
        <Field id="sr-month" label={t('attendance.month')}>
          <Input id="sr-month" type="month" value={month} onChange={(e) => setMonth(e.target.value)} />
        </Field>
        <a href={salaryCsvUrl(month)} className="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
          <Download aria-hidden className="size-4" />
          {t('attendance.downloadCsv')}
        </a>
      </div>
      <p className="text-xs text-slate-500">{t('salary.hint')}</p>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.length === 0 ? <EmptyState message={t('people.noEmployees')} /> : null}
      {q.data && q.data.length > 0 ? (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <table className="w-full min-w-[48rem] text-left text-sm">
            <thead className="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800">
              <tr>
                <th className="px-3 py-2">{t('performance.employee')}</th>
                <th className="px-3 py-2 text-right">{t('salary.monthly')}</th>
                <th className="px-3 py-2 text-right">{t('salary.present')}</th>
                <th className="px-3 py-2 text-right">{t('salary.paidLeave')}</th>
                <th className="px-3 py-2 text-right">{t('salary.unpaid')}</th>
                <th className="px-3 py-2 text-right">{t('salary.payDays')}</th>
                <th className="px-3 py-2 text-right">{t('salary.pay')}</th>
              </tr>
            </thead>
            <tbody>
              {q.data.map((r) => (
                <tr key={r.employee.id} className="border-t border-slate-100 dark:border-slate-800">
                  <th scope="row" className="px-3 py-2 text-left font-medium">
                    {r.employee.name}
                    <span className="block text-xs font-normal text-slate-500">
                      {r.employee.employee_code}
                      {r.employee.department ? ` · ${r.employee.department}` : ''}
                    </span>
                  </th>
                  <td className="px-3 py-2 text-right tabular-nums">{money(r.monthly_salary, i18n.language)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{r.totals.present + r.totals.half_day / 2}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{r.totals.leave}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{r.unpaid_days}</td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {r.pay_days} / {r.days_in_month}
                  </td>
                  <td className="px-3 py-2 text-right font-semibold tabular-nums">{money(r.pay, i18n.language)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </div>
  )
}
