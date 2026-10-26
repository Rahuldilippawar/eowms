import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { allocateLeave, fetchBalances, fetchMyBalances, type Balance } from '@/features/attendance/parityApi'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { actionErrorMessage } from '@/lib/forms'

const show = (n: number | null, t: (k: string) => string) => (n === null ? t('balances.noLimit') : n)

/** Own balances on the leave page. */
export function MyBalances() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const year = new Date().getFullYear()
  const q = useQuery({ queryKey: ['my-leave-balances', user?.current_organization?.id, year], queryFn: () => fetchMyBalances(year) })
  if (!q.data?.length) return null
  return (
    <section aria-labelledby="bal-title" className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
      <h2 id="bal-title" className="mb-3 font-semibold">
        {t('balances.mine', { year })}
      </h2>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {q.data.map((b) => (
          <div key={b.leave_type.id} className="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
            <p className="text-sm text-slate-500">{b.leave_type.name}</p>
            <p className="text-2xl font-semibold tabular-nums">{show(b.remaining, t)}</p>
            <p className="text-xs text-slate-500">
              {b.allocated === null ? t('balances.usedOnly', { used: b.used }) : t('balances.detail', { allocated: b.allocated, used: b.used, pending: b.pending })}
            </p>
          </div>
        ))}
      </div>
    </section>
  )
}

function AllocateDialog({ target, year, onClose }: { target: { employeeId: number; name: string; balance: Balance } | null; year: number; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [days, setDays] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const save = async () => {
    if (!target) return
    const n = Number(days)
    if (days === '' || Number.isNaN(n) || n < 0 || n > 366) return setError(t('leave.daysRange'))
    setBusy(true)
    try {
      await allocateLeave({ employee_id: target.employeeId, leave_type_id: target.balance.leave_type.id, year, days: n })
      void queryClient.invalidateQueries({ queryKey: ['leave-balances'] })
      toast.success(t('form.saved'))
      onClose()
    } catch (e) {
      setError(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open={target !== null}
      onOpenChange={(o) => !o && onClose()}
      title={t('balances.allocateTitle')}
      description={target ? `${target.name} — ${target.balance.leave_type.name} (${year})` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={busy} onClick={() => void save()}>
            {busy ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <Field id="al-days" label={t('balances.daysGiven')} error={error ?? undefined}>
        <Input id="al-days" type="number" min={0} max={366} step="0.5" value={days} placeholder={target?.balance.allocated?.toString() ?? ''} onChange={(e) => setDays(e.target.value)} />
      </Field>
    </Modal>
  )
}

export function LeaveBalancesPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [year, setYear] = useState(new Date().getFullYear())
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [target, setTarget] = useState<{ employeeId: number; name: string; balance: Balance } | null>(null)
  const debounced = useDebouncedValue(search.trim())
  const q = useQuery({ queryKey: ['leave-balances', user?.current_organization?.id, year, debounced, page], queryFn: () => fetchBalances({ year, search: debounced, page }), placeholderData: keepPreviousData })
  const types = q.data?.data[0]?.balances.map((b) => b.leave_type) ?? []

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('nav.leaveBalances')}</h1>
      <div className="flex flex-wrap items-end gap-3">
        <Field id="lb-year" label={t('holidays.year')}>
          <Input id="lb-year" type="number" min={2000} max={2100} value={year} className="w-32" onChange={(e) => setYear(Number(e.target.value) || year)} />
        </Field>
        <Field id="lb-search" label={t('common.search')}>
          <Input
            id="lb-search"
            type="search"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
        </Field>
      </div>
      <p className="text-xs text-slate-500">{t('balances.hint')}</p>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.data.length === 0 ? <EmptyState message={t('people.noEmployees')} /> : null}
      {q.data && q.data.data.length > 0 ? (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <table className="w-full min-w-[40rem] text-left text-sm">
            <thead className="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800">
              <tr>
                <th className="px-3 py-2">{t('performance.employee')}</th>
                {types.map((lt) => (
                  <th key={lt.id} className="px-3 py-2 text-right">
                    {lt.code}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((r) => (
                <tr key={r.employee.id} className="border-t border-slate-100 dark:border-slate-800">
                  <th scope="row" className="px-3 py-2 text-left font-medium">
                    {r.employee.name} <span className="font-mono text-xs text-slate-500">{r.employee.employee_code}</span>
                  </th>
                  {r.balances.map((b) => (
                    <td key={b.leave_type.id} className="px-3 py-2 text-right tabular-nums">
                      {q.data.can_allocate ? (
                        <button
                          type="button"
                          className="text-brand-700 hover:underline"
                          aria-label={t('balances.allocateFor', { name: r.employee.name, type: b.leave_type.name })}
                          onClick={() => setTarget({ employeeId: r.employee.id, name: r.employee.name, balance: b })}
                        >
                          {show(b.remaining, t)}
                        </button>
                      ) : (
                        show(b.remaining, t)
                      )}
                      <span className="block text-[11px] text-slate-500">
                        {b.allocated === null ? `${b.used}` : `${b.used + b.pending}/${b.allocated}`}
                      </span>
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {q.data ? <Pagination meta={q.data.meta} onPage={setPage} /> : null}
      <AllocateDialog target={target} year={year} onClose={() => setTarget(null)} />
    </div>
  )
}
