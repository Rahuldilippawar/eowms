import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatMoney } from '@/lib/format'
import { fetchVendors, saveVendor, type Vendor } from './api'
import { PurchasingNav } from './PurchasingNav'

const FIELDS = ['name', 'contact_person', 'phone', 'email', 'gstin'] as const

function VendorDialog({ vendor, onClose }: { vendor: Vendor | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [v, setV] = useState({
    name: vendor?.name ?? '',
    contact_person: vendor?.contact_person ?? '',
    phone: vendor?.phone ?? '',
    email: vendor?.email ?? '',
    gstin: vendor?.gstin ?? '',
    address: vendor?.address ?? '',
    is_active: vendor?.is_active ?? true,
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () =>
      saveVendor(vendor?.id ?? null, {
        name: v.name.trim(),
        contact_person: v.contact_person.trim() || null,
        phone: v.phone.trim() || null,
        email: v.email.trim() || null,
        gstin: v.gstin.trim() || null,
        address: v.address.trim() || null,
        ...(vendor ? { is_active: v.is_active } : {}),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['vendors'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => {
      const r = splitServerErrors(e, [...FIELDS, 'address'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={vendor ? t('purchasing.editVendor') : t('purchasing.newVendor')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={() => (v.name.trim() ? save.mutate() : setErrors({ name: t('form.required') }))}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        {FIELDS.map((k) => (
          <Field key={k} id={`vd-${k}`} label={t(`purchasing.vendor.${k}`)} error={errors[k]}>
            <Input id={`vd-${k}`} type={k === 'email' ? 'email' : k === 'phone' ? 'tel' : 'text'} maxLength={k === 'gstin' ? 15 : 255} value={v[k]} onChange={(e) => setV({ ...v, [k]: e.target.value })} />
          </Field>
        ))}
        <div className="sm:col-span-2">
          <Field id="vd-address" label={t('purchasing.vendor.address')} error={errors.address}>
            <Textarea id="vd-address" maxLength={1000} value={v.address} onChange={(e) => setV({ ...v, address: e.target.value })} />
          </Field>
        </div>
        {vendor ? (
          <label className="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" checked={v.is_active} onChange={(e) => setV({ ...v, is_active: e.target.checked })} />
            {t('purchasing.vendorActive')}
          </label>
        ) : null}
      </div>
    </Modal>
  )
}

export function VendorsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [search, setSearch] = useState('')
  const [inactive, setInactive] = useState(false)
  const [editing, setEditing] = useState<Vendor | 'new' | null>(null)
  const debounced = useDebouncedValue(search.trim(), 300)
  const q = useQuery({ queryKey: ['vendors', user?.current_organization?.id, debounced, inactive], queryFn: () => fetchVendors({ search: debounced, inactive }), placeholderData: keepPreviousData })

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('purchasing.vendorsTitle')}</h1>
        {q.data?.meta.can_manage ? (
          <Button onClick={() => setEditing('new')}>
            <Plus aria-hidden className="size-4" />
            {t('purchasing.newVendor')}
          </Button>
        ) : null}
      </div>
      <PurchasingNav />
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-48 flex-1">
          <Field id="vd-search" label={t('common.search')}>
            <Input id="vd-search" type="search" value={search} onChange={(e) => setSearch(e.target.value)} />
          </Field>
        </div>
        <label className="inline-flex min-h-10 items-center gap-2 text-sm">
          <input type="checkbox" checked={inactive} onChange={(e) => setInactive(e.target.checked)} />
          {t('store.showInactive')}
        </label>
      </div>
      <div className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.data.length === 0 ? <EmptyState message={t('purchasing.noVendors')} /> : null}
        <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
          {(q.data?.data ?? []).map((v) => (
            <li key={v.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
              <span className="min-w-0">
                <span className="block font-medium">
                  {v.name}
                  {!v.is_active ? <span className="ms-2 text-xs text-slate-500">{t('purchasing.inactive')}</span> : null}
                </span>
                <span className="block text-slate-500">{[v.contact_person, v.phone, v.gstin ? `GSTIN ${v.gstin}` : null].filter(Boolean).join(' · ')}</span>
              </span>
              <span className="flex items-center gap-3">
                <span className="text-slate-600">{t('purchasing.vendorOrders', { count: v.orders ?? 0, amount: formatMoney(v.ordered_amount ?? 0) })}</span>
                {q.data?.meta.can_manage ? (
                  <Button variant="secondary" aria-label={t('common.editNamed', { name: v.name })} onClick={() => setEditing(v)}>
                    {t('common.edit')}
                  </Button>
                ) : null}
              </span>
            </li>
          ))}
        </ul>
      </div>
      {editing ? <VendorDialog vendor={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </div>
  )
}
