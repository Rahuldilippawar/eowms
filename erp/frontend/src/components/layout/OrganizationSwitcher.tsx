import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/features/auth/AuthProvider'

/** Shown only to users who belong to more than one organization. */
export function OrganizationSwitcher() {
  const { t } = useTranslation()
  const { user, switchOrganization } = useAuth()
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(false)

  if (!user || user.organizations.length < 2) return null

  const onChange = async (id: number) => {
    setBusy(true)
    setFailed(false)
    try {
      await switchOrganization(id)
    } catch {
      setFailed(true)
    } finally {
      setBusy(false)
    }
  }

  return (
    <label className="inline-flex min-w-0 items-center gap-2 text-sm">
      <span className="sr-only">{t('org.switch')}</span>
      <select
        value={user.current_organization?.id ?? ''}
        disabled={busy}
        aria-invalid={failed || undefined}
        title={failed ? t('org.switchFailed') : undefined}
        onChange={(e) => void onChange(Number(e.target.value))}
        className="min-h-9 max-w-[11rem] truncate rounded-lg border border-slate-300 bg-white px-2 py-1 text-sm aria-[invalid=true]:border-red-500 sm:max-w-xs dark:border-slate-700 dark:bg-slate-900"
      >
        {user.organizations.map((org) => (
          <option key={org.id} value={org.id}>
            {org.short_name ? `${org.short_name} — ${org.name}` : org.name}
          </option>
        ))}
      </select>
      {failed ? (
        <span role="alert" className="sr-only">
          {t('org.switchFailed')}
        </span>
      ) : null}
    </label>
  )
}
