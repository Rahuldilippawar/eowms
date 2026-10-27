import { api } from '@/lib/api'
import type { PaymentType } from '@/features/finance/api'
import type { Paginated } from '@/lib/pagination'

export type OrderStatus = 'draft' | 'issued' | 'partly_received' | 'received' | 'cancelled'
export type QuotationStatus = 'received' | 'accepted' | 'rejected'

export interface Vendor {
  id: number
  name: string
  contact_person: string | null
  phone: string | null
  email: string | null
  gstin: string | null
  address: string | null
  is_active: boolean
  orders?: number
  ordered_amount?: number
}
export type VendorInput = Omit<Vendor, 'id' | 'is_active' | 'orders' | 'ordered_amount'> & { is_active?: boolean }

export interface Line {
  id: number
  item_id: number | null
  description: string
  quantity: number
  unit: string | null
  rate: number
  gst_percent: number
  amount: number
  received_quantity: number
}
export interface LineInput {
  item_id: number | null
  description: string | null
  quantity: number
  unit: string | null
  rate: number
  gst_percent: number
}
export interface Quotation {
  id: number
  reference_no: string | null
  status: QuotationStatus
  quotation_date: string
  valid_until: string | null
  department: { id: number; name: string } | null
  vendor: { id: number; name: string } | null
  total_amount: number
  remarks: string | null
  lines: Line[]
  order: { id: number; po_number: string | null; status: OrderStatus } | null
  can_manage: boolean
}
export interface OrderRow {
  id: number
  po_number: string | null
  status: OrderStatus
  order_date: string
  department: { id: number; name: string } | null
  vendor_name: string | null
  total_amount: number
  invoiced_amount: number
}
export interface Invoice {
  id: number
  invoice_number: string
  invoice_date: string
  goods_amount: number
  other_charges: number
  total_amount: number
  paid_amount: number
  due_amount: number
  remarks: string | null
  payments: { id: number; amount: number; payment_date: string; payment_type: PaymentType; payment_mode: string | null; recorded_by: string | null }[]
}
export interface OrderDetail extends OrderRow {
  vendor: Vendor
  quotation: { id: number; reference_no: string | null; quotation_date: string } | null
  expected_date: string | null
  terms: string | null
  subtotal: number
  gst_amount: number
  created_by: string | null
  issued_at: string | null
  lines: Line[]
  receipts: { id: number; grn_number: string; received_date: string; remarks: string | null; received_by: string | null; lines: { description: string | null; quantity: number }[] }[]
  invoices: Invoice[]
  received_value: number
  invoiceable_amount: number
  can_manage: boolean
  can_receive: boolean
  budget_left: number | null
}
export interface OrderInput {
  department_id: number
  vendor_id: number
  order_date: string
  expected_date: string | null
  terms: string | null
  lines: LineInput[]
}

export const fetchVendors = async (p: { search?: string; inactive?: boolean } = {}): Promise<{ data: Vendor[]; meta: { can_manage: boolean } }> =>
  (await api.get('/purchasing/vendors', { params: { search: p.search || undefined, inactive: p.inactive ? 1 : undefined } })).data
export const saveVendor = async (id: number | null, input: VendorInput): Promise<Vendor> =>
  (id ? await api.put(`/purchasing/vendors/${id}`, input) : await api.post('/purchasing/vendors', input)).data.data

export const fetchQuotations = async (p: { status?: string; department_id?: string }): Promise<{ data: Quotation[]; meta: { can_manage: boolean } }> =>
  (await api.get('/purchasing/quotations', { params: { status: p.status || undefined, department_id: p.department_id || undefined } })).data
export const saveQuotation = async (input: { department_id: number; vendor_id: number; quotation_date: string; reference_no: string | null; valid_until: string | null; remarks: string | null; lines: LineInput[] }): Promise<Quotation> =>
  (await api.post('/purchasing/quotations', input)).data.data
export const acceptQuotation = async (id: number): Promise<OrderDetail> => (await api.post(`/purchasing/quotations/${id}/decide`, { decision: 'accept' })).data.data
export const rejectQuotation = async (id: number): Promise<Quotation> => (await api.post(`/purchasing/quotations/${id}/decide`, { decision: 'reject' })).data.data

export const fetchOrders = async (p: { status?: string; search?: string; page?: number }): Promise<Paginated<OrderRow> & { meta: { can_manage: boolean } }> =>
  (await api.get('/purchasing/orders', { params: { status: p.status || undefined, search: p.search || undefined, page: p.page } })).data
export const fetchOrder = async (id: number): Promise<OrderDetail> => (await api.get(`/purchasing/orders/${id}`)).data.data
export const saveOrder = async (id: number | null, input: OrderInput): Promise<OrderDetail> =>
  (id ? await api.put(`/purchasing/orders/${id}`, input) : await api.post('/purchasing/orders', input)).data.data
export const issueOrder = async (id: number): Promise<OrderDetail> => (await api.post(`/purchasing/orders/${id}/issue`)).data.data
export const cancelOrder = async (id: number, reason: string | null): Promise<OrderDetail> => (await api.post(`/purchasing/orders/${id}/cancel`, { reason })).data.data
export const receiveGoods = async (id: number, input: { received_date: string; remarks: string | null; lines: { line_id: number; quantity: number }[] }): Promise<OrderDetail> =>
  (await api.post(`/purchasing/orders/${id}/receive`, input)).data.data
export const addInvoice = async (id: number, input: { invoice_number: string; invoice_date: string; goods_amount: number; other_charges: number | null; remarks: string | null }): Promise<OrderDetail> =>
  (await api.post(`/purchasing/orders/${id}/invoices`, input)).data.data
export const deleteInvoice = async (id: number): Promise<OrderDetail> => (await api.delete(`/purchasing/invoices/${id}`)).data.data
export const payInvoice = async (id: number, input: { amount: number; payment_date: string; payment_type: PaymentType; payment_mode: string | null; remarks: string | null }): Promise<OrderDetail> =>
  (await api.post(`/purchasing/invoices/${id}/payments`, input)).data.data

/** Line amount as the server works it out: quantity × rate plus GST. */
export const lineAmount = (l: Pick<LineInput, 'quantity' | 'rate' | 'gst_percent'>) => {
  const base = Math.round(l.quantity * l.rate * 100) / 100
  return Math.round((base + (base * (l.gst_percent || 0)) / 100) * 100) / 100
}
