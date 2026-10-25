import type { TFunction } from 'i18next'
import { formatDate } from '@/lib/format'
import type { AppNotification } from './api'

/** Notification text is rendered here, in the reader's language, from the stored type + data. */
export function notificationText(n: AppNotification, t: TFunction): string {
  const d = n.data ?? {}
  const params: Record<string, string> = {}
  for (const [k, v] of Object.entries(d)) params[k] = v === null || v === undefined ? '' : String(v)
  for (const k of ['due_date', 'start_date', 'end_date', 'work_date']) if (params[k]) params[k] = formatDate(params[k])
  let key = n.type.replace('.', '_')
  if ((n.type === 'leave.decided' || n.type === 'correction.decided') && params.status) key += `_${params.status}`
  return t(`notifications.types.${key}`, { ...params, defaultValue: t('notifications.generic') })
}
