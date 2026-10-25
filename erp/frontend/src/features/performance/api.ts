import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'

export interface PerformanceRow {
  employee: { id: number; name: string; employee_code: string; department: string | null }
  attendance: { present: number; late: number; half_day: number; absent: number; leave: number }
  work: { entries: number; hours: number; days_logged: number; reviewed: number }
  tasks: { assigned: number; completed: number; on_time: number; overdue: number; on_time_rate: number | null }
}

export interface Range {
  from: string
  to: string
}

export const fetchMyPerformance = async (p: Range): Promise<PerformanceRow> => (await api.get('/me/performance', { params: p })).data.data
export const fetchPerformance = async (p: Range & { search?: string; page?: number }): Promise<Paginated<PerformanceRow>> =>
  (await api.get('/performance', { params: { ...p, search: p.search || undefined } })).data
export const performanceCsvUrl = (p: Range) => `/api/v1/performance?${new URLSearchParams({ ...p, format: 'csv' })}`
