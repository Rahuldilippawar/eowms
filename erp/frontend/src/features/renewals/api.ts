import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'
import type { PaymentType } from '@/features/finance/api'
import { csvForm, type ImportRow } from '@/features/people/profileApi'

/** Same list as the server (RenewalItem::REGULATORY_BODIES). */
export const REGULATORY_BODIES = ['CBSE', 'State Board', 'AICTE', 'DTE Maharashtra', 'MSBTE', 'University', 'UGC', 'NAAC', 'RTE', 'RTO/Transport', 'Other Government', 'Not Applicable']

export type RenewalStatus = 'expired' | 'due_soon' | 'active' | 'inactive'

export interface Renewal {
  id: number
  department: { id: number; name: string } | null
  responsible: { id: number; name: string } | null
  item_type: string
  regulatory_body: string | null
  item_name: string
  reference_no: string | null
  issued_date: string | null
  expiry_date: string
  days_left: number
  status: RenewalStatus
  renewal_cost: number | null
  is_recurring_monthly: boolean
  monthly_amount: number | null
  vendor_name: string | null
  is_active: boolean
  cycle_paid: number
}

export interface RenewalDetail extends Renewal {
  vendor_contact: string | null
  recurring_start_month: string | null
  can_manage: boolean
  months: { month: string; paid: boolean }[]
  history: { old_expiry_date: string; old_issued_date: string | null; old_renewal_cost: number | null; old_vendor_name: string | null; old_reference_no: string | null; new_expiry_date: string; renewed_by: string | null; created_at: string | null }[]
  payments: { id: number; amount: number; payment_date: string; payment_type: PaymentType; payment_mode: string | null; for_month: string | null; cycle_expiry_date: string | null; remarks: string | null; recorded_by: string | null }[]
  attachments: { id: number; title: string | null; name: string; size: number; cycle_expiry_date: string | null; created_at: string | null }[]
}

export interface RenewalInput {
  department_id: number
  item_type: string
  regulatory_body: string | null
  item_name: string
  reference_no: string | null
  issued_date: string | null
  expiry_date: string
  renewal_cost: number | null
  is_recurring_monthly: boolean
  monthly_amount: number | null
  recurring_start_month: string | null
  vendor_name: string | null
  vendor_contact: string | null
  responsible_user_id: number | null
}

export interface RenewalListMeta {
  summary: { expired: number; due_soon: number; active: number }
  by_regulatory_body: { regulatory_body: string; expired: number; due_soon: number; total: number }[]
  can_manage: boolean
  regulatory_bodies: string[]
}

export const fetchRenewals = async (p: { status?: string; regulatory_body?: string; search?: string; page?: number }): Promise<Paginated<Renewal> & { meta: RenewalListMeta }> =>
  (await api.get('/renewals', { params: { ...p, status: p.status || undefined, regulatory_body: p.regulatory_body || undefined, search: p.search || undefined } })).data
export const renewalsCsvUrl = '/api/v1/renewals?format=csv'
export const fetchRenewal = async (id: number): Promise<RenewalDetail> => (await api.get(`/renewals/${id}`)).data.data
export const saveRenewal = async (id: number | null, input: RenewalInput): Promise<RenewalDetail> =>
  id ? (await api.put(`/renewals/${id}`, input)).data.data : (await api.post('/renewals', input)).data.data
export const setRenewalActive = async (id: number, isActive: boolean): Promise<RenewalDetail> => (await api.post(`/renewals/${id}/active`, { is_active: isActive })).data.data
export const renewItem = async (id: number, input: { new_expiry_date: string; issued_date: string | null; renewal_cost: number | null; vendor_name: string | null; reference_no: string | null }): Promise<RenewalDetail> =>
  (await api.post(`/renewals/${id}/renew`, input)).data.data
export const payRenewal = async (id: number, input: { amount: number; payment_date: string; payment_type: PaymentType; payment_mode: string | null; for_month: string | null; remarks: string | null }): Promise<RenewalDetail> =>
  (await api.post(`/renewals/${id}/payments`, input)).data.data
export const attachToRenewal = async (id: number, file: File, title: string | null): Promise<RenewalDetail> => {
  const form = new FormData()
  form.append('file', file)
  if (title) form.append('title', title)
  return (await api.post(`/renewals/${id}/attachments`, form)).data.data
}
export const renewalAttachmentUrl = (id: number, attachmentId: number) => `/api/v1/renewals/${id}/attachments/${attachmentId}`
export const fetchRenewalCalendar = async (month: string): Promise<{ data: Renewal[]; meta: { month: string } }> => (await api.get('/renewals/calendar', { params: { month } })).data
export const fetchRenewalCosts = async (): Promise<{ by_year_type: { year: number; item_type: string; total: number; count: number }[]; paid_by_year: { year: number; total: number }[] }> =>
  (await api.get('/renewals/costs')).data.data

export const renewalImportTemplateUrl = '/api/v1/renewals/import/template'
export const previewRenewalImport = async (file: File): Promise<{ rows: ImportRow[] }> => (await api.post('/renewals/import/preview', csvForm(file))).data.data
export const runRenewalImport = async (file: File): Promise<{ imported: number }> => (await api.post('/renewals/import', csvForm(file))).data.data
