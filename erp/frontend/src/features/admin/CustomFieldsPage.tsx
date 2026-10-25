import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { saveField, fetchAllFields, type FieldEntity, type ManagedField } from './settingsApi'
import { splitServerErrors } from './serverErrors'

const ENTITIES: FieldEntity[] = ['employee', 'student', 'work_entry']
const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'] as const

function FieldDialog({ open, onOpenChange, field, entity }: { open: boolean; onOpenChange: (o: boolean) => void; field?: ManagedField; entity: FieldEntity }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const init = () => ({
    label: field?.label ?? '',
    key: field?.key ?? '',
    type: field?.type ?? ('text' as ManagedField['type']),
    options: (field?.options ?? []).join('\n'),
    is_required: field?.is_required ?? false,
    is_active: field?.is_active ?? true,
    sort_order: field?.sort_order ?? 0,
  })
  const [v, setV] = useState(init)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    if (open) {
      setV(init())
      setErrors({})
      setGeneral(null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when the dialog opens
  }, [open, field?.id])

  const submit = async () => {
    const local: Record<string, string> = {}
    if (!v.label.trim()) local.label = t('form.required')
    if (!field && !/^[a-z][a-z0-9_]*$/.test(v.key)) local.key = t('settings.keyRule')
    const options = v.options
      .split('\n')
      .map((o) => o.trim())
      .filter(Boolean)
    if (v.type === 'select' && options.length === 0) local.options = t('form.required')
    setErrors(local)
    if (Object.keys(local).length) return
    setBusy(true)
    try {
      await saveField(field?.id ?? null, {
        entity,
        key: v.key,
        label: v.label.trim(),
        type: v.type,
        options: v.type === 'select' ? options : null,
        is_required: v.is_required,
        is_active: v.is_active,
        sort_order: Number(v.sort_order) || 0,
      })
      void queryClient.invalidateQueries({ queryKey: ['custom-fields'] })
      void queryClient.invalidateQueries({ queryKey: ['custom-fields-all'] })
      toast.success(t('form.saved'))
      onOpenChange(false)
    } catch (e) {
      const r = splitServerErrors(e, ['label', 'key', 'type', 'options'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title={field ? t('settings.editField') : t('settings.newField')}
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button disabled={busy} onClick={() => void submit()}>
            {busy ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <Field id="cf-label" label={t('settings.label')} error={errors.label}>
          <Input id="cf-label" value={v.label} onChange={(e) => setV({ ...v, label: e.target.value })} />
        </Field>
        <Field id="cf-key" label={t('settings.key')} error={errors.key}>
          <Input id="cf-key" value={v.key} disabled={!!field} className="font-mono" onChange={(e) => setV({ ...v, key: e.target.value })} />
        </Field>
        <Field id="cf-type" label={t('settings.type')} error={errors.type}>
          <Select id="cf-type" value={v.type} disabled={!!field} onChange={(e) => setV({ ...v, type: e.target.value as ManagedField['type'] })}>
            {TYPES.map((x) => (
              <option key={x} value={x}>
                {t(`settings.types.${x}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="cf-order" label={t('settings.order')}>
          <Input id="cf-order" type="number" min={0} value={v.sort_order} onChange={(e) => setV({ ...v, sort_order: Number(e.target.value) })} />
        </Field>
        {v.type === 'select' ? (
          <div className="sm:col-span-2">
            <Field id="cf-options" label={t('settings.options')} error={errors.options}>
              <textarea id="cf-options" rows={4} className="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" value={v.options} onChange={(e) => setV({ ...v, options: e.target.value })} />
            </Field>
          </div>
        ) : null}
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={v.is_required} onChange={(e) => setV({ ...v, is_required: e.target.checked })} />
          {t('settings.required')}
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={v.is_active} onChange={(e) => setV({ ...v, is_active: e.target.checked })} />
          {t('org.active')}
        </label>
      </div>
    </Modal>
  )
}

export function CustomFieldsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['custom-fields-all', user?.current_organization?.id], queryFn: fetchAllFields })
  const [entity, setEntity] = useState<FieldEntity>('employee')
  const [editing, setEditing] = useState<ManagedField | undefined>()
  const [open, setOpen] = useState(false)
  const rows = (q.data ?? []).filter((f) => f.entity === entity)

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.customFields')}</h1>
        <Button
          onClick={() => {
            setEditing(undefined)
            setOpen(true)
          }}
        >
          <Plus aria-hidden className="size-4" />
          {t('settings.newField')}
        </Button>
      </div>
      <p className="text-sm text-slate-500">{t('settings.fieldsHint')}</p>
      <Field id="cf-entity" label={t('settings.form')}>
        <Select id="cf-entity" value={entity} onChange={(e) => setEntity(e.target.value as FieldEntity)}>
          {ENTITIES.map((x) => (
            <option key={x} value={x}>
              {t(`settings.entities.${x}`)}
            </option>
          ))}
        </Select>
      </Field>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && rows.length === 0 ? <EmptyState message={t('settings.noFields')} /> : null}
      {rows.length ? (
        <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {rows.map((f) => (
            <li key={f.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
              <div>
                <p className="font-medium">
                  {f.label} {f.is_required ? <span className="text-xs text-red-700">*</span> : null}
                </p>
                <p className="text-xs text-slate-500">
                  <span className="font-mono">{f.key}</span> · {t(`settings.types.${f.type}`)}
                  {f.options?.length ? ` · ${f.options.join(', ')}` : ''}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge tone={f.is_active ? 'green' : 'slate'}>{f.is_active ? t('org.active') : t('org.inactive')}</Badge>
                <Button
                  variant="ghost"
                  className="min-h-8 px-2 py-1 text-xs"
                  aria-label={t('common.editNamed', { name: f.label })}
                  onClick={() => {
                    setEditing(f)
                    setOpen(true)
                  }}
                >
                  {t('common.edit')}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      ) : null}
      <FieldDialog open={open} onOpenChange={setOpen} field={editing} entity={editing?.entity ?? entity} />
    </div>
  )
}
