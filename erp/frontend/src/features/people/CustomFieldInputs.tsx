import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchActiveFields, type CustomFieldDef, type CustomValues } from './profileApi'

/** Fields the organization added to this form; values are sent as `custom`. */
export function CustomFieldInputs({
  entity,
  values,
  onChange,
  errors,
  disabled,
}: {
  entity: 'employee' | 'student' | 'work_entry'
  values: CustomValues
  onChange: (v: CustomValues) => void
  errors?: Record<string, string>
  disabled?: boolean
}) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const fields = useQuery({ queryKey: ['custom-fields', user?.current_organization?.id, entity], queryFn: () => fetchActiveFields(entity), staleTime: 5 * 60_000 })
  if (!fields.data?.length) return null
  const set = (key: string, value: string | boolean | null) => onChange({ ...values, [key]: value })

  return (
    <>
      {fields.data.map((f: CustomFieldDef) => {
        const id = `cf-${entity}-${f.key}`
        const error = errors?.[`custom.${f.key}`]
        const raw = values[f.key]
        const label = f.is_required ? `${f.label} *` : f.label
        if (f.type === 'checkbox') {
          return (
            <label key={f.key} className="flex min-h-10 items-center gap-2 text-sm">
              <input type="checkbox" disabled={disabled} checked={!!raw} onChange={(e) => set(f.key, e.target.checked)} />
              {label}
              {error ? <span className="text-red-600">— {error}</span> : null}
            </label>
          )
        }
        return (
          <Field key={f.key} id={id} label={label} error={error}>
            {f.type === 'select' ? (
              <Select id={id} disabled={disabled} value={(raw as string) ?? ''} aria-invalid={!!error || undefined} onChange={(e) => set(f.key, e.target.value || null)}>
                <option value="">{t('form.choose')}</option>
                {(f.options ?? []).map((o) => (
                  <option key={o} value={o}>
                    {o}
                  </option>
                ))}
              </Select>
            ) : f.type === 'textarea' ? (
              <Textarea id={id} disabled={disabled} value={(raw as string) ?? ''} aria-invalid={!!error || undefined} onChange={(e) => set(f.key, e.target.value)} />
            ) : (
              <Input
                id={id}
                disabled={disabled}
                type={f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'}
                value={raw === null || raw === undefined ? '' : String(raw)}
                aria-invalid={!!error || undefined}
                onChange={(e) => set(f.key, e.target.value)}
              />
            )}
          </Field>
        )
      })}
    </>
  )
}
