import { api } from '@/lib/api'
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
  custom?: Record<string, string | number | boolean | null>
}

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
