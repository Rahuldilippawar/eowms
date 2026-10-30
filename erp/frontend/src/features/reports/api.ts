import { api } from '@/lib/api'

export interface WorkPrint {
  from: string
  to: string
  total: number
  truncated: boolean
  summary: { department: string | null; total: number; completed: number; in_progress: number; hours: number; completion: number }[]
  entries: {
    id: number
    work_date: string
    title: string
    description: string | null
    hours: number | null
    status: 'completed' | 'in_progress' | 'pending'
    reviewed: boolean
    employee: { name: string; code: string } | null
    department: string | null
    team: string[]
    materials: { item: string | null; unit: string | null; quantity: number }[]
  }[]
}
export const CAMPUS_METRICS = ['departments', 'employees', 'students', 'present_today', 'attendance_percent', 'work_entries', 'work_completion_percent', 'open_requests'] as const
export type CampusMetric = (typeof CAMPUS_METRICS)[number]
export interface CampusComparison {
  from: string
  to: string
  today: string
  metrics: string[]
  campuses: ({ id: number; name: string; code: string | null; type: string | null } & Partial<Record<CampusMetric | 'work_completed', number | null>>)[]
}

type Range = { from?: string; to?: string }
const clean = (p: Record<string, string | undefined>) => Object.fromEntries(Object.entries(p).filter(([, v]) => v))

export const fetchWorkPrint = async (p: Range & { status?: string }) => (await api.get<{ data: WorkPrint }>('/work/print', { params: clean(p) })).data.data
export const fetchCampusComparison = async (p: Range) => (await api.get<{ data: CampusComparison }>('/reports/campuses', { params: clean(p) })).data.data
export const campusCsvUrl = (p: Range) => `/api/v1/reports/campuses?${new URLSearchParams({ ...clean(p), format: 'csv' } as Record<string, string>)}`
