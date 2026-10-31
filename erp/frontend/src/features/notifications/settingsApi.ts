import { api } from '@/lib/api'

export type Channel = 'push' | 'email'
export interface NotificationSettings {
  locale: 'mr' | 'en'
  groups: string[]
  push: Record<string, boolean>
  email: Record<string, boolean>
  /** The server's VAPID public key; null while push is not set up there. */
  push_key: string | null
  devices: { id: number; user_agent: string | null; endpoint_hash: string; last_sent_at: string | null; created_at: string | null }[]
}

export const fetchNotificationSettings = async () => (await api.get<{ data: NotificationSettings }>('/me/notification-settings')).data.data
export const saveNotificationSettings = async (patch: Partial<Pick<NotificationSettings, 'locale' | 'push' | 'email'>>) =>
  (await api.put<{ data: NotificationSettings }>('/me/notification-settings', patch)).data.data
export const savePushSubscription = async (sub: PushSubscriptionJSON) =>
  (await api.post<{ data: NotificationSettings }>('/me/push-subscriptions', sub)).data.data
export const deletePushSubscription = async (endpoint: string) =>
  (await api.delete<{ data: NotificationSettings }>('/me/push-subscriptions', { data: { endpoint } })).data.data
export const sendTestPush = async () => (await api.post<{ data: { sent: number } }>('/me/push-subscriptions/test')).data.data
