import { api } from '@/lib/api'
import { csvForm, type ImportRow } from '@/features/people/profileApi'
import type { Paginated } from '@/lib/pagination'

export type Condition = 'good' | 'repairable' | 'bad' | 'scrap'
export type FieldType = 'text' | 'number' | 'date' | 'dropdown' | 'yesno'
export type AllocationView = 'current' | 'loans' | 'overdue' | 'history'

export interface ItemMaster {
  id: number
  item_name: string
  category: string | null
  default_unit: string | null
  is_active: boolean
}
export interface DeadStockItem {
  id: number
  item_code: string
  item_master_id: number
  item_name: string
  category: string | null
  department: { id: number; name: string } | null
  quantity: number
  unit: string | null
  purchase_date: string | null
  purchase_cost: number | null
  is_new_purchase: boolean
  condition: Condition
  location: string | null
  remarks: string | null
  can_manage: boolean
}
export interface DeadStockInput {
  department_id: number
  item_master_id: number
  quantity: number
  unit: string | null
  purchase_date: string | null
  purchase_cost: number | null
  is_new_purchase: boolean
  condition: Condition
  location: string | null
  remarks: string | null
}
export interface ReportRow {
  key: string | number | null
  label: string | null
  entries: number
  quantity: number
  value: number
}
export interface TypeField {
  key?: string
  label: string
  type: FieldType
  options: string[]
  required: boolean
}
export interface AllocationType {
  id: number
  name: string
  is_loan: boolean
  fields: TypeField[]
  is_active: boolean
  items_count?: number
}
export interface AllocationItem {
  id: number
  name: string
  default_location: string | null
  type: { id: number; name: string; is_loan: boolean }
  held_by: { id: number; name: string } | null
}
export interface Allocation {
  id: number
  type: { id: number; name: string; is_loan: boolean } | null
  item: { id: number; name: string } | null
  user: { id: number; name: string } | null
  custom: Record<string, string>
  fields: TypeField[]
  purpose: string | null
  allocated_at: string
  due_at: string | null
  overdue: boolean
  returned_at: string | null
  returned_to: string | null
  return_reason: string | null
  allocated_by: string | null
  transferred_from_id: number | null
}

export const fetchMasters = async (inactive = false): Promise<{ data: ItemMaster[]; meta: { can_manage: boolean } }> =>
  (await api.get('/assets/masters', { params: { inactive: inactive ? 1 : undefined } })).data
export const saveMaster = async (id: number | null, input: { item_name: string; category: string | null; default_unit: string | null; is_active?: boolean }): Promise<ItemMaster> =>
  (id ? await api.put(`/assets/masters/${id}`, input) : await api.post('/assets/masters', input)).data.data
export const masterTemplateUrl = '/api/v1/assets/masters/import/template'
export const previewMasterImport = async (file: File): Promise<{ rows: ImportRow[] }> => (await api.post('/assets/masters/import/preview', csvForm(file))).data.data
export const runMasterImport = async (file: File): Promise<{ imported: number }> => (await api.post('/assets/masters/import', csvForm(file))).data.data

export const fetchDeadStock = async (p: { department_id?: string; category?: string; condition?: string; search?: string; page?: number }): Promise<
  Paginated<DeadStockItem> & { meta: { can_manage: boolean; categories: string[] } }
> =>
  (await api.get('/assets/dead-stock', { params: { department_id: p.department_id || undefined, category: p.category || undefined, condition: p.condition || undefined, search: p.search || undefined, page: p.page } })).data
export const deadStockCsvUrl = '/api/v1/assets/dead-stock?format=csv'
export const saveDeadStock = async (id: number | null, input: DeadStockInput): Promise<DeadStockItem> =>
  (id ? await api.put(`/assets/dead-stock/${id}`, input) : await api.post('/assets/dead-stock', input)).data.data
export const deleteDeadStock = async (id: number) => (await api.delete(`/assets/dead-stock/${id}`)).data
export const fetchDeadStockReport = async (): Promise<{ by_department: ReportRow[]; by_category: ReportRow[]; by_condition: ReportRow[]; total_value: number }> =>
  (await api.get('/assets/dead-stock/report')).data.data

export const fetchAllocationTypes = async (inactive = false): Promise<{ data: AllocationType[]; meta: { can_manage: boolean } }> =>
  (await api.get('/assets/allocation-types', { params: { inactive: inactive ? 1 : undefined } })).data
export const saveAllocationType = async (id: number | null, input: { name: string; is_loan: boolean; fields: TypeField[]; is_active?: boolean }): Promise<AllocationType> =>
  (id ? await api.put(`/assets/allocation-types/${id}`, input) : await api.post('/assets/allocation-types', input)).data.data
export const fetchAllocationItems = async (p: { type_id?: number; free?: boolean } = {}): Promise<AllocationItem[]> =>
  (await api.get('/assets/allocation-items', { params: { type_id: p.type_id, free: p.free ? 1 : undefined } })).data.data
export const addAllocationItem = async (input: { type_id: number; name: string; default_location: string | null }) => (await api.post('/assets/allocation-items', input)).data.data
export const retireAllocationItem = async (id: number) => (await api.post(`/assets/allocation-items/${id}/retire`)).data.data
export const fetchAllocations = async (p: { view: AllocationView; type_id?: string; search?: string; page?: number }): Promise<
  Paginated<Allocation> & { meta: { can_manage: boolean; overdue: number } }
> => (await api.get('/assets/allocations', { params: { view: p.view, type_id: p.type_id || undefined, search: p.search || undefined, page: p.page } })).data
export const allocationsCsvUrl = (view: AllocationView) => `/api/v1/assets/allocations?view=${view}&format=csv`
export const giveItem = async (input: { item_id: number; user_id: number; purpose: string | null; due_at: string | null; custom: Record<string, string> }): Promise<Allocation> =>
  (await api.post('/assets/allocations', input)).data.data
export const takeBack = async (id: number, reason: string | null): Promise<Allocation> => (await api.post(`/assets/allocations/${id}/return`, { reason })).data.data
export const transferItem = async (id: number, userId: number, reason: string | null): Promise<Allocation> =>
  (await api.post(`/assets/allocations/${id}/transfer`, { user_id: userId, reason })).data.data
export const fetchAllocationReport = async (): Promise<{ held: number; overdue: number; free_items: number; given_last_30_days: number; by_type: { name: string; count: number }[]; by_holder: { name: string; count: number }[] }> =>
  (await api.get('/assets/allocations/report')).data.data
export const allocationTemplateUrl = '/api/v1/assets/allocations/import/template'
export const previewAllocationImport = async (file: File): Promise<{ rows: ImportRow[] }> => (await api.post('/assets/allocations/import/preview', csvForm(file))).data.data
export const runAllocationImport = async (file: File): Promise<{ imported: number }> => (await api.post('/assets/allocations/import', csvForm(file))).data.data
export const fetchMyAssets = async (): Promise<Allocation[]> => (await api.get('/my/assets')).data.data
