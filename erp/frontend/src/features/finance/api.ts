import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'

export type PaymentStatus = 'pending' | 'partial' | 'paid'
export type PaymentType = 'advance' | 'partial' | 'final'

export interface BudgetRow {
  department: { id: number; name: string; code: string }
  allocated: number | null
  spent: number
  remaining: number | null
  percent_used: number | null
  by_category: Record<string, number>
  by_source: Record<string, number>
  purchases_billed: number
  purchases_paid: number
}

export interface Purchase {
  id: number
  department: { id: number; name: string; code: string } | null
  material_name: string
  vendor_name: string
  vendor_contact: string | null
  quantity: number
  unit: string | null
  rate: number
  base_amount: number
  transport_cost: number
  gst_percent: number
  gst_amount: number
  total_amount: number
  paid_amount: number
  due_amount: number
  payment_status: PaymentStatus
  purchase_date: string
  remarks: string | null
}

export interface PurchaseDetail extends Purchase {
  created_by: string | null
  can_manage: boolean
  payments: { id: number; amount: number; payment_date: string; payment_type: PaymentType; payment_mode: string | null; remarks: string | null; recorded_by: string | null }[]
}

export interface PurchaseInput {
  department_id: number
  material_name: string
  vendor_name: string
  vendor_contact: string | null
  quantity: number
  unit: string | null
  rate: number
  transport_cost: number
  gst_percent: number
  purchase_date: string
  remarks: string | null
}

export const fetchBudgets = async (fy?: number): Promise<{ data: BudgetRow[]; meta: { fy: number; current_fy: number; can_allocate: boolean } }> =>
  (await api.get('/finance/budgets', { params: { fy } })).data
export const allocateBudget = async (input: { department_id: number; fy: number; allocated_amount: number }) => (await api.put('/finance/budgets', input)).data.data
export const checkBudget = async (p: { department_id: number; date: string; amount: number }): Promise<{ fy: number; remaining: number | null; exceeds: boolean }> =>
  (await api.get('/finance/budget-check', { params: p })).data.data
export const ledgerCsvUrl = (fy: number) => `/api/v1/finance/transactions?${new URLSearchParams({ fy: String(fy), format: 'csv' })}`

export const fetchPurchases = async (p: { fy?: number; status?: string; search?: string; page?: number }): Promise<Paginated<Purchase> & { meta: { fy: number; current_fy: number; can_manage: boolean } }> =>
  (await api.get('/finance/purchases', { params: { ...p, status: p.status || undefined, search: p.search || undefined } })).data
export const purchasesCsvUrl = (fy: number) => `/api/v1/finance/purchases?${new URLSearchParams({ fy: String(fy), format: 'csv' })}`
export const fetchPurchase = async (id: number): Promise<PurchaseDetail> => (await api.get(`/finance/purchases/${id}`)).data.data
export const savePurchase = async (id: number | null, input: PurchaseInput): Promise<PurchaseDetail> =>
  id ? (await api.put(`/finance/purchases/${id}`, input)).data.data : (await api.post('/finance/purchases', input)).data.data
export const deletePurchase = async (id: number) => (await api.delete(`/finance/purchases/${id}`)).data
export const payPurchase = async (id: number, input: { amount: number; payment_date: string; payment_type: PaymentType; payment_mode: string | null; remarks: string | null }): Promise<PurchaseDetail> =>
  (await api.post(`/finance/purchases/${id}/payments`, input)).data.data

/** "2026-27" for the financial year starting in April 2026. */
export const fyLabel = (y: number) => `${y}-${String((y + 1) % 100).padStart(2, '0')}`
