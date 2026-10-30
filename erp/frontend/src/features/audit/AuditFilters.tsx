import { useQuery } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchAuditUsers, type AuditFilters as Filters } from './api'

/** Who / from / to (shared by both log pages) plus page-specific filters; the CSV link when allowed. */
export function AuditFilters({ id, value, onChange, csvUrl, children }: { id: string; value: Filters; onChange: (f: Filters) => void; csvUrl?: string; children?: ReactNode }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const users = useQuery({ queryKey: ['audit-users', user?.current_organization?.id], queryFn: fetchAuditUsers })
  const set = (patch: Filters) => onChange({ ...value, ...patch, page: 1 })
  return (
    <div className="flex flex-wrap items-end gap-3">
      <div className="w-full sm:w-56">
        <Field id={`${id}-user`} label={t('audit.who')}>
          <Select id={`${id}-user`} value={value.user_id ?? ''} onChange={(e) => set({ user_id: e.target.value })}>
            <option value="">{t('audit.everyone')}</option>
            {users.data?.map((u) => (
              <option key={u.id} value={u.id}>
                {u.name}
              </option>
            ))}
          </Select>
        </Field>
      </div>
      {children}
      <Field id={`${id}-from`} label={t('audit.from')}>
        <Input id={`${id}-from`} type="date" value={value.from ?? ''} onChange={(e) => set({ from: e.target.value })} />
      </Field>
      <Field id={`${id}-to`} label={t('audit.to')}>
        <Input id={`${id}-to`} type="date" value={value.to ?? ''} min={value.from} onChange={(e) => set({ to: e.target.value })} />
      </Field>
      {csvUrl ? (
        <a href={csvUrl} className="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
          <Download aria-hidden className="size-4" />
          {t('attendance.downloadCsv')}
        </a>
      ) : null}
    </div>
  )
}
