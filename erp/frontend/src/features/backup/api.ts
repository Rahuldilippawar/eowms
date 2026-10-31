import { api } from '@/lib/api'

export const BACKUP_KINDS = ['manual', 'scheduled', 'uploaded', 'pre_restore', 'pre_clear'] as const
export interface Backup {
  name: string
  kind: (typeof BACKUP_KINDS)[number]
  created_at: string
  size: number
}
export interface Confirmation {
  password: string
  confirmation: string
}

export const fetchBackups = async () => (await api.get<{ data: Backup[]; meta: { keep_scheduled: number } }>('/backups')).data
export const createBackup = async () => (await api.post<{ data: { name: string } }>('/backups')).data.data
export const uploadBackup = async (file: File) => {
  const form = new FormData()
  form.append('file', file)
  return (await api.post<{ data: { name: string } }>('/backups/upload', form)).data.data
}
export const deleteBackup = async (name: string) => {
  await api.delete(`/backups/${encodeURIComponent(name)}`)
}
export const restoreBackup = async (name: string, c: Confirmation) =>
  (await api.post<{ data: { restored: string; safety_backup: string } }>(`/backups/${encodeURIComponent(name)}/restore`, c)).data.data
export const clearOrganization = async (c: Confirmation) =>
  (await api.post<{ data: { deleted: Record<string, number>; safety_backup: string } }>('/organization/clear', c)).data.data

export const backupDownloadUrl = (name: string) => `/api/v1/backups/${encodeURIComponent(name)}`
export const ORGANIZATION_EXPORT_URL = '/api/v1/organization/export'

/** Bytes as KB / MB. */
export function formatSize(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
