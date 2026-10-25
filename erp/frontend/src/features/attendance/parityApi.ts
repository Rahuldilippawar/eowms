import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'

export type DayCode = 'P' | 'L' | 'H' | 'A' | 'LV' | 'WO' | 'HO' | '-' | null
export interface SheetTotals {
  present: number
  late: number
  half_day: number
  absent: number
  leave: number
  unpaid_leave: number
  weekly_off: number
  holiday: number
  unmarked: number
  working_days: number
}
export interface GridRow {
  employee: { id: number; name: string; employee_code: string }
  days: Record<string, DayCode>
  totals: SheetTotals
}
export const fetchGrid = async (p: { month: string; search?: string; page?: number }): Promise<Paginated<GridRow> & { from: string; to: string }> =>
  (await api.get('/attendance/grid', { params: { ...p, search: p.search || undefined } })).data
export const gridCsvUrl = (month: string) => `/api/v1/attendance/grid?${new URLSearchParams({ month, format: 'csv' })}`

export interface MovementRow {
  employee: { id: number; name: string; employee_code: string }
  work_date: string
  status: string
  source: string
  in: string | null
  out: string | null
  out_next_day: boolean
  hours: number | null
}
export const fetchMovement = async (p: { from: string; to: string; page?: number }): Promise<Paginated<MovementRow>> => (await api.get('/attendance/movement', { params: p })).data
export const movementCsvUrl = (p: { from: string; to: string }) => `/api/v1/attendance/movement?${new URLSearchParams({ ...p, format: 'csv' })}`

export interface Certificate {
  employee: { name: string; employee_code: string; designation: string | null; department: string | null; institution: string | null; joining_date: string | null }
  organization: string
  from: string
  to: string
  totals: SheetTotals
  issued_on: string
}
export const fetchCertificate = async (employeeId: number | 'me', p: { from: string; to: string }): Promise<Certificate> =>
  (await api.get(employeeId === 'me' ? '/me/attendance/certificate' : `/attendance/certificate/${employeeId}`, { params: p })).data.data

export interface Holiday {
  id: number
  holiday_date: string
  name: string
  institution: { id: number; name: string } | null
}
export const fetchHolidays = async (year: number): Promise<Holiday[]> => (await api.get('/holidays', { params: { year } })).data.data
export const saveHoliday = async (id: number | null, input: { holiday_date: string; name: string; institution_id: number | null }): Promise<Holiday> =>
  id ? (await api.put(`/holidays/${id}`, input)).data.data : (await api.post('/holidays', input)).data.data
export const deleteHoliday = async (id: number) => (await api.delete(`/holidays/${id}`)).data
export const holidayTemplateUrl = '/api/v1/holidays/import/template'
export async function importHolidays(file: File): Promise<{ created: number; skipped: number }> {
  const form = new FormData()
  form.append('file', file)
  return (await api.post('/holidays/import', form)).data.data
}

export interface AttendanceRulesSettings {
  timezone: string
  work_start: string
  work_end: string
  late_grace_minutes: number
  half_day_below_hours: number
  max_shift_hours: number
  weekly_offs: number[]
  daily_work_edit_days: number
}
export const fetchAttendanceRules = async (): Promise<AttendanceRulesSettings> => (await api.get('/organization/attendance-rules')).data.data
export const saveAttendanceRules = async (input: AttendanceRulesSettings): Promise<AttendanceRulesSettings> => (await api.put('/organization/attendance-rules', input)).data.data

export interface ShiftFields {
  work_start: string | null
  work_end: string | null
  late_grace_minutes: number | null
  half_day_below_hours: number | null
  weekly_offs: number[] | null
}
export interface EmployeeAttendanceSettings {
  shift: ShiftFields | null
  effective: { work_start: string; work_end: string; late_grace_minutes: number; half_day_below_hours: number; weekly_offs: number[] }
  biometric_pin: string | null
  can_edit: boolean
}
export const fetchEmployeeAttendance = async (id: number): Promise<EmployeeAttendanceSettings> => (await api.get(`/employees/${id}/attendance-settings`)).data.data
export const saveEmployeeAttendance = async (id: number, input: ShiftFields & { biometric_pin: string | null }): Promise<EmployeeAttendanceSettings> =>
  (await api.put(`/employees/${id}/attendance-settings`, input)).data.data

export interface Balance {
  leave_type: { id: number; code: string; name: string; is_paid: boolean }
  allocated: number | null
  used: number
  pending: number
  remaining: number | null
}
export const fetchMyBalances = async (year: number): Promise<Balance[]> => (await api.get('/me/leave/balances', { params: { year } })).data.data
export const fetchBalances = async (p: { year: number; search?: string; page?: number }): Promise<Paginated<{ employee: { id: number; name: string; employee_code: string }; balances: Balance[] }> & { can_allocate: boolean }> =>
  (await api.get('/leave-balances', { params: { ...p, search: p.search || undefined } })).data
export const allocateLeave = async (input: { employee_id: number; leave_type_id: number; year: number; days: number }) => (await api.put('/leave-balances', input)).data.data

export interface SalaryRow {
  employee: { id: number; name: string; employee_code: string; department: string | null }
  monthly_salary: number | null
  days_in_month: number
  pay_days: number
  unpaid_days: number
  totals: SheetTotals
  pay: number | null
}
export const fetchSalary = async (month: string): Promise<SalaryRow[]> => (await api.get('/reports/salary', { params: { month } })).data.data
export const salaryCsvUrl = (month: string) => `/api/v1/reports/salary?${new URLSearchParams({ month, format: 'csv' })}`

export interface Device {
  id: number
  name: string
  serial_number: string
  is_active: boolean
  institution: { id: number; name: string } | null
  last_seen_at: string | null
}
export const fetchDevices = async (): Promise<Device[]> => (await api.get('/biometric-devices')).data.data
export const saveDevice = async (id: number | null, input: { name: string; serial_number: string; institution_id: number | null; is_active: boolean }) =>
  id ? (await api.put(`/biometric-devices/${id}`, input)).data.data : (await api.post('/biometric-devices', input)).data.data
export const fetchPunches = async (result?: string): Promise<{ id: number; device: string | null; pin: string; punched_at: string; result: string; employee_id: number | null }[]> =>
  (await api.get('/biometric-punches', { params: { result: result || undefined } })).data.data
