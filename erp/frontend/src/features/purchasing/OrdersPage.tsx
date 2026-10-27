import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatDate, formatMoney } from '@/lib/format'
import { fetchOrders } from './api'
import { OrderStatusBadge } from './OrderStatusBadge'
import { PurchasingNav } from './PurchasingNav'

export function OrdersPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [status, setStatus] = useState('open')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const debounced = useDebouncedValue(search.trim(), 300)
  const q = useQuery({
    queryKey: ['purchase-orders', user?.current_organization?.id, status, debounced, page],
    queryFn: () => fetchOrders({ status: status === 'all' ? undefined : status, search: debounced, page }),
    placeholderData: keepPreviousData,
  })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.purchasing')}</h1>
        {q.data?.meta.can_manage ? (
          <Link to="/purchasing/orders/new" className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-brand-700 px-4 text-sm font-medium text-white hover:bg-brand-800">
            <Plus aria-hidden className="size-4" />
            {t('purchasing.newOrder')}
          </Link>
        ) : null}
      </div>
      {can('purchasing.view') ? <PurchasingNav /> : null}
      <div className="flex flex-wrap items-end gap-3">
        <Field id="po-status" label={t('attendance.statusLabel')}>
          <Select
            id="po-status"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value)
              setPage(1)
            }}
          >
            {(['open', 'draft', 'issued', 'partly_received', 'received', 'cancelled', 'all'] as const).map((s) => (
              <option key={s} value={s}>
                {s === 'open' || s === 'all' ? t(`purchasing.filter.${s}`) : t(`purchasing.status.${s}`)}
              </option>
            ))}
          </Select>
        </Field>
        <div className="min-w-48 flex-1">
          <Field id="po-search" label={t('common.search')}>
            <Input
              id="po-search"
              type="search"
              placeholder={t('purchasing.searchHint')}
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
            />
          </Field>
        </div>
      </div>
      <div className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('purchasing.noOrders')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(q.data?.data ?? []).map((o) => (
            <li key={o.id}>
              <Link to={`/purchasing/orders/${o.id}`} className="flex flex-col gap-1 px-4 py-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-slate-800">
                <span className="min-w-0">
                  <span className="block font-medium">
                    {o.po_number ?? t('purchasing.draftOrder')} · {o.vendor_name}
                  </span>
                  <span className="block text-sm text-slate-500">{[o.department?.name, formatDate(o.order_date)].filter(Boolean).join(' · ')}</span>
                </span>
                <span className="flex items-center gap-3 text-sm">
                  <OrderStatusBadge status={o.status} />
                  <span className="font-semibold tabular-nums">{formatMoney(o.total_amount)}</span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </div>
      {q.data ? <Pagination meta={q.data.meta} onPage={setPage} /> : null}
    </div>
  )
}
