import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'

export interface AuditRow {
  id: number
  created_at: string
  user: { id: number; name: string } | null
  module: string
  action: string
  subject: { type: string; id: number | null } | null
  reason: string | null
  ip_address: string | null
  user_agent: string | null
  old_values?: Record<string, unknown> | null
  new_values?: Record<string, unknown> | null
}
export interface AuditFilters {
  user_id?: string
  module?: string
  action?: string
  from?: string
  to?: string
  page?: number
}
type Meta = Paginated<AuditRow>['meta'] & { can_export: boolean }
export type ActivityPage = { data: AuditRow[]; meta: Meta & { modules: string[] } }
export type LoginPage = { data: AuditRow[]; meta: Meta & { summary: { logins_7d: number; failed_7d: number; users_7d: number } } }

export const LOGIN_ACTIONS = ['login', 'login_failed', 'logout', 'password_changed', 'password_reset_requested', 'password_reset'] as const

const params = (f: AuditFilters) => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== undefined && v !== ''))

export const fetchActivity = async (f: AuditFilters) => (await api.get<ActivityPage>('/audit-logs', { params: params(f) })).data
export const fetchLogins = async (f: AuditFilters) => (await api.get<LoginPage>('/audit-logs/logins', { params: params(f) })).data
export const fetchAuditUsers = async () => (await api.get<{ data: { id: number; name: string }[] }>('/audit-logs/users')).data.data

export function auditCsvUrl(kind: 'activity' | 'logins', f: AuditFilters): string {
  const q = new URLSearchParams({ ...(params({ ...f, page: undefined }) as Record<string, string>), format: 'csv' })
  return `/api/v1/audit-logs${kind === 'logins' ? '/logins' : ''}?${q}`
}

