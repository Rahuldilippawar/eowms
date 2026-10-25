import { api } from '@/lib/api'

export type AttendanceStatus = 'present' | 'late' | 'half_day' | 'absent' | 'leave'

export interface AttendanceRecord {
  id: number
  work_date: string
  check_in_at: string | null
  check_out_at: string | null
  status: AttendanceStatus
  source: string
  remarks: string | null
}

export interface MyEmployee {
  id: number
  name: string
  employee_code: string
  department: { id: number; name: string }
  is_active: boolean
}

export interface Correction {
  id: number
  work_date: string
  requested_status: AttendanceStatus
  requested_check_in: string | null
  requested_check_out: string | null
  reason: string
  status: 'pending' | 'approved' | 'rejected'
  decision_remarks: string | null
  employee?: { id: number; name: string; employee_code: string }
}

export interface DayRow {
  employee: { id: number; name: string; employee_code: string; department: string | null }
  record: (Omit<AttendanceRecord, 'work_date'> & { work_date?: string }) | null
}

export interface SummaryRow {
  id: number | null
  label: string | null
  code: string | null
  present: number
  late: number
  half_day: number
  absent: number
  leave: number
}

export const fetchMyEmployee = async (): Promise<MyEmployee | null> => (await api.get('/me/employee')).data.data
export const fetchMyAttendance = async (month: string): Promise<AttendanceRecord[]> => (await api.get('/me/attendance', { params: { month } })).data.data
export const checkIn = async (): Promise<AttendanceRecord> => (await api.post('/me/attendance/check-in')).data.data
export const checkOut = async (): Promise<AttendanceRecord> => (await api.post('/me/attendance/check-out')).data.data
export const fetchMyCorrections = async (): Promise<Correction[]> => (await api.get('/me/attendance/corrections')).data.data
export const requestCorrection = async (input: { work_date: string; requested_status: AttendanceStatus; requested_check_in: string | null; requested_check_out: string | null; reason: string }) =>
  (await api.post('/me/attendance/corrections', input)).data.data

export const fetchDay = async (p: { date: string; search?: string; page?: number }) =>
  (await api.get<{ date: string; data: DayRow[]; meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null } }>('/attendance', { params: { ...p, search: p.search || undefined } })).data
export const markAttendance = async (input: { employee_id: number; date: string; status: AttendanceStatus; check_in: string | null; check_out: string | null; remarks: string | null }) =>
  (await api.put('/attendance', input)).data.data
export const fetchSummary = async (p: { from: string; to: string; group_by: 'employee' | 'department' | 'institution' }): Promise<SummaryRow[]> =>
  (await api.get('/attendance/summary', { params: p })).data.data
export const summaryCsvUrl = (p: { from: string; to: string; group_by: string }) => `/api/v1/attendance/summary?${new URLSearchParams({ ...p, format: 'csv' })}`
export const fetchCorrections = async (): Promise<Correction[]> => (await api.get('/attendance/corrections')).data.data
export const decideCorrection = async (id: number, approve: boolean, remarks: string) => (await api.post(`/attendance/corrections/${id}/decide`, { approve, remarks: remarks || undefined })).data.data
