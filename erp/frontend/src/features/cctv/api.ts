import { api } from '@/lib/api'
import { csvForm, type ImportRow } from '@/features/people/profileApi'

export const CAMERA_TYPES = ['dome', 'bullet', 'ptz', 'other'] as const
export type CameraType = (typeof CAMERA_TYPES)[number]
export const CAMERA_STATUSES = ['working', 'not_working', 'needs_attention'] as const
export type CameraStatus = (typeof CAMERA_STATUSES)[number]

export interface Dvr {
  id: number
  name: string
  location: string | null
  channel_capacity: number | null
  storage_tb: number | null
  installed_date: string | null
  is_active: boolean
  department: { id: number; name: string } | null
}
export interface DashboardDvr extends Dvr {
  cameras_count: number
  last_checkup: { date: string; storage_used_percent: number | null; footage_days: number | null; backup_done: boolean } | null
  days_since_checkup: number | null
  late: boolean
}
export interface Camera {
  id: number
  name: string
  location: string | null
  camera_type: CameraType
  is_active: boolean
}
export interface DvrDetail extends Dvr {
  cameras: Camera[]
  checkups: { id: number; date: string; storage_used_percent: number | null; footage_days: number | null; backup_done: boolean; backup_location: string | null; remarks: string | null; checked_by: string | null; not_working: number; needs_attention: number }[]
  can_manage: boolean
}
export interface Sheet {
  dvr: Dvr
  date: string
  saved: boolean
  checked_by: string | null
  storage_used_percent: number | null
  footage_days: number | null
  backup_done: boolean
  backup_location: string | null
  remarks: string | null
  cameras: { id: number; name: string; location: string | null; status: CameraStatus; remarks: string | null }[]
}

export const fetchCctvDashboard = async (): Promise<{
  dvrs: DashboardDvr[]
  cameras: Record<CameraStatus, number>
  checked_today: number
  can_manage: boolean
  can_check: boolean
}> => (await api.get('/cctv/dashboard')).data.data
export const fetchDvr = async (id: number): Promise<DvrDetail> => (await api.get(`/cctv/dvrs/${id}`)).data.data
export const saveDvr = async (id: number | null, input: { name: string; location: string | null; channel_capacity: number | null; storage_tb: number | null; installed_date: string | null; is_active?: boolean }): Promise<DvrDetail> =>
  (id ? await api.put(`/cctv/dvrs/${id}`, input) : await api.post('/cctv/dvrs', input)).data.data
export const addCamera = async (dvrId: number, input: { name: string; location: string | null; camera_type: CameraType }): Promise<DvrDetail> =>
  (await api.post(`/cctv/dvrs/${dvrId}/cameras`, input)).data.data
export const updateCamera = async (id: number, input: { name: string; location: string | null; camera_type: CameraType; is_active: boolean }): Promise<DvrDetail> =>
  (await api.put(`/cctv/cameras/${id}`, input)).data.data
export const fetchSheet = async (dvrId: number, date: string): Promise<Sheet> => (await api.get(`/cctv/dvrs/${dvrId}/checkup`, { params: { date } })).data.data
export const saveSheet = async (
  dvrId: number,
  input: { date: string; storage_used_percent: number | null; footage_days: number | null; backup_done: boolean; backup_location: string | null; remarks: string | null; cameras: { camera_id: number; status: CameraStatus; remarks: string | null }[] },
): Promise<Sheet> => (await api.post(`/cctv/dvrs/${dvrId}/checkup`, input)).data.data
export const fetchCctvReport = async (
  period: 'weekly' | 'monthly',
): Promise<{ period: string; from: string; to: string; days: number; dvrs: { dvr: Dvr; checkups: number; compliance: number; backups: number; max_storage: number | null; working: number; not_working: number; needs_attention: number }[] }> =>
  (await api.get('/cctv/report', { params: { period } })).data.data
export const cctvTemplateUrl = '/api/v1/cctv/import/template'
export const previewCctvImport = async (file: File): Promise<{ rows: ImportRow[] }> => (await api.post('/cctv/import/preview', csvForm(file))).data.data
export const runCctvImport = async (file: File): Promise<{ imported: number }> => (await api.post('/cctv/import', csvForm(file))).data.data
