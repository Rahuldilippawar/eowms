import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatMoney } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import { allocateBudget, fetchBudgets, fyLabel, ledgerCsvUrl, type BudgetRow } from './api'

function AllocateDialog({ row, fy, onClose }: { row: BudgetRow; fy: number; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [amount, setAmount] = useState(row.allocated?.toString() ?? '')
  const [error, setError] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => allocateBudget({ department_id: row.department.id, fy, allocated_amount: Number(amount) }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['finance-budgets'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => setError(actionErrorMessage(e, t('common.actionFailed'))),
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t('finance.allocateTitle', { name: row.department.name, year: fyLabel(fy) })}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button
            disabled={save.isPending}
            onClick={() => {
              if (amount === '' || Number.isNaN(Number(amount)) || Number(amount) < 0) return setError(t('work.costRule'))
              save.mutate()
            }}
          >
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <Field id="fb-amount" label={t('finance.allocated')} error={error ?? undefined}>
        <Input id="fb-amount" type="number" inputMode="decimal" min={0} step="1" value={amount} onChange={(e) => setAmount(e.target.value)} />
      </Field>
    </Modal>
  )
}

function UsageBar({ percent }: { percent: number | null }) {
  const { t } = useTranslation()
  if (percent === null) return null
  return (
    <div className="h-2 w-full rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" aria-valuenow={Math.min(percent, 100)} aria-valuemin={0} aria-valuemax={100} aria-label={t('finance.used', { percent })}>
      <div className={cn('h-2 rounded-full', percent > 100 ? 'bg-red-600' : percent >= 80 ? 'bg-amber-500' : 'bg-emerald-600')} style={{ width: `${Math.min(percent, 100)}%` }} />
    </div>
  )
}

/** Department budgets for a financial year: given, spent and left, with where the money went. */
export function BudgetsPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [fy, setFy] = useState<number | undefined>()
  const [editing, setEditing] = useState<BudgetRow | null>(null)
  const q = useQuery({ queryKey: ['finance-budgets', user?.current_organization?.id, fy], queryFn: () => fetchBudgets(fy), placeholderData: keepPreviousData })
  const current = q.data?.meta.current_fy ?? new Date().getFullYear()
  const shown = q.data?.meta.fy ?? current

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.budgets')}</h1>
        {can('finance.export') ? (
          <a href={ledgerCsvUrl(shown)} className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
            <Download aria-hidden className="size-4" />
            {t('finance.ledgerCsv')}
          </a>
        ) : null}
      </div>
      <Field id="fb-fy" label={t('letters.financialYear')}>
        <Select id="fb-fy" value={shown} onChange={(e) => setFy(Number(e.target.value))}>
          {[1, 0, -1, -2, -3].map((n) => (
            <option key={n} value={current + n}>
              {fyLabel(current + n)}
            </option>
          ))}
        </Select>
      </Field>
      <p className="text-sm text-slate-600 dark:text-slate-400">{t('finance.budgetHint')}</p>
      <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('finance.noDepartments')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(q.data?.data ?? []).map((r) => (
            <li key={r.department.id} className="space-y-2 px-4 py-3">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                  {r.department.name} <span className="font-mono text-xs text-slate-500">{r.department.code}</span>
                </p>
                <div className="flex flex-wrap items-center gap-4 text-sm">
                  <span>
                    {t('finance.allocated')}: <strong>{r.allocated === null ? t('finance.notSet') : formatMoney(r.allocated)}</strong>
                  </span>
                  <span>
                    {t('finance.spent')}: <strong>{formatMoney(r.spent)}</strong>
                  </span>
                  {r.remaining !== null ? (
                    <span className={r.remaining < 0 ? 'font-semibold text-red-700' : ''}>
                      {r.remaining < 0 ? t('finance.over', { amount: formatMoney(-r.remaining) }) : `${t('finance.remaining')}: ${formatMoney(r.remaining)}`}
                    </span>
                  ) : null}
                  {q.data?.meta.can_allocate ? (
                    <Button variant="ghost" className="min-h-8 px-2 py-1 text-xs" aria-label={t('finance.setBudgetFor', { name: r.department.name })} onClick={() => setEditing(r)}>
                      {t('finance.setBudget')}
                    </Button>
                  ) : null}
                </div>
              </div>
              <UsageBar percent={r.percent_used} />
              <p className="text-xs text-slate-500">
                {Object.entries(r.by_source)
                  .map(([k, v]) => `${t(`finance.source.${k}`, { defaultValue: k })} ${formatMoney(v)}`)
                  .join(' · ') || t('finance.nothingSpent')}
                {r.purchases_billed > 0 ? ` · ${t('finance.purchasesPaid', { paid: formatMoney(r.purchases_paid), billed: formatMoney(r.purchases_billed) })}` : ''}
              </p>
            </li>
          ))}
        </ul>
      </section>
      {editing ? <AllocateDialog row={editing} fy={shown} onClose={() => setEditing(null)} /> : null}
    </div>
  )
}
