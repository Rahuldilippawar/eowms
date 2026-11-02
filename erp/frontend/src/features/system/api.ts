import { api } from '@/lib/api'
import type { Backup, Confirmation } from '@/features/backup/api'

export const SYSTEM_CHECKS = ['debug_off', 'https', 'mail', 'push', 'scheduler', 'storage_writable', 'recent_backup'] as const
export interface SystemStatus {
  version: string
  php: string
  laravel: string
  database: string | null
  pending_migrations: string[]
  scheduler_last_run: string | null
  checks: Record<(typeof SYSTEM_CHECKS)[number], boolean>
  latest_backup: Backup | null
  app_url: string
  webauthn_rp_id: string
}

export const fetchSystem = async () => (await api.get<{ data: SystemStatus }>('/admin/system')).data.data
export const runMigrations = async (c: Confirmation) =>
  (await api.post<{ data: { ran: string[]; safety_backup: string | null; output?: string } }>('/admin/system/migrate', c)).data.data
