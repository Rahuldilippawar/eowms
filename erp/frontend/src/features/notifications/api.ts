import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'

export interface AppNotification {
  id: number
  type: string
  data: Record<string, string | number | null>
  link: string | null
  read_at: string | null
  created_at: string
}

export type NotificationPage = Paginated<AppNotification> & { unread: number }

export const fetchNotifications = async (p: { page?: number; unread?: boolean; per_page?: number }): Promise<NotificationPage> =>
  (await api.get('/notifications', { params: { page: p.page, per_page: p.per_page, unread: p.unread ? 1 : undefined } })).data
export const fetchUnreadCount = async (): Promise<number> => (await api.get('/notifications/unread-count')).data.data.unread
export const markRead = async (id: number): Promise<number> => (await api.post(`/notifications/${id}/read`)).data.data.unread
export const markAllRead = async (): Promise<number> => (await api.post('/notifications/read-all')).data.data.unread
