import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'
import type { PaymentType } from '@/features/finance/api'

export const WORK_TYPES = ['installed', 'repaired', 'replaced', 'inspected'] as const
export type WorkType = (typeof WORK_TYPES)[number]

export interface Building {
  id: number
  name: string
  institution_id: number | null
  locations_count?: number
}

export interface Location {
  id: number
  name: string
  location_code: string
  floor: number
  capacity: number | null
  is_residential: boolean
  is_active: boolean
  institution_id: number | null
  building: { id: number; name: string } | null
}

export interface LocationRow extends Location {
  total_logs: number
  recent_issues: number
  last_work_date: string | null
}

export interface LogEntry {
  id: number
  location: { id: number; name?: string; location_code?: string; building?: string | null }
  department: { id: number; name: string } | null
  item_description: string
  work_type: WorkType
  work_date: string
  cost: number | null
  paid_amount: number
  due_amount: number
  payment_status: 'pending' | 'partial' | 'paid' | null
  remarks: string | null
  performed_by: string | null
}

export interface LogDetail extends LogEntry {
  payments: { id: number; amount: number; payment_date: string; payment_type: PaymentType; payment_mode: string | null; remarks: string | null; recorded_by: string | null }[]
  can_manage: boolean
}

export interface LogInput {
  location_id: number
  department_id: number
  item_description: string
  work_type: WorkType
  work_date: string
  cost: number | null
  remarks: string | null
}

export interface LocationsMeta {
  can_manage: boolean
  can_log: boolean
  can_export: boolean
  log_departments: { id: number; name: string }[]
}

export interface MaintenanceReport {
  from: string
  to: string
  by_department: { id: number; name: string; entries: number; cost: number; paid: number }[]
  by_location: { id: number; name: string; location_code: string; entries: number; cost: number }[]
  by_work_type: { work_type: WorkType; entries: number; cost: number }[]
}

export const fetchBuildings = async (): Promise<Building[]> => (await api.get('/maintenance/buildings')).data.data
export const saveBuilding = async (id: number | null, input: { name: string; institution_id: number | null }): Promise<Building> =>
  id ? (await api.put(`/maintenance/buildings/${id}`, input)).data.data : (await api.post('/maintenance/buildings', input)).data.data

export const fetchLocations = async (p: { building_id?: string; search?: string; include_inactive?: boolean }): Promise<{ data: LocationRow[]; meta: LocationsMeta }> =>
  (await api.get('/maintenance/locations', { params: { building_id: p.building_id || undefined, search: p.search || undefined, include_inactive: p.include_inactive ? 1 : undefined } })).data
export const saveLocation = async (
  id: number | null,
  input: { name: string; location_code: string; building_id: number | null; floor: number | null; capacity: number | null; is_residential: boolean; is_active: boolean },
): Promise<Location> => (id ? (await api.put(`/maintenance/locations/${id}`, input)).data.data : (await api.post('/maintenance/locations', input)).data.data)
export const fetchLocation = async (id: number): Promise<Location & { total_cost: number; logs: LogEntry[] }> => (await api.get(`/maintenance/locations/${id}`)).data.data

export const fetchLogs = async (p: { location_id?: string; department_id?: string; work_type?: string; from?: string; to?: string; search?: string; page?: number }): Promise<Paginated<LogEntry>> =>
  (await api.get('/maintenance/logs', { params: Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== undefined)) })).data
export const logsCsvUrl = '/api/v1/maintenance/logs?format=csv'
export const fetchLog = async (id: number): Promise<LogDetail> => (await api.get(`/maintenance/logs/${id}`)).data.data
export const saveLog = async (id: number | null, input: LogInput): Promise<LogDetail> =>
  id ? (await api.put(`/maintenance/logs/${id}`, input)).data.data : (await api.post('/maintenance/logs', input)).data.data
export const deleteLog = async (id: number) => (await api.delete(`/maintenance/logs/${id}`)).data
export const payLog = async (id: number, input: { amount: number; payment_date: string; payment_type: PaymentType; payment_mode: string | null; remarks: string | null }): Promise<LogDetail> =>
  (await api.post(`/maintenance/logs/${id}/payments`, input)).data.data

export const fetchRegisterDepartments = async (): Promise<{ id: number; name: string; code: string; has_maintenance_register: boolean }[]> => (await api.get('/maintenance/departments')).data.data
export const setRegisterDepartment = async (id: number, on: boolean) => (await api.put(`/maintenance/departments/${id}`, { has_maintenance_register: on })).data.data

export const fetchMaintenanceReport = async (from: string, to: string): Promise<MaintenanceReport> => (await api.get('/maintenance/report', { params: { from, to } })).data.data
