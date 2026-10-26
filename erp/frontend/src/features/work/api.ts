import { api } from '@/lib/api'
import { csvForm, type ImportRow } from '@/features/people/profileApi'
import type { Paginated } from '@/lib/pagination'

export type WorkStatus = 'completed' | 'in_progress' | 'pending'

export interface WorkEntry {
  id: number
  employee?: { id: number; name: string; employee_code: string }
  work_date: string
  title: string
  description: string | null
  hours: number | null
  status: WorkStatus
  task: { id: number; code: string; title: string } | null
  reviewed_at: string | null
  reviewer: { id: number; name: string } | null
  review_remarks: string | null
  requested_by?: string | null
  estimated_cost?: number | null
  actual_cost?: number | null
  team?: Colleague[]
  has_before_photo?: boolean
  has_after_photo?: boolean
  is_imported?: boolean
  can_edit: boolean
  custom?: Record<string, string | number | boolean | null>
}

export interface WorkInput {
  work_date: string
  title: string
  description: string | null
  hours: number | null
  status: WorkStatus
  task_id: number | null
  requested_by: string | null
  estimated_cost: number | null
  actual_cost: number | null
  team_employee_ids: number[]
  custom?: Record<string, string | number | boolean | null>
}

export interface Colleague {
  id: number
  name: string
  employee_code: string
  department?: string | null
}

export type PhotoKind = 'before' | 'after'

export interface MyWork {
  data: WorkEntry[]
  meta: { edit_days: number; today: string }
}

export interface OpenTask {
  id: number
  code: string
  title: string
  status: string
  due_date: string
}

export interface TeamWorkParams {
  from: string
  to: string
  search?: string
  reviewed?: '0' | '1'
  page?: number
}

export const fetchMyWork = async (p: { from: string; to: string }): Promise<MyWork> => (await api.get('/me/work', { params: p })).data
export const fetchMyOpenTasks = async (): Promise<OpenTask[]> => (await api.get('/me/tasks/open')).data.data
export const saveWork = async (id: number | null, input: WorkInput): Promise<WorkEntry> =>
  id ? (await api.put(`/me/work/${id}`, input)).data.data : (await api.post('/me/work', input)).data.data
export const deleteWork = async (id: number) => (await api.delete(`/me/work/${id}`)).data

export const fetchTeamWork = async (p: TeamWorkParams): Promise<Paginated<WorkEntry>> => (await api.get('/work', { params: { ...p, search: p.search || undefined } })).data
export const reviewWork = async (id: number, remarks: string): Promise<WorkEntry> => (await api.post(`/work/${id}/review`, { remarks: remarks || undefined })).data.data
export const workCsvUrl = (p: { from: string; to: string; search?: string; reviewed?: string }) =>
  `/api/v1/work/export?${new URLSearchParams(Object.entries(p).filter(([, v]) => v) as [string, string][])}`

export const searchColleagues = async (search: string): Promise<Colleague[]> => (await api.get('/me/work/colleagues', { params: { search } })).data.data
export const workPhotoUrl = (id: number, kind: PhotoKind) => `/api/v1/work/${id}/photos/${kind}`
export const uploadWorkPhoto = async (id: number, kind: PhotoKind, file: File): Promise<WorkEntry> => {
  const form = new FormData()
  form.append('photo', file)
  return (await api.post(`/me/work/${id}/photos/${kind}`, form)).data.data
}
export const deleteWorkPhoto = async (id: number, kind: PhotoKind): Promise<WorkEntry> => (await api.delete(`/me/work/${id}/photos/${kind}`)).data.data

export const workImportTemplateUrl = '/api/v1/work/import/template'
export const previewWorkImport = async (file: File): Promise<{ rows: ImportRow[] }> => (await api.post('/work/import/preview', csvForm(file))).data.data
export const runWorkImport = async (file: File): Promise<{ imported: number }> => (await api.post('/work/import', csvForm(file))).data.data
