import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import type { OrganizationInput } from './api'

export function OrganizationFields({
  value,
  onChange,
  errors,
  idPrefix,
}: {
  value: OrganizationInput
  onChange: (v: OrganizationInput) => void
  errors: Record<string, string>
  idPrefix: string
}) {
  const { t, i18n } = useTranslation()
  const [months, setMonths] = useState<string[]>([])
  useEffect(() => {
    const fmt = new Intl.DateTimeFormat(i18n.language === 'mr' ? 'mr-IN' : 'en-IN', { month: 'long' })
    setMonths(Array.from({ length: 12 }, (_, m) => fmt.format(new Date(2024, m, 1))))
  }, [i18n.language])

  const set = (k: keyof OrganizationInput) => (e: { target: { value: string } }) =>
    onChange({ ...value, [k]: k === 'financial_year_start_month' ? Number(e.target.value) : e.target.value })
  const id = (k: string) => `${idPrefix}-${k}`

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <div className="sm:col-span-2">
        <Field id={id('name')} label={t('org.name')} error={errors.name}>
          <Input id={id('name')} value={value.name} aria-invalid={!!errors.name || undefined} onChange={set('name')} />
        </Field>
      </div>
      <Field id={id('short')} label={t('org.shortName')} error={errors.short_name}>
        <Input id={id('short')} value={value.short_name ?? ''} onChange={set('short_name')} />
      </Field>
      <Field id={id('code')} label={t('org.code')} error={errors.code}>
        <Input id={id('code')} value={value.code} aria-invalid={!!errors.code || undefined} onChange={set('code')} />
      </Field>
      <Field id={id('email')} label={t('auth.email')} error={errors.email}>
        <Input id={id('email')} type="email" value={value.email ?? ''} onChange={set('email')} />
      </Field>
      <Field id={id('phone')} label={t('org.phone')} error={errors.phone}>
        <Input id={id('phone')} type="tel" value={value.phone ?? ''} onChange={set('phone')} />
      </Field>
      <Field id={id('website')} label={t('org.website')} error={errors.website}>
        <Input id={id('website')} type="url" placeholder="https://" value={value.website ?? ''} onChange={set('website')} />
      </Field>
      <Field id={id('fy')} label={t('org.fyStart')} error={errors.financial_year_start_month}>
        <Select id={id('fy')} value={value.financial_year_start_month} onChange={set('financial_year_start_month')}>
          {months.map((m, i) => (
            <option key={m} value={i + 1}>
              {m}
            </option>
          ))}
        </Select>
      </Field>
      <div className="sm:col-span-2">
        <Field id={id('address')} label={t('org.address')} error={errors.address}>
          <Input id={id('address')} value={value.address ?? ''} onChange={set('address')} />
        </Field>
      </div>
    </div>
  )
}
