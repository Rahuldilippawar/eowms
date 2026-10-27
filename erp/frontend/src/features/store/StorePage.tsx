import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Download, Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { cn } from '@/lib/utils'
import { fetchStoreItems, stockCsvUrl } from './api'
import { ItemDialog } from './StoreDialogs'

export function StorePage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('')
  const [low, setLow] = useState(false)
  const [inactive, setInactive] = useState(false)
  const [creating, setCreating] = useState(false)
  const debounced = useDebouncedValue(search.trim(), 300)
  const q = useQuery({
    queryKey: ['store-items', user?.current_organization?.id, debounced, category, low, inactive],
    queryFn: () => fetchStoreItems({ search: debounced, category, low, inactive }),
    placeholderData: keepPreviousData,
  })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.store')}</h1>
        <div className="flex flex-wrap items-center gap-3">
          {q.data?.meta.can_manage && !user?.disabled_modules?.includes('purchasing') ? (
            <Link to="/purchasing/orders" className="text-sm font-medium text-brand-700 hover:underline">
              {t('store.goodsToReceive')}
            </Link>
          ) : null}
          <Link to="/store/issues" className="text-sm font-medium text-brand-700 hover:underline">
            {t('store.issuesReport')}
          </Link>
          <a href={stockCsvUrl} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
            <Download aria-hidden className="size-4" />
            {t('attendance.downloadCsv')}
          </a>
          {q.data?.meta.can_manage ? (
            <Button onClick={() => setCreating(true)}>
              <Plus aria-hidden className="size-4" />
              {t('store.newItem')}
            </Button>
          ) : null}
        </div>
      </div>
      {q.data && q.data.meta.low_count > 0 ? (
        <button type="button" onClick={() => setLow(true)} className="w-full rounded-xl bg-amber-50 px-4 py-2 text-left text-sm font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">
          {t('store.lowBanner', { count: q.data.meta.low_count })}
        </button>
      ) : null}
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-48 flex-1">
          <Field id="st-search" label={t('common.search')}>
            <Input id="st-search" type="search" value={search} placeholder={t('store.searchHint')} onChange={(e) => setSearch(e.target.value)} />
          </Field>
        </div>
        <Field id="st-category" label={t('store.category')}>
          <Select id="st-category" value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">{t('store.allCategories')}</option>
            {(q.data?.meta.categories ?? []).map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </Select>
        </Field>
        <label className="inline-flex min-h-10 items-center gap-2 text-sm">
          <input type="checkbox" checked={low} onChange={(e) => setLow(e.target.checked)} />
          {t('store.lowOnly')}
        </label>
        <label className="inline-flex min-h-10 items-center gap-2 text-sm">
          <input type="checkbox" checked={inactive} onChange={(e) => setInactive(e.target.checked)} />
          {t('store.showInactive')}
        </label>
      </div>
      <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('store.noItems')} /> : null}
        {q.data && q.data.data.length ? (
          <table className="w-full text-sm">
            <thead className="text-left text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">{t('store.itemName')}</th>
                <th className="hidden px-3 py-2 font-medium sm:table-cell">{t('store.category')}</th>
                <th className="px-3 py-2 text-right font-medium">{t('store.balance')}</th>
                <th className="hidden px-3 py-2 text-right font-medium sm:table-cell">{t('store.reorderLevel')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {q.data.data.map((i) => (
                <tr key={i.id} className={cn(!i.is_active && 'opacity-60')}>
                  <td className="px-3 py-2">
                    <Link to={`/store/items/${i.id}`} className="font-medium text-brand-700 hover:underline">
                      {i.item_name}
                    </Link>
                    {i.department ? <span className="block text-xs text-slate-500">{i.department.name}</span> : null}
                  </td>
                  <td className="hidden px-3 py-2 sm:table-cell">{i.category ?? '—'}</td>
                  <td className={cn('px-3 py-2 text-right tabular-nums', i.low && 'font-semibold text-red-700')}>
                    {i.balance} {i.unit}
                  </td>
                  <td className="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{i.reorder_level || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>
      {creating ? <ItemDialog item={null} categories={q.data?.meta.categories ?? []} onClose={() => setCreating(false)} onSaved={(i) => navigate(`/store/items/${i.id}`)} /> : null}
    </div>
  )
}
