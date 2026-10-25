import { api } from '@/lib/api'

export type Period = 'today' | 'week' | 'month' | 'year' | 'custom'

export interface DashboardData {
  from: string
  to: string
  today: string
  kpis: Record<'employees' | 'present_today' | 'on_leave_today' | 'absent_today' | 'students' | 'pending_leave_approvals' | 'pending_corrections', number | null>
  charts: {
    attendance_trend: { date: string; present: number; late: number; absent: number; leave: number }[] | null
    by_department: { id: number | null; name: string | null; total: number }[] | null
    by_institution: { id: number | null; name: string | null; total: number }[] | null
  }
}

export const fetchDashboard = async (p: { period: Period; from?: string; to?: string; institution_id?: number; department_id?: number }): Promise<DashboardData> =>
  (await api.get('/dashboard', { params: p })).data.data
