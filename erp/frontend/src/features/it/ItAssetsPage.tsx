import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Download, Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { ASSET_STATUSES, ASSET_TYPES, fetchItAssets, fetchLabs, itAssetsCsvUrl } from './api'
import { AssetDialog } from './ItDialogs'
import { ItNav } from './ItNav'
import { StatusText } from './StatusText'

export function ItAssetsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [f, setF] = useState({ lab_id: params.get('lab_id') ?? '', asset_type: '', status: params.get('status') ?? '', search: '' })
  const [page, setPage] = useState(1)
  const [adding, setAdding] = useState(false)
  const search = useDebouncedValue(f.search.trim(), 300)
  const labs = useQuery({ queryKey: ['it-labs', user?.current_organization?.id], queryFn: fetchLabs })
  const q = useQuery({
    queryKey: ['it-assets', user?.current_organization?.id, f.lab_id, f.asset_type, f.status, search, page],
    queryFn: () => fetchItAssets({ ...f, search, page }),
    placeholderData: keepPreviousData,
  })
  const set = (patch: Partial<typeof f>) => {
    setF({ ...f, ...patch })
    setPage(1)
  }

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('it.assetsTitle')}</h1>
        <div className="flex flex-wrap items-center gap-3">
          <a href={itAssetsCsvUrl} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
            <Download aria-hidden className="size-4" />
            {t('attendance.downloadCsv')}
          </a>
          {q.data?.meta.can_manage || user?.it_lab_assistant ? (
            <Button onClick={() => setAdding(true)}>
              <Plus aria-hidden className="size-4" />
              {t('it.newAsset')}
            </Button>
          ) : null}
        </div>
      </div>
      <ItNav />
      <div className="flex flex-wrap items-end gap-3">
        <Field id="ia-lab" label={t('it.lab')}>
          <Select id="ia-lab" value={f.lab_id} onChange={(e) => set({ lab_id: e.target.value })}>
            <option value="">{t('it.allLabs')}</option>
            {(labs.data ?? []).map((l) => (
              <option key={l.id} value={l.id}>
                {l.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="ia-type" label={t('it.assetType')}>
          <Select id="ia-type" value={f.asset_type} onChange={(e) => set({ asset_type: e.target.value })}>
            <option value="">{t('it.allTypes')}</option>
            {ASSET_TYPES.map((x) => (
              <option key={x} value={x}>
                {t(`it.type.${x}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="ia-status" label={t('it.statusLabel')}>
          <Select id="ia-status" value={f.status} onChange={(e) => set({ status: e.target.value })}>
            <option value="">{t('it.anyStatus')}</option>
            {ASSET_STATUSES.map((x) => (
              <option key={x} value={x}>
                {t(`it.status.${x}`)}
              </option>
            ))}
          </Select>
        </Field>
        <div className="min-w-48 flex-1">
          <Field id="ia-search" label={t('common.search')}>
            <Input id="ia-search" type="search" placeholder={t('it.searchHint')} value={f.search} onChange={(e) => set({ search: e.target.value })} />
          </Field>
        </div>
      </div>
      <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('it.noAssets')} /> : null}
        {q.data && q.data.data.length ? (
          <table className="w-full text-sm">
            <thead className="text-left text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">{t('it.tag')}</th>
                <th className="px-3 py-2 font-medium">{t('it.assetType')}</th>
                <th className="hidden px-3 py-2 font-medium sm:table-cell">{t('it.lab')}</th>
                <th className="px-3 py-2 font-medium">{t('it.statusLabel')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {q.data.data.map((a) => (
                <tr key={a.id}>
                  <td className="px-3 py-2">
                    <Link to={`/it/assets/${a.id}`} className="font-medium text-brand-700 hover:underline">
                      {a.asset_tag}
                    </Link>
                    <span className="block text-xs text-slate-500">{[a.workstation_no, a.brand, a.model].filter(Boolean).join(' · ')}</span>
                  </td>
                  <td className="px-3 py-2">{t(`it.type.${a.asset_type}`)}</td>
                  <td className="hidden px-3 py-2 sm:table-cell">{a.lab?.name}</td>
                  <td className="px-3 py-2">
                    <StatusText status={a.status} />
                    {a.open_tickets ? <span className="block text-xs text-slate-500">{t('it.openCount', { count: a.open_tickets })}</span> : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>
      {q.data ? <Pagination meta={q.data.meta} onPage={setPage} /> : null}
      {adding ? <AssetDialog asset={null} defaultLab={f.lab_id} onClose={() => setAdding(false)} onSaved={(a) => navigate(`/it/assets/${a.id}`)} /> : null}
    </div>
  )
}
