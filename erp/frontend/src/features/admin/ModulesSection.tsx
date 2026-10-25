import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { CURRENT_USER_KEY, useAuth } from '@/features/auth/AuthProvider'
import { actionErrorMessage } from '@/lib/forms'
import { fetchModules, saveModules } from './settingsApi'

/** Switch modules on or off for the whole organization (hidden from menus, refused by the API). */
export function ModulesSection() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['org-modules', user?.current_organization?.id], queryFn: fetchModules })
  const [disabled, setDisabled] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    if (q.data) setDisabled(q.data.disabled)
  }, [q.data])

  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      await saveModules(disabled)
      await queryClient.invalidateQueries({ queryKey: CURRENT_USER_KEY })
      void queryClient.invalidateQueries({ queryKey: ['org-modules'] })
      toast.success(t('form.saved'))
    } catch (e) {
      setError(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section aria-labelledby="mod-title" className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
      <div>
        <h2 id="mod-title" className="font-semibold">
          {t('settings.modules')}
        </h2>
        <p className="text-sm text-slate-500">{t('settings.modulesHint')}</p>
      </div>
      {q.isPending ? <Spinner label={t('common.loading')} /> : null}
      <div className="grid gap-2 sm:grid-cols-2">
        {(q.data?.toggleable ?? []).map((m) => (
          <label key={m} className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={!disabled.includes(m)} onChange={(e) => setDisabled(e.target.checked ? disabled.filter((x) => x !== m) : [...disabled, m])} />
            {t(`modules.${m}`, m)}
          </label>
        ))}
      </div>
      {error ? (
        <p role="alert" className="text-sm text-red-600">
          {error}
        </p>
      ) : null}
      <Button disabled={busy || !q.data} onClick={() => void save()}>
        {busy ? <Spinner label={t('common.saving')} /> : t('common.save')}
      </Button>
    </section>
  )
}
