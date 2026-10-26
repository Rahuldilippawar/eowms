import { api } from '@/lib/api'
import { csvForm, type ImportRow } from '@/features/people/profileApi'
import type { RenewalStatus } from '@/features/renewals/api'

/** Same lists as the server (Vehicle::FUEL_TYPES, Vehicle::RENEWAL_FIELDS). */
export const FUEL_TYPES = ['petrol', 'diesel', 'cng', 'electric', 'other'] as const
export const DOCUMENT_FIELDS = ['permit', 'puc', 'insurance', 'fitness', 'road_tax', 'green_tax', 'mv_tax'] as const
export type FuelType = (typeof FUEL_TYPES)[number]
export type DocumentField = (typeof DOCUMENT_FIELDS)[number]
export type EntryType = 'trip' | 'fuel' | 'service'

export interface Vehicle {
  id: number
  vehicle_number: string
  vehicle_type: string | null
  model: string | null
  department: { id: number; name: string } | null
  driver: { id: number; name: string } | null
  fuel_type: FuelType
  current_odometer: number
  is_active: boolean
  documents_status: Exclude<RenewalStatus, 'inactive'> | null
  next_document: { field: DocumentField; expiry_date: string; days_left: number } | null
  next_service: { date: string | null; days_left: number | null; km: number | null; km_left: number | null; due: boolean } | null
}

export interface Trip {
  id: number
  type: 'trip'
  date: string
  from_location: string
  to_location: string
  start_time: string | null
  end_time: string | null
  start_odometer: number
  end_odometer: number
  distance_km: number
  purpose: string | null
  created_by: string | null
  created_by_id: number | null
}
export interface FuelFill {
  id: number
  type: 'fuel'
  date: string
  odometer_reading: number
  liters: number
  cost: number | null
  fuel_station: string | null
  created_by: string | null
  created_by_id: number | null
}
export interface Service {
  id: number
  type: 'service'
  date: string
  service_type: string
  description: string | null
  cost: number | null
  odometer_reading: number | null
  next_due_date: string | null
  next_due_km: number | null
  vendor: string | null
  created_by: string | null
  created_by_id: number | null
}
export type Entry = Trip | FuelFill | Service

export interface VehicleDetail extends Vehicle {
  chassis_no: string | null
  engine_no: string | null
  vehicle_class: string | null
  passenger_capacity: number | null
  registration_date: string | null
  documents: { field: DocumentField; renewal_id: number | null; expiry_date: string | null; days_left: number | null; status: RenewalStatus | null }[]
  stats: { total_km: number; total_liters: number; km_per_liter: number | null; fuel_cost: number; service_cost: number }
  trend: { month: string; km: number; liters: number }[]
  trips: Trip[]
  fuel: FuelFill[]
  services: Service[]
  /** "type:id" of entries with a correction request waiting. */
  pending_corrections: string[]
  can_manage: boolean
  can_log: boolean
}

export interface VehicleInput {
  department_id: number
  vehicle_number: string
  vehicle_type: string | null
  model: string | null
  registration_date: string | null
  chassis_no: string | null
  engine_no: string | null
  vehicle_class: string | null
  passenger_capacity: number | null
  driver_user_id: number | null
  fuel_type: FuelType
  current_odometer?: number | null
  documents: Partial<Record<DocumentField, string | null>>
}

export interface TripInput {
  trip_date: string
  from_location: string
  to_location: string
  start_time: string | null
  end_time: string | null
  start_odometer: number
  end_odometer: number
  purpose: string | null
}
export interface FuelInput {
  fill_date: string
  odometer_reading: number
  liters: number
  cost: number | null
  fuel_station: string | null
}
export interface ServiceInput {
  service_date: string
  type: string
  description: string | null
  cost: number | null
  odometer_reading: number | null
  next_due_date: string | null
  next_due_km: number | null
  vendor: string | null
}
export type EntryInput = TripInput | FuelInput | ServiceInput

export interface Correction {
  id: number
  vehicle: { id: number; vehicle_number: string }
  entry_type: EntryType
  entry_id: number
  request_type: 'edit' | 'delete'
  reason: string
  status: 'pending' | 'approved' | 'rejected'
  requested_by: string | null
  reviewed_by: string | null
  reviewed_at: string | null
  review_note: string | null
  created_at: string | null
  entry: Record<string, string | number | null> | null
  can_review: boolean
}

export interface VehicleReport {
  vehicle: { id: number; vehicle_number: string; model: string | null }
  from: string
  to: string
  totals: { km: number; liters: number; km_per_liter: number | null; fuel_cost: number; service_cost: number; trips: number }
  trips: Trip[]
  fuel: FuelFill[]
  services: Service[]
}

export const fetchVehicles = async (p: { search?: string; status?: string; department_id?: string }): Promise<{ data: Vehicle[]; meta: { can_create: boolean; can_export: boolean } }> =>
  (await api.get('/vehicles', { params: { search: p.search || undefined, status: p.status || undefined, department_id: p.department_id || undefined } })).data
export const vehiclesCsvUrl = '/api/v1/vehicles?format=csv&status=all'
export const fetchVehicle = async (id: number): Promise<VehicleDetail> => (await api.get(`/vehicles/${id}`)).data.data
export const saveVehicle = async (id: number | null, input: VehicleInput): Promise<VehicleDetail> =>
  id ? (await api.put(`/vehicles/${id}`, input)).data.data : (await api.post('/vehicles', input)).data.data
export const setVehicleActive = async (id: number, isActive: boolean): Promise<VehicleDetail> => (await api.post(`/vehicles/${id}/active`, { is_active: isActive })).data.data

const entryPath: Record<EntryType, string> = { trip: 'trips', fuel: 'fuel', service: 'services' }
export const addEntry = async (vehicleId: number, type: EntryType, input: EntryInput): Promise<VehicleDetail> => (await api.post(`/vehicles/${vehicleId}/${entryPath[type]}`, input)).data.data
export const updateEntry = async (vehicleId: number, type: EntryType, id: number, input: EntryInput): Promise<VehicleDetail> =>
  (await api.put(`/vehicles/${vehicleId}/entries/${type}/${id}`, input)).data.data
export const deleteEntry = async (vehicleId: number, type: EntryType, id: number): Promise<VehicleDetail> => (await api.delete(`/vehicles/${vehicleId}/entries/${type}/${id}`)).data.data
export const requestCorrection = async (vehicleId: number, type: EntryType, id: number, input: { request_type: 'edit' | 'delete'; reason: string }) =>
  (await api.post(`/vehicles/${vehicleId}/entries/${type}/${id}/corrections`, input)).data.data

export const fetchCorrections = async (status: string): Promise<Correction[]> => (await api.get('/vehicle-corrections', { params: { status } })).data.data
export const reviewCorrection = async (id: number, input: { decision: 'approve' | 'reject'; note: string | null; changes?: Record<string, string | number | null> }) =>
  (await api.post(`/vehicle-corrections/${id}/review`, input)).data.data

export const fetchVehicleReport = async (id: number, from: string, to: string): Promise<VehicleReport> => (await api.get(`/vehicles/${id}/report`, { params: { from, to } })).data.data
export const vehicleReportCsvUrl = (id: number, from: string, to: string) => `/api/v1/vehicles/${id}/report?format=csv&from=${from}&to=${to}`

export const vehicleImportTemplateUrl = '/api/v1/vehicles/import/template'
export const previewVehicleImport = async (file: File): Promise<{ rows: ImportRow[] }> => (await api.post('/vehicles/import/preview', csvForm(file))).data.data
export const runVehicleImport = async (file: File): Promise<{ imported: number }> => (await api.post('/vehicles/import', csvForm(file))).data.data
