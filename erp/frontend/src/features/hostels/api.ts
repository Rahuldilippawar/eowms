import { api } from '@/lib/api'
import { csvForm, type ImportRow } from '@/features/people/profileApi'

export const SESSIONS = ['morning', 'afternoon', 'evening'] as const
export type Session = (typeof SESSIONS)[number]
export type HostelGender = 'boys' | 'girls' | 'mixed'
export type RollStatus = 'present' | 'absent' | 'on_leave'
export type PaymentMode = 'cash' | 'online' | 'cheque' | 'other'

export interface Hostel {
  id: number
  name: string
  gender: HostelGender
  capacity: number | null
  is_active: boolean
  department: { id: number; name: string } | null
}
export interface HostelRow extends Hostel {
  residents_count: number
  beds: number
  out_now: number
  overdue_returns: number
}
export interface HostelDetail extends Hostel {
  staff: { user_id: number; name: string | null; role: string }[]
  rooms: { id: number; room_number: string; capacity: number; occupied: number; is_active: boolean }[]
  stats: {
    residents: number
    beds: number
    occupied_beds: number
    out_now: number
    overdue_returns: number
    fees_due: number
    today: Record<Session, { present: number; absent: number; on_leave: number } | null>
  }
  can_manage: boolean
  can_care: boolean
}

export interface Resident {
  id: number
  name: string
  school_name: string | null
  class_grade: string | null
  district: string | null
  gender: 'male' | 'female' | null
  admission_date: string | null
  guardian_name: string | null
  guardian_phone: string | null
  is_active: boolean
  room: { room_id: number; room_number: string | null; bed_label: string | null } | null
  out: { since: string; expected_return_date: string | null; overdue: boolean } | null
}
export interface ResidentInput {
  name: string
  school_name: string | null
  class_grade: string | null
  district: string | null
  gender: 'male' | 'female' | null
  date_of_birth: string | null
  admission_date: string | null
  guardian_name: string | null
  guardian_phone: string | null
}
export interface HealthProfile {
  blood_group: string | null
  allergies: string | null
  regular_medication: string | null
  emergency_contact_name: string | null
  emergency_contact_phone: string | null
  medical_notes: string | null
}
export interface ResidentDetail extends Resident {
  student_id: number | null
  date_of_birth: string | null
  hostel: { id: number; name: string }
  rooms: { id: number; room_number: string; capacity: number; occupied: number }[]
  allocations: { id: number; room: string | null; bed_label: string | null; allocated_on: string; vacated_on: string | null }[]
  leaves: { id: number; leave_date: string; expected_return_date: string | null; actual_return_date: string | null; reason: string | null; escort_name: string | null; approved_by: string | null; overdue: boolean }[]
  health: HealthProfile | null
  incidents: { id: number; incident_date: string; description: string; action_taken: string | null; reported_by: string | null }[]
  fees: {
    id: number
    fee_period: string
    amount_due: number
    due_date: string | null
    paid: number
    balance: number
    status: 'pending' | 'partial' | 'paid'
    payments: { id: number; amount: number; payment_mode: PaymentMode; paid_on: string; remarks: string | null; received_by: string | null }[]
  }[]
  alumnus: { departure_date: string; departure_reason: string | null; current_status: string | null; contact_number: string | null; notes: string | null } | null
  attendance_30_days: { present: number; absent: number; on_leave: number }
}

export interface RollSheet {
  date: string
  session: Session
  marked: boolean
  residents: { id: number; name: string; room: string | null; status: RollStatus; remark: string | null; on_out_pass: boolean }[]
  sessions_marked: Session[]
}

export const fetchHostels = async (): Promise<{ data: HostelRow[]; meta: { can_create: boolean } }> => (await api.get('/hostels')).data
export const fetchHostel = async (id: number): Promise<HostelDetail> => (await api.get(`/hostels/${id}`)).data.data
export const saveHostel = async (id: number | null, input: { name: string; department_id: number; gender: HostelGender; capacity: number | null; is_active?: boolean }): Promise<HostelDetail> =>
  id ? (await api.put(`/hostels/${id}`, input)).data.data : (await api.post('/hostels', input)).data.data
export const setHostelStaff = async (id: number, staff: { user_id: number; role: string }[]): Promise<HostelDetail> => (await api.put(`/hostels/${id}/staff`, { staff })).data.data
export const saveRoom = async (hostelId: number, roomId: number | null, input: { room_number: string; capacity: number; is_active?: boolean }): Promise<HostelDetail> =>
  roomId ? (await api.put(`/hostels/${hostelId}/rooms/${roomId}`, input)).data.data : (await api.post(`/hostels/${hostelId}/rooms`, input)).data.data

export const fetchResidents = async (hostelId: number, status: string, search: string): Promise<Resident[]> =>
  (await api.get(`/hostels/${hostelId}/residents`, { params: { status, search: search || undefined } })).data.data
export const residentsCsvUrl = (hostelId: number) => `/api/v1/hostels/${hostelId}/residents?format=csv&status=active`
export const addResident = async (hostelId: number, input: ResidentInput): Promise<ResidentDetail> => (await api.post(`/hostels/${hostelId}/residents`, input)).data.data
export const fetchResident = async (id: number): Promise<ResidentDetail> => (await api.get(`/hostel-residents/${id}`)).data.data
export const updateResident = async (id: number, input: ResidentInput): Promise<ResidentDetail> => (await api.put(`/hostel-residents/${id}`, input)).data.data
export const allocateBed = async (id: number, roomId: number, bedLabel: string | null): Promise<ResidentDetail> =>
  (await api.post(`/hostel-residents/${id}/allocate`, { room_id: roomId, bed_label: bedLabel })).data.data
export const vacateBed = async (id: number): Promise<ResidentDetail> => (await api.post(`/hostel-residents/${id}/vacate`)).data.data
export const goOut = async (id: number, input: { leave_date: string; expected_return_date: string | null; reason: string | null; escort_name: string | null }): Promise<ResidentDetail> =>
  (await api.post(`/hostel-residents/${id}/out`, input)).data.data
export const markReturned = async (id: number, date: string | null): Promise<ResidentDetail> => (await api.post(`/hostel-residents/${id}/returned`, { actual_return_date: date })).data.data
export const saveHealth = async (id: number, input: HealthProfile): Promise<ResidentDetail> => (await api.put(`/hostel-residents/${id}/health`, input)).data.data
export const addIncident = async (id: number, input: { incident_date: string; description: string; action_taken: string | null }): Promise<ResidentDetail> =>
  (await api.post(`/hostel-residents/${id}/incidents`, input)).data.data
export const addFee = async (id: number, input: { fee_period: string; amount_due: number; due_date: string | null }): Promise<ResidentDetail> =>
  (await api.post(`/hostel-residents/${id}/fees`, input)).data.data
export const payFee = async (feeId: number, input: { amount: number; payment_mode: PaymentMode; paid_on: string | null; remarks: string | null }): Promise<ResidentDetail> =>
  (await api.post(`/hostel-fees/${feeId}/payments`, input)).data.data
export const departResident = async (
  id: number,
  input: { departure_date: string; departure_reason: string | null; current_status: string | null; contact_number: string | null; notes: string | null },
): Promise<ResidentDetail> => (await api.post(`/hostel-residents/${id}/depart`, input)).data.data
export const updateAlumnus = async (id: number, input: { current_status: string | null; contact_number: string | null; notes: string | null }): Promise<ResidentDetail> =>
  (await api.put(`/hostel-residents/${id}/alumnus`, input)).data.data

export const fetchRollSheet = async (hostelId: number, session: Session, date: string): Promise<RollSheet> =>
  (await api.get(`/hostels/${hostelId}/attendance`, { params: { session, date } })).data.data
export const saveRollSheet = async (hostelId: number, session: Session, date: string, entries: { resident_id: number; status: RollStatus; remark: string | null }[]): Promise<RollSheet> =>
  (await api.post(`/hostels/${hostelId}/attendance`, { entries }, { params: { session, date } })).data.data

export const residentImportTemplateUrl = '/api/v1/hostels/residents/import/template'
export const previewResidentImport = (hostelId: number) => async (file: File): Promise<{ rows: ImportRow[] }> =>
  (await api.post(`/hostels/${hostelId}/residents/import/preview`, csvForm(file))).data.data
export const runResidentImport = (hostelId: number) => async (file: File): Promise<{ imported: number }> =>
  (await api.post(`/hostels/${hostelId}/residents/import`, csvForm(file))).data.data
