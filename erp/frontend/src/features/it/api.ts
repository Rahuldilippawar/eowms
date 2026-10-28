import { api } from '@/lib/api'
import { csvForm, type ImportRow } from '@/features/people/profileApi'
import type { Paginated } from '@/lib/pagination'

export const ASSET_TYPES = ['computer', 'monitor', 'keyboard', 'mouse', 'cpu', 'ups', 'smartboard', 'projector', 'printer', 'other'] as const
export type AssetType = (typeof ASSET_TYPES)[number]
export const ASSET_STATUSES = ['working', 'faulty', 'under_repair', 'scrapped'] as const
export type AssetStatus = (typeof ASSET_STATUSES)[number]
export type TicketStatus = 'pending' | 'in_progress' | 'resolved'

export interface Lab {
  id: number
  name: string
  location: string | null
  capacity: number | null
  is_active: boolean
  department: { id: number; name: string } | null
  assistant: { id: number; name: string } | null
  assets_count?: number
  working_count?: number
  issue_count?: number
}
export interface ItAsset {
  id: number
  asset_tag: string
  asset_type: AssetType
  workstation_no: string | null
  brand: string | null
  model: string | null
  serial_no: string | null
  lab: { id: number; name: string } | null
  status: AssetStatus
  power_status: 'on' | 'off'
  warranty_expiry: string | null
  license_expiry: string | null
  open_tickets: number
  tickets?: number
}
export interface Ticket {
  id: number
  status: TicketStatus
  issue_description: string
  resolution_notes: string | null
  asset: { id: number; asset_tag: string; asset_type: AssetType; label: string | null; workstation_no: string | null } | null
  lab: string | null
  reported_by: string | null
  resolved_by: string | null
  created_at: string | null
  resolved_at: string | null
}
export interface ItAssetDetail extends Omit<ItAsset, 'tickets'> {
  purchase_date: string | null
  purchase_cost: number | null
  os_license: string | null
  remarks: string | null
  power_updated_at: string | null
  tickets: Ticket[]
  transfers: { from: string | null; to: string | null; remarks: string | null; by: string | null; at: string | null }[]
  can_keep: boolean
  can_manage: boolean
}
export interface AssetInput {
  lab_id?: number
  asset_type?: AssetType
  workstation_no: string | null
  brand: string | null
  model: string | null
  serial_no: string | null
  purchase_date: string | null
  purchase_cost: number | null
  warranty_expiry: string | null
  os_license: string | null
  license_expiry: string | null
  remarks: string | null
}
export interface Dashboard {
  labs: Lab[]
  by_status: Partial<Record<AssetStatus, number>>
  open_tickets: number
  smartboards: { total: number; on: number; faulty: number }
  expiring: { id: number; asset_tag: string; lab: string | null; warranty_expiry: string | null; license_expiry: string | null }[]
  can_manage: boolean
}

export const fetchItDashboard = async (): Promise<Dashboard> => (await api.get('/it/dashboard')).data.data
export const fetchLabs = async (): Promise<Lab[]> => (await api.get('/it/labs')).data.data
export const saveLab = async (id: number | null, input: { name: string; location: string | null; capacity: number | null; department_id: number | null; assistant_user_id: number | null; is_active?: boolean }): Promise<Lab> =>
  (id ? await api.put(`/it/labs/${id}`, input) : await api.post('/it/labs', input)).data.data
export const fetchItAssets = async (p: { lab_id?: string; asset_type?: string; status?: string; search?: string; page?: number }): Promise<Paginated<ItAsset> & { meta: { can_manage: boolean } }> =>
  (await api.get('/it/assets', { params: { lab_id: p.lab_id || undefined, asset_type: p.asset_type || undefined, status: p.status || undefined, search: p.search || undefined, page: p.page } })).data
export const itAssetsCsvUrl = '/api/v1/it/assets?format=csv'
export const fetchItAsset = async (id: number): Promise<ItAssetDetail> => (await api.get(`/it/assets/${id}`)).data.data
export const saveItAsset = async (id: number | null, input: AssetInput): Promise<ItAssetDetail> =>
  (id ? await api.put(`/it/assets/${id}`, input) : await api.post('/it/assets', input)).data.data
export const setItAssetStatus = async (id: number, input: { status?: AssetStatus; power_status?: 'on' | 'off' }): Promise<ItAssetDetail> =>
  (await api.post(`/it/assets/${id}/status`, input)).data.data
export const transferItAsset = async (id: number, toLabId: number, remarks: string | null): Promise<ItAssetDetail> =>
  (await api.post(`/it/assets/${id}/transfer`, { to_lab_id: toLabId, remarks })).data.data
export const fetchTickets = async (status: string): Promise<{ data: Ticket[]; meta: { can_resolve: boolean } }> => (await api.get('/it/tickets', { params: { status } })).data
export const startTicket = async (id: number): Promise<Ticket> => (await api.post(`/it/tickets/${id}/start`)).data.data
export const resolveTicket = async (id: number, notes: string): Promise<Ticket> => (await api.post(`/it/tickets/${id}/resolve`, { resolution_notes: notes })).data.data
export const fetchSmartboards = async (): Promise<ItAsset[]> => (await api.get('/it/smartboards')).data.data
export const lookupTag = async (tag: string): Promise<{ id: number; asset_tag: string; asset_type: AssetType; brand: string | null; model: string | null; workstation_no: string | null; lab: string | null; status: AssetStatus; open_tickets: number }> =>
  (await api.get('/it/lookup', { params: { tag } })).data.data
export const reportProblem = async (assetId: number, description: string): Promise<Ticket> => (await api.post(`/it/assets/${assetId}/report`, { issue_description: description })).data.data
export const fetchMyTickets = async (): Promise<Ticket[]> => (await api.get('/my/it-tickets')).data.data
export const itTemplateUrl = '/api/v1/it/import/template'
export const previewItImport = async (file: File): Promise<{ rows: ImportRow[] }> => (await api.post('/it/import/preview', csvForm(file))).data.data
export const runItImport = async (file: File): Promise<{ imported: number }> => (await api.post('/it/import', csvForm(file))).data.data
