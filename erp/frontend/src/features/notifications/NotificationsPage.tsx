import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Pagination } from '@/components/data/Pagination'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/features/auth/AuthProvider'
import { cn } from '@/lib/utils'
import { fetchNotifications, markAllRead, markRead, type AppNotification } from './api'
import { notificationText } from './text'

export function NotificationsPage() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const orgKey = user?.current_organization?.id
  const [page, setPage] = useState(1)
  const [unreadOnly, setUnreadOnly] = useState(false)
  const q = useQuery({ queryKey: ['notifications', orgKey, page, unreadOnly], queryFn: () => fetchNotifications({ page, unread: unreadOnly }), placeholderData: keepPreviousData })
  const refresh = (unread: number) => {
    queryClient.setQueryData(['notifications-unread', orgKey], unread)
    void queryClient.invalidateQueries({ queryKey: ['notifications'] })
  }
  const all = useMutation({ mutationFn: markAllRead, onSuccess: refresh })
  const when = (iso: string) => new Intl.DateTimeFormat(i18n.language === 'mr' ? 'mr-IN' : 'en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))

  const open = async (n: AppNotification) => {
    if (!n.read_at) refresh(await markRead(n.id))
    if (n.link) navigate(n.link)
  }

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('notifications.title')}</h1>
        <div className="flex items-center gap-3">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={unreadOnly}
              onChange={(e) => {
                setUnreadOnly(e.target.checked)
                setPage(1)
              }}
            />
            {t('notifications.unreadOnly')}
          </label>
          <Button variant="secondary" disabled={all.isPending || !q.data?.unread} onClick={() => all.mutate()}>
            {t('notifications.markAll')}
          </Button>
        </div>
      </div>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.data.length === 0 ? <EmptyState message={t('notifications.none')} /> : null}
      {q.data && q.data.data.length > 0 ? (
        <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {q.data.data.map((n) => (
            <li key={n.id}>
              <button type="button" onClick={() => void open(n)} className="flex w-full gap-3 px-4 py-3 text-left hover:bg-slate-50 dark:hover:bg-slate-800">
                <span aria-hidden className={cn('mt-1.5 size-2 shrink-0 rounded-full', n.read_at ? 'bg-transparent' : 'bg-brand-600')} />
                <span className="min-w-0 flex-1">
                  <span className={cn('block text-sm', n.read_at ? 'text-slate-600 dark:text-slate-400' : 'font-medium')}>
                    {n.read_at ? null : <span className="sr-only">{t('notifications.unread')}: </span>}
                    {notificationText(n, t)}
                  </span>
                  <span className="block text-xs text-slate-500">{when(n.created_at)}</span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      {q.data ? <Pagination meta={q.data.meta} onPage={setPage} /> : null}
    </div>
  )
}
