import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Pencil } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate, formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { fetchStoreItem, type MovementType, type StoreItemDetail } from './api'
import { ItemDialog, MoveDialog } from './StoreDialogs'

export function StoreItemPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const { id } = useParams()
  const key = ['store-item', user?.current_organization?.id, Number(id)]
  const q = useQuery({ queryKey: key, queryFn: () => fetchStoreItem(Number(id)) })
  const [moving, setMoving] = useState<MovementType | null>(null)
  const [editing, setEditing] = useState(false)
  const store = (i: StoreItemDetail) => queryClient.setQueryData(key, i)

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const i = q.data

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/store" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.store')}
      </Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{i.item_name}</h1>
          <p className="text-sm text-slate-500">
            {[i.category, i.department?.name ?? t('store.shared'), i.reorder_level ? t('store.reorderAt', { level: i.reorder_level, unit: i.unit }) : null, !i.is_active ? t('store.notInUse') : null]
              .filter(Boolean)
              .join(' · ')}
          </p>
        </div>
        {i.can_manage ? (
          <Button variant="secondary" onClick={() => setEditing(true)}>
            <Pencil aria-hidden className="size-4" />
            {t('common.edit')}
          </Button>
        ) : null}
      </div>
      <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <p className={cn('flex-1 text-2xl font-semibold tabular-nums', i.low && 'text-red-700')}>
          {i.balance} {i.unit}
          <span className="block text-xs font-normal text-slate-500">{i.low ? t('store.lowStock') : t('store.inStockLabel')}</span>
        </p>
        {i.can_manage && i.is_active ? (
          <div className="flex flex-wrap gap-2">
            {(['receive', 'issue', 'adjust'] as const).map((k) => (
              <Button key={k} variant={k === 'adjust' ? 'secondary' : 'primary'} onClick={() => setMoving(k)}>
                {t(`store.move.${k}`)}
              </Button>
            ))}
          </div>
        ) : null}
      </div>
      <section aria-labelledby="si-ledger" className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <h2 id="si-ledger" className="px-4 pt-4 font-semibold">
          {t('store.ledger')}
        </h2>
        {i.movements.length === 0 ? <EmptyState message={t('store.noMovements')} /> : null}
        <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
          {i.movements.map((m) => (
            <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
              <span className="min-w-0">
                <span className="font-medium">{formatDate(m.movement_date)}</span> <Badge tone={m.quantity > 0 ? 'green' : 'slate'}>{t(`store.type.${m.movement_type}`)}</Badge>
                {m.from_purchase ? <span className="ms-1 text-xs text-slate-500">{t('store.fromPurchase')}</span> : null}
                <span className="block text-slate-500">
                  {[m.department, m.issued_to, m.unit_cost !== null ? t('store.atCost', { cost: formatMoney(m.unit_cost) }) : null, m.remarks, m.by].filter(Boolean).join(' · ')}
                </span>
              </span>
              <span className="text-right tabular-nums">
                <span className={cn('block font-semibold', m.quantity > 0 ? 'text-emerald-700' : 'text-red-700')}>
                  {m.quantity > 0 ? '+' : ''}
                  {m.quantity}
                </span>
                <span className="block text-xs text-slate-500">{t('store.balanceAfter', { balance: m.balance })}</span>
              </span>
            </li>
          ))}
        </ul>
      </section>
      {moving ? (
        <MoveDialog
          item={i}
          type={moving}
          onClose={() => setMoving(null)}
          onSaved={(x) => {
            store(x)
            setMoving(null)
          }}
        />
      ) : null}
      {editing ? (
        <ItemDialog
          item={i}
          categories={[]}
          onClose={() => setEditing(false)}
          onSaved={(x) => {
            store(x)
            setEditing(false)
          }}
        />
      ) : null}
    </div>
  )
}
