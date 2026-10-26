import { api } from '@/lib/api'
import { csvForm, type ImportRow } from '@/features/people/profileApi'

export type RequestStatus = 'pending' | 'waitlisted' | 'approved' | 'rejected' | 'cancelled'
export type Condition = 'excellent' | 'good' | 'fair' | 'poor'

export interface RoomRow {
  id: number
  name: string
  location_code: string
  floor: number
  capacity: number | null
  building: { id: number; name: string } | null
  occupants_count: number
  staff: string[]
}
export interface Occupant {
  id: number
  occupant_type: 'staff' | 'family'
  name: string
  user: { id: number; name: string } | null
  family_of_id: number | null
  relation: string | null
  age: number | null
  moved_in_date: string | null
  status: 'active' | 'vacated'
  vacated_date: string | null
  vacated_remark: string | null
}
export interface Bill {
  id: number
  billing_month: string
  room_rent: number
  electricity_bill: number
  water_bill: number
  total_amount: number
  billing_unit_count: number
  shares: { id: number; occupant: string | null; amount_due: number; paid_at: string | null }[]
}
export interface RoomDetail {
  id: number
  name: string
  location_code: string
  floor: number
  capacity: number | null
  building: { id: number; name: string } | null
  occupants: Occupant[]
  items: { id: number; item_name: string; ownership: 'institution' | 'personal'; quantity: number; remarks: string | null }[]
  inspections: { id: number; inspection_date: string; condition_rating: Condition; remarks: string | null; inspected_by: string | null }[]
  bills: Bill[]
  guests: { id: number; guest_name: string; guest_contact: string | null; relation: string | null; purpose: string | null; host: string | null; arrival_date: string; expected_departure_date: string | null; actual_departure_date: string | null }[]
  transfers: { occupant: string | null; from: string | null; to: string | null; transfer_date: string; reason: string | null }[]
  can_manage: boolean
}
export interface AllotmentRequest {
  id: number
  requested_by: string | null
  requested_by_id: number
  preferred_building: string | null
  family_size: number
  reason: string | null
  status: RequestStatus
  allotted_room: string | null
  decided_by: string | null
  decided_at: string | null
  remarks: string | null
  created_at: string | null
}
export interface MyResidency {
  home: { room: string | null; building: string | null; moved_in_date: string | null; family: { id: number; name: string; relation: string | null; age: number | null }[] } | null
  bills: { id: number; billing_month: string | null; amount_due: number; bill_total: number; shared_by: number | null; paid_at: string | null }[]
  requests: AllotmentRequest[]
  buildings: { id: number; name: string }[]
}
export interface Directory {
  resident: { user_id: number; name: string; designation: string | null; phone: string | null; department: string | null; room: string | null; building: string | null; moved_in_date: string | null; family_count: number }[]
  non_resident: { user_id: number; name: string; designation: string | null; phone: string | null; department: string | null }[]
  unlinked: number
}
export interface Analytics {
  rooms: number
  occupied_rooms: number
  occupancy_rate: number
  occupants: number
  staff_housed: number
  avg_tenure_days: number | null
  waiting: number
  guests_now: number
  by_building: { id: number; name: string; rooms: number; occupied: number }[]
}
export interface ImportBatch {
  id: number
  filename: string | null
  buildings_created: number
  rooms_created: number
  occupants_created: number
  status: 'completed' | 'rolled_back'
  imported_by: string | null
  created_at: string | null
  rolled_back_at: string | null
}

export const fetchMyResidency = async (): Promise<MyResidency> => (await api.get('/my/residency')).data.data
export const requestHousing = async (input: { preferred_building_id: number | null; family_size: number; reason: string | null }): Promise<MyResidency> =>
  (await api.post('/my/residency/requests', input)).data.data
export const cancelHousingRequest = async (id: number): Promise<MyResidency> => (await api.post(`/my/residency/requests/${id}/cancel`)).data.data

export const fetchRooms = async (p: { building_id?: string; search?: string; vacant?: boolean }): Promise<{ data: RoomRow[]; meta: { can_manage: boolean; buildings: { id: number; name: string }[] } }> =>
  (await api.get('/residency/rooms', { params: { building_id: p.building_id || undefined, search: p.search || undefined, vacant: p.vacant ? 1 : undefined } })).data
export const fetchRoom = async (id: number): Promise<RoomDetail> => (await api.get(`/residency/rooms/${id}`)).data.data
export const addOccupant = async (
  roomId: number,
  input: { occupant_type: 'staff' | 'family'; user_id?: number; family_of_id?: number; name?: string; relation?: string | null; age?: number | null; moved_in_date?: string | null },
): Promise<RoomDetail> => (await api.post(`/residency/rooms/${roomId}/occupants`, input)).data.data
export const vacateOccupant = async (id: number, remark: string | null): Promise<RoomDetail> => (await api.post(`/residency/occupants/${id}/vacate`, { remark })).data.data
export const transferOccupant = async (id: number, toLocationId: number, reason: string | null): Promise<RoomDetail> =>
  (await api.post(`/residency/occupants/${id}/transfer`, { to_location_id: toLocationId, reason })).data.data
export const linkOccupant = async (id: number, userId: number) => (await api.post(`/residency/occupants/${id}/link`, { user_id: userId })).data.data
export const fetchUnlinked = async (): Promise<(Occupant & { room: string | null; building: string | null })[]> => (await api.get('/residency/unlinked')).data.data
export const addItem = async (roomId: number, input: { item_name: string; ownership: 'institution' | 'personal'; quantity: number; remarks: string | null }): Promise<RoomDetail> =>
  (await api.post(`/residency/rooms/${roomId}/items`, input)).data.data
export const removeItem = async (id: number): Promise<RoomDetail> => (await api.delete(`/residency/items/${id}`)).data.data
export const addInspection = async (roomId: number, input: { inspection_date: string; condition_rating: Condition; remarks: string | null }): Promise<RoomDetail> =>
  (await api.post(`/residency/rooms/${roomId}/inspections`, input)).data.data
export const createBill = async (roomId: number, input: { billing_month: string; room_rent: number; electricity_bill: number; water_bill: number }): Promise<RoomDetail> =>
  (await api.post(`/residency/rooms/${roomId}/bills`, input)).data.data
export const markSharePaid = async (shareId: number, paid: boolean): Promise<Bill> => (await api.post(`/residency/bill-shares/${shareId}/paid`, { paid })).data.data
export const fetchBillsReport = async (month: string): Promise<{ month: string; total: number; pending: number; bills: (Bill & { location_id: number; room: string | null; building: string | null })[] }> =>
  (await api.get('/residency/bills', { params: { month } })).data.data
export const addGuest = async (
  roomId: number,
  input: { guest_name: string; guest_contact: string | null; relation: string | null; purpose: string | null; host_occupant_id: number | null; arrival_date: string; expected_departure_date: string | null },
): Promise<RoomDetail> => (await api.post(`/residency/rooms/${roomId}/guests`, input)).data.data
export const guestLeft = async (id: number): Promise<RoomDetail> => (await api.post(`/residency/guests/${id}/left`, {})).data.data
export const fetchAllotmentRequests = async (status: string): Promise<AllotmentRequest[]> => (await api.get('/residency/requests', { params: { status } })).data.data
export const decideRequest = async (id: number, input: { decision: 'approve' | 'waitlist' | 'reject'; location_id?: number; remarks: string | null }): Promise<AllotmentRequest> =>
  (await api.post(`/residency/requests/${id}/decide`, input)).data.data
export const fetchDirectory = async (): Promise<Directory> => (await api.get('/residency/directory')).data.data
export const fetchAnalytics = async (): Promise<Analytics> => (await api.get('/residency/analytics')).data.data

export const residencyImportTemplateUrl = '/api/v1/residency/import/template'
export const previewResidencyImport = async (file: File): Promise<{ rows: ImportRow[] }> => (await api.post('/residency/import/preview', csvForm(file))).data.data
export const runResidencyImport = async (file: File): Promise<{ imported: number }> => (await api.post('/residency/import', csvForm(file))).data.data
export const fetchImportHistory = async (): Promise<ImportBatch[]> => (await api.get('/residency/import/history')).data.data
export const rollbackImport = async (id: number): Promise<ImportBatch[]> => (await api.post(`/residency/import/${id}/rollback`)).data.data
