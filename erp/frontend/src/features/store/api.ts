import { api } from '@/lib/api'

export type MovementType = 'receive' | 'issue' | 'adjust'

export interface StoreItem {
  id: number
  item_name: string
  category: string | null
  unit: string
  reorder_level: number
  department: { id: number; name: string } | null
  is_active: boolean
  balance: number
  low: boolean
}
export interface StockMovement {
  id: number
  movement_type: MovementType
  quantity: number
  balance: number
  unit_cost: number | null
  department: string | null
  issued_to: string | null
  from_purchase: boolean
  movement_date: string
  remarks: string | null
  by: string | null
}
export interface StoreItemDetail extends StoreItem {
  movements: StockMovement[]
  can_manage: boolean
}
export interface ItemInput {
  item_name: string
  category: string | null
  unit: string
  department_id: number | null
  reorder_level: number | null
  is_active?: boolean
}
export interface MovementInput {
  movement_type: MovementType
  quantity: number
  unit_cost?: number | null
  department_id?: number | null
  issued_to?: string | null
  movement_date?: string | null
  remarks?: string | null
}

export const fetchStoreItems = async (p: { search?: string; category?: string; low?: boolean; inactive?: boolean }): Promise<{
  data: StoreItem[]
  meta: { can_manage: boolean; categories: string[]; low_count: number }
}> =>
  (await api.get('/store/items', { params: { search: p.search || undefined, category: p.category || undefined, low: p.low ? 1 : undefined, inactive: p.inactive ? 1 : undefined } })).data
export const stockCsvUrl = '/api/v1/store/items?format=csv'
export const fetchStoreItem = async (id: number): Promise<StoreItemDetail> => (await api.get(`/store/items/${id}`)).data.data
export const saveStoreItem = async (id: number | null, input: ItemInput): Promise<StoreItemDetail> =>
  (id ? await api.put(`/store/items/${id}`, input) : await api.post('/store/items', input)).data.data
export const moveStock = async (id: number, input: MovementInput): Promise<StoreItemDetail> => (await api.post(`/store/items/${id}/movements`, input)).data.data
export const fetchIssues = async (from: string, to: string): Promise<{ department: string | null; item_id: number; item_name: string; unit: string; quantity: number; issues: number }[]> =>
  (await api.get('/store/issues', { params: { from, to } })).data.data
