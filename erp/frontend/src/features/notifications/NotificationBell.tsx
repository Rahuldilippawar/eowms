import * as Dropdown from '@radix-ui/react-dropdown-menu'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { useAuth } from '@/features/auth/AuthProvider'
import { cn } from '@/lib/utils'
import { fetchNotifications, fetchUnreadCount, markRead, type AppNotification } from './api'
import { notificationText } from './text'

/** No websockets on shared hosting: the count is polled once a minute and on window focus. */
const POLL_MS = 60_000

export function NotificationBell() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const orgKey = user?.current_organization?.id
  const count = useQuery({ queryKey: ['notifications-unread', orgKey], queryFn: fetchUnreadCount, refetchInterval: POLL_MS, enabled: !!orgKey })
  const latest = useQuery({ queryKey: ['notifications', orgKey, 'latest'], queryFn: () => fetchNotifications({ per_page: 8 }), enabled: false })
  const unread = count.data ?? 0

  const open = async (n: AppNotification) => {
    if (!n.read_at) {
      try {
        queryClient.setQueryData(['notifications-unread', orgKey], await markRead(n.id))
      } catch {
        // Opening the link matters more than the read mark.
      }
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    }
    if (n.link) navigate(n.link)
  }

  if (!orgKey) return null

  return (
    <Dropdown.Root onOpenChange={(o) => o && void latest.refetch()}>
      <Dropdown.Trigger asChild>
        <Button variant="ghost" className="relative px-2" aria-label={unread ? t('notifications.bellUnread', { count: unread }) : t('notifications.bell')}>
          <Bell aria-hidden className="size-5" />
          {unread ? (
            <span aria-hidden className="absolute -top-0.5 -right-0.5 min-w-5 rounded-full bg-red-600 px-1 text-center text-[11px] leading-5 font-semibold text-white">
              {unread > 99 ? '99+' : unread}
            </span>
          ) : null}
        </Button>
      </Dropdown.Trigger>
      <Dropdown.Portal>
        <Dropdown.Content align="end" sideOffset={6} className="z-50 w-80 max-w-[calc(100vw-1rem)] rounded-lg border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-700 dark:bg-slate-900">
          <div className="px-3 py-2 text-sm font-semibold">{t('notifications.title')}</div>
          <Dropdown.Separator className="my-1 h-px bg-slate-200 dark:bg-slate-700" />
          {latest.isFetching && !latest.data ? (
            <div className="flex justify-center py-6">
              <Spinner label={t('common.loading')} />
            </div>
          ) : (latest.data?.data.length ?? 0) === 0 ? (
            <p className="px-3 py-6 text-center text-sm text-slate-500">{t('notifications.none')}</p>
          ) : (
            latest.data?.data.map((n) => (
              <Dropdown.Item
                key={n.id}
                onSelect={() => void open(n)}
                className={cn(
                  'flex cursor-pointer gap-2 rounded-md px-3 py-2 text-sm outline-none data-[highlighted]:bg-slate-100 dark:data-[highlighted]:bg-slate-800',
                  n.read_at ? 'text-slate-500' : 'font-medium',
                )}
              >
                <span aria-hidden className={cn('mt-1.5 size-2 shrink-0 rounded-full', n.read_at ? 'bg-transparent' : 'bg-brand-600')} />
                <span>{notificationText(n, t)}</span>
              </Dropdown.Item>
            ))
          )}
          <Dropdown.Separator className="my-1 h-px bg-slate-200 dark:bg-slate-700" />
          <Dropdown.Item asChild>
            <Link to="/notifications" className="block rounded-md px-3 py-2 text-center text-sm text-brand-700 outline-none data-[highlighted]:bg-slate-100 dark:data-[highlighted]:bg-slate-800">
              {t('notifications.seeAll')}
            </Link>
          </Dropdown.Item>
        </Dropdown.Content>
      </Dropdown.Portal>
    </Dropdown.Root>
  )
}
