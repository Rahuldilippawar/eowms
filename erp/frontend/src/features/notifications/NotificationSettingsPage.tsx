import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BellOff, BellRing, Send } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { useToast } from '@/components/ui/Toast'
import { formatDateTime } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { currentPush, disablePush, enablePush, type PushState } from './push'
import { deletePushSubscription, fetchNotificationSettings, saveNotificationSettings, savePushSubscription, sendTestPush, type Channel, type NotificationSettings } from './settingsApi'

/** One's own push devices and which groups go by push and by e-mail (erp/docs/NOTIFICATIONS.md). */
export function NotificationSettingsPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['notification-settings'], queryFn: fetchNotificationSettings })
  const [push, setPush] = useState<{ state: PushState; endpoint: string | null } | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    void currentPush().then(setPush, () => setPush({ state: 'unsupported', endpoint: null }))
  }, [])
  const put = (data: NotificationSettings) => qc.setQueryData(['notification-settings'], data)
  const onError = (e: unknown) => toast.error(actionErrorMessage(e, t('common.actionFailed')))
  // The box turns at once; the saved settings replace it, or it turns back if saving fails.
  const save = useMutation({
    mutationFn: (patch: { push?: Record<string, boolean>; email?: Record<string, boolean> }) => saveNotificationSettings(patch),
    onMutate: (patch) => {
      const before = qc.getQueryData<NotificationSettings>(['notification-settings'])
      if (before) put({ ...before, push: { ...before.push, ...patch.push }, email: { ...before.email, ...patch.email } })
      return { before }
    },
    onSuccess: (d) => {
      put(d)
      toast.success(t('notifications.settings.saved'))
    },
    onError: (e, _patch, ctx) => {
      if (ctx?.before) put(ctx.before)
      onError(e)
    },
  })
  const test = useMutation({ mutationFn: () => sendTestPush(), onSuccess: () => toast.success(t('notifications.settings.pushTestSent')), onError })

  const turnOn = async () => {
    if (!q.data?.push_key) return
    setBusy(true)
    try {
      const sub = await enablePush(q.data.push_key)
      if (sub) put(await savePushSubscription(sub))
      setPush(await currentPush())
    } catch (e) {
      onError(e)
    } finally {
      setBusy(false)
    }
  }
  const turnOff = async () => {
    setBusy(true)
    try {
      const endpoint = await disablePush()
      if (endpoint) put(await deletePushSubscription(endpoint))
      setPush(await currentPush())
    } catch (e) {
      onError(e)
    } finally {
      setBusy(false)
    }
  }

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const s = q.data
  const pushMessage = !s.push_key ? 'pushNotConfigured' : push?.state === 'unsupported' ? 'pushUnsupported' : push?.state === 'blocked' ? 'pushBlocked' : null

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <h1 className="text-2xl font-semibold">{t('notifications.settings.title')}</h1>

      <section aria-labelledby="ns-push" className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div>
          <h2 id="ns-push" className="font-semibold">
            {t('notifications.settings.pushTitle')}
          </h2>
          <p className="text-sm text-slate-500">{t('notifications.settings.pushHint')}</p>
        </div>
        {pushMessage ? (
          <p role="status" className="text-sm text-amber-800 dark:text-amber-300">
            {t(`notifications.settings.${pushMessage}`)}
          </p>
        ) : push?.state === 'on' ? (
          <div className="flex flex-wrap items-center gap-3">
            <p role="status" className="text-sm font-medium text-green-700">
              {t('notifications.settings.pushOn')}
            </p>
            <Button variant="secondary" className="gap-2" disabled={test.isPending} onClick={() => test.mutate()}>
              <Send aria-hidden className="size-4" />
              {t('notifications.settings.pushTest')}
            </Button>
            <Button variant="ghost" className="gap-2" disabled={busy} onClick={() => void turnOff()}>
              <BellOff aria-hidden className="size-4" />
              {t('notifications.settings.pushDisable')}
            </Button>
          </div>
        ) : push ? (
          <Button className="gap-2" disabled={busy} onClick={() => void turnOn()}>
            <BellRing aria-hidden className="size-4" />
            {t('notifications.settings.pushEnable')}
          </Button>
        ) : null}
        {s.devices.length > 0 ? (
          <ul className="space-y-1 text-xs text-slate-500">
            {s.devices.map((d) => (
              <li key={d.id} className="truncate">
                {d.user_agent ?? '—'} · {formatDateTime(d.last_sent_at ?? d.created_at)}
              </li>
            ))}
          </ul>
        ) : null}
      </section>

      <section aria-labelledby="ns-groups" className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div>
          <h2 id="ns-groups" className="font-semibold">
            {t('notifications.settings.groupsTitle')}
          </h2>
          <p className="text-sm text-slate-500">{t('notifications.settings.groupsHint')}</p>
        </div>
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500">
              <th className="py-2 font-medium">
                <span className="sr-only">{t('notifications.title')}</span>
              </th>
              <th className="w-20 py-2 text-center font-medium">{t('notifications.settings.push')}</th>
              <th className="w-20 py-2 text-center font-medium">{t('notifications.settings.email')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {s.groups.map((g) => (
              <tr key={g}>
                <th scope="row" className="py-2 text-left font-normal">
                  {t(`notifications.settings.groups.${g}`)}
                </th>
                {(['push', 'email'] as Channel[]).map((c) => (
                  <td key={c} className="py-2 text-center">
                    <input
                      type="checkbox"
                      className="size-5"
                      aria-label={`${t(`notifications.settings.groups.${g}`)}: ${t(`notifications.settings.${c}`)}`}
                      checked={s[c][g]}
                      disabled={save.isPending}
                      onChange={(e) => save.mutate({ [c]: { [g]: e.target.checked } })}
                    />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </div>
  )
}
