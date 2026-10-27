import { useQuery } from '@tanstack/react-query'
import { Plus, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchStoreItems } from '@/features/store/api'
import { formatMoney } from '@/lib/format'
import { lineAmount } from './api'
import { blankLine, type DraftLine } from './lines'

/** Lines of a quotation or an order. Store items are optional: a line can be a service ("Delivery"). */
export function LinesEditor({ lines, onChange, error }: { lines: DraftLine[]; onChange: (l: DraftLine[]) => void; error?: string }) {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const items = useQuery({ queryKey: ['store-items', user?.current_organization?.id, 'options'], queryFn: () => fetchStoreItems({}), enabled: can('store.view') })
  const set = (i: number, patch: Partial<DraftLine>) => onChange(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)))
  const total = lines.reduce((s, l) => s + lineAmount({ quantity: Number(l.quantity) || 0, rate: Number(l.rate) || 0, gst_percent: Number(l.gst_percent) || 0 }), 0)

  return (
    <fieldset className="space-y-2">
      <legend className="mb-1 text-sm font-medium">{t('purchasing.lines')}</legend>
      {error ? (
        <p role="alert" className="text-sm text-red-600">
          {error}
        </p>
      ) : null}
      {lines.map((l, i) => {
        const n = i + 1
        return (
          <div key={i} className="grid grid-cols-2 gap-2 rounded-xl border border-slate-200 p-2 sm:grid-cols-12 dark:border-slate-800">
            {can('store.view') ? (
              <Select
                aria-label={t('purchasing.lineItem', { n })}
                className="col-span-2 sm:col-span-3"
                value={l.item_id}
                onChange={(e) => {
                  const it = items.data?.data.find((x) => String(x.id) === e.target.value)
                  set(i, { item_id: e.target.value, unit: it?.unit ?? l.unit })
                }}
              >
                <option value="">{t('purchasing.notStoreItem')}</option>
                {(items.data?.data ?? []).map((x) => (
                  <option key={x.id} value={x.id}>
                    {x.item_name}
                  </option>
                ))}
              </Select>
            ) : null}
            <Input
              aria-label={t('purchasing.lineDescription', { n })}
              className={can('store.view') ? 'col-span-2 sm:col-span-3' : 'col-span-2 sm:col-span-6'}
              maxLength={255}
              placeholder={l.item_id ? t('purchasing.descriptionOptional') : t('purchasing.description')}
              value={l.description}
              onChange={(e) => set(i, { description: e.target.value })}
            />
            <Input aria-label={t('purchasing.lineQuantity', { n })} className="sm:col-span-1" type="number" inputMode="decimal" min={0} step="0.01" placeholder={t('purchasing.qty')} value={l.quantity} onChange={(e) => set(i, { quantity: e.target.value })} />
            <Input aria-label={t('purchasing.lineUnit', { n })} className="sm:col-span-1" maxLength={30} placeholder={t('store.unit')} value={l.unit} onChange={(e) => set(i, { unit: e.target.value })} />
            <Input aria-label={t('purchasing.lineRate', { n })} className="sm:col-span-2" type="number" inputMode="decimal" min={0} step="0.01" placeholder={t('purchasing.rate')} value={l.rate} onChange={(e) => set(i, { rate: e.target.value })} />
            <Input aria-label={t('purchasing.lineGst', { n })} className="sm:col-span-1" type="number" inputMode="decimal" min={0} max={100} step="0.01" placeholder="GST %" value={l.gst_percent} onChange={(e) => set(i, { gst_percent: e.target.value })} />
            <Button variant="ghost" className="sm:col-span-1" aria-label={t('purchasing.removeLine', { n })} disabled={lines.length === 1} onClick={() => onChange(lines.filter((_, j) => j !== i))}>
              <X aria-hidden className="size-4" />
            </Button>
          </div>
        )
      })}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Button variant="secondary" onClick={() => onChange([...lines, blankLine])}>
          <Plus aria-hidden className="size-4" />
          {t('purchasing.addLine')}
        </Button>
        <span className="text-sm font-semibold tabular-nums">{t('purchasing.totalWithGst', { total: formatMoney(total) })}</span>
      </div>
    </fieldset>
  )
}
