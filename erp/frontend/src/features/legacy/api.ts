import { api } from '@/lib/api'
import type { Confirmation } from '@/features/backup/api'

export interface StepReport {
  read: number
  created: number
  existing: number
  matched: number
  skipped: number
  skips: { table: string; id: number | null; reason: string }[]
  warnings: string[]
}
export interface ImportRun {
  id: number
  mode: 'dry_run' | 'import'
  status: 'done' | 'failed'
  error: string | null
  safety_backup: string | null
  started_at: string
  finished_at: string | null
  report?: Record<string, StepReport> | null
}
export interface ImporterState {
  source: { connected: boolean; error?: string; tables?: Record<string, number> }
  runs: ImportRun[]
}

export const fetchImporter = async () => (await api.get<{ data: ImporterState }>('/admin/legacy-import')).data.data
export const fetchRun = async (id: number) => (await api.get<{ data: ImportRun }>(`/admin/legacy-import/runs/${id}`)).data.data
export const startRun = async (mode: 'dry_run' | 'import', c?: Confirmation) =>
  (await api.post<{ data: ImportRun & { report: Record<string, StepReport> | null } }>('/admin/legacy-import', { mode, ...c })).data.data
