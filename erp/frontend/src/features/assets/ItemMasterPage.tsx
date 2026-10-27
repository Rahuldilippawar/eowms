import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { CsvImport } from '@/components/data/CsvImport'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { cn } from '@/lib/utils'
import { fetchMasters, masterTemplateUrl, previewMasterImport, runMasterImport, saveMaster, type ItemMaster } from './api'
import { AssetsNav } from './AssetsNav'
import { DEAD_STOCK_LINKS } from './navLinks'

function MasterDialog({ master, onClose }: { master: ItemMaster | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [v, setV] = useState({ item_name: master?.item_name ?? '', category: master?.category ?? '', default_unit: master?.default_unit ?? '', is_active: master?.is_active ?? true })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const save = useMutation({
    mutationFn: () => saveMaster(master?.id ?? null, { item_name: v.item_name.trim(), category: v.category.trim() || null, default_unit: v.default_unit.trim() || null, ...(master ? { is_active: v.is_active } : {}) }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['asset-masters'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => setErrors(splitServerErrors(e, ['item_name', 'category', 'default_unit'], t('common.actionFailed')).byField),
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={master ? t('assets.editMaster') : t('assets.newMaster')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={() => (v.item_name.trim() ? save.mutate() : setErrors({ item_name: t('form.required') }))}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-3 sm:grid-cols-3">
        <Field id="im-name" label={t('assets.item')} error={errors.item_name}>
          <Input id="im-name" maxLength={255} value={v.item_name} onChange={(e) => setV({ ...v, item_name: e.target.value })} />
        </Field>
        <Field id="im-category" label={t('store.category')} error={errors.category}>
          <Input id="im-category" maxLength={100} value={v.category} onChange={(e) => setV({ ...v, category: e.target.value })} />
        </Field>
        <Field id="im-unit" label={t('assets.defaultUnit')} error={errors.default_unit}>
          <Input id="im-unit" maxLength={30} value={v.default_unit} onChange={(e) => setV({ ...v, default_unit: e.target.value })} />
        </Field>
        {master ? (
          <label className="inline-flex items-center gap-2 text-sm sm:col-span-3">
            <input type="checkbox" checked={v.is_active} onChange={(e) => setV({ ...v, is_active: e.target.checked })} />
            {t('store.inUse')}
          </label>
        ) : null}
      </div>
    </Modal>
  )
}

export function ItemMasterPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [inactive, setInactive] = useState(false)
  const [editing, setEditing] = useState<ItemMaster | 'new' | null>(null)
  const q = useQuery({ queryKey: ['asset-masters', user?.current_organization?.id, inactive], queryFn: () => fetchMasters(inactive) })
  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('assets.mastersTitle')}</h1>
        {q.data?.meta.can_manage ? (
          <div className="flex flex-wrap items-center gap-3">
            <Link to="/assets/masters/import" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
              <Upload aria-hidden className="size-4" />
              {t('assets.importMasters')}
            </Link>
            <Button onClick={() => setEditing('new')}>
              <Plus aria-hidden className="size-4" />
              {t('assets.newMaster')}
            </Button>
          </div>
        ) : null}
      </div>
      <AssetsNav links={DEAD_STOCK_LINKS} />
      <p className="text-sm text-slate-600">{t('assets.mastersHint')}</p>
      <label className="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" checked={inactive} onChange={(e) => setInactive(e.target.checked)} />
        {t('store.showInactive')}
      </label>
      <div className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('assets.noMasters')} /> : null}
        <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
          {(q.data?.data ?? []).map((m) => (
            <li key={m.id} className={cn('flex items-center justify-between gap-2 px-4 py-2', !m.is_active && 'opacity-60')}>
              <span>
                <span className="font-medium">{m.item_name}</span>
                <span className="block text-slate-500">{[m.category, m.default_unit, !m.is_active ? t('store.notInUse') : null].filter(Boolean).join(' · ')}</span>
              </span>
              {q.data?.meta.can_manage ? (
                <button type="button" className="font-medium text-brand-700 hover:underline" aria-label={t('common.editNamed', { name: m.item_name })} onClick={() => setEditing(m)}>
                  {t('common.edit')}
                </button>
              ) : null}
            </li>
          ))}
        </ul>
      </div>
      {editing ? <MasterDialog master={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </div>
  )
}

export function ItemMasterImportPage() {
  const { t } = useTranslation()
  return (
    <CsvImport
      title={t('assets.importMasters')}
      templateUrl={masterTemplateUrl}
      preview={previewMasterImport}
      run={runMasterImport}
      invalidate={['asset-masters']}
      doneLink={{ to: '/assets/masters', label: t('assets.mastersTitle') }}
      doneText={(count) => t('assets.mastersImported', { count })}
      columns={[
        { header: t('assets.item'), cell: (d) => String(d.item_name ?? '') },
        { header: t('store.category'), cell: (d) => String(d.category ?? '—') },
      ]}
    />
  )
}
