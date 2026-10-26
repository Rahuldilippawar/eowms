import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'

export type LetterStatus = 'draft' | 'in_progress' | 'returned' | 'completed'
export type LetterPriority = 'low' | 'normal' | 'high' | 'urgent'
export type LetterAction = 'edit' | 'submit' | 'forward' | 'return' | 'sign' | 'remark' | 'attach' | 'dispatch' | 'reassign'
export type LetterBox = 'with_me' | 'created' | 'all'
export type DispatchMode = 'post' | 'courier' | 'speed_post' | 'email' | 'hand' | 'other'
export type BudgetCategory = 'purchase' | 'maintenance' | 'fuel' | 'other'
export type ApproverType = 'origin_department_head' | 'department_head' | 'user'
type Ref = { id: number; name: string } | null

export interface Letter {
  id: number
  file_number: string
  outward_no: string | null
  reference_no: string | null
  subject: string
  category: Ref
  priority: LetterPriority
  status: LetterStatus
  department: { id: number; name: string; code: string } | null
  creator: Ref
  holder: Ref
  holder_since: string | null
  holder_seen_at: string | null
  workflow: Ref
  current_step_order: number
  is_budget_expense: boolean
  budget_category: BudgetCategory | null
  requested_amount: number | null
  approved_amount: number | null
  dispatch_mode: DispatchMode | null
  dispatch_tracking_no: string | null
  dispatch_date: string | null
  completed_at: string | null
  created_at: string | null
}

export interface LetterDetail extends Letter {
  description: string | null
  letter_category_id: number | null
  approval_workflow_id: number | null
  actions: LetterAction[]
  steps: { step_order: number; name: string; approver: Ref }[]
  movements: { id: number; type: string; from: Ref; to: Ref; step_order: number | null; remark: string | null; created_at: string | null }[]
  attachments: { id: number; name: string; mime: string; size: number; created_at: string | null }[]
  inward_numbers: { department: string | null; inward_no: string; created_at: string | null }[]
  signatures: { signer_name: string; signer_designation: string | null; method: string; verification_code: string; signed_at: string; content_hash: string; intact: boolean }[]
}

export interface LetterInput {
  subject: string
  description: string | null
  reference_no: string | null
  letter_category_id: number | null
  priority: LetterPriority
  approval_workflow_id: number | null
  is_budget_expense: boolean
  budget_category: BudgetCategory | null
  requested_amount: number | null
}

export interface Recipient {
  user_id: number
  name: string
  designation: string | null
  department: string | null
}

export interface LetterOptions {
  categories: { id: number; name: string }[]
  workflows: { id: number; name: string; letter_category_id: number | null }[]
}

export interface RegisterRow {
  number: string
  date: string | null
  file_number: string
  letter_id: number
  subject: string
  reference_no: string | null
  department: string | null
  party: string | null
  created_by: string | null
  priority: LetterPriority
  status: LetterStatus
  dispatch_mode: DispatchMode | null
  dispatch_tracking_no: string | null
  dispatch_date: string | null
}

export interface WorkflowStepInput {
  name: string
  approver_type: ApproverType
  department_id: number | null
  user_id: number | null
}

export interface Workflow {
  id: number
  name: string
  is_active: boolean
  letter_category_id: number | null
  category: string | null
  files_on_the_way: number
  steps: (WorkflowStepInput & { step_order: number; department: string | null; user: string | null; resolves_to: string | null })[]
}

export interface PublicSignature {
  signer_name: string
  signer_designation: string | null
  organization: string
  file_number: string
  outward_no: string | null
  signed_at: string
  method: string
  intact: boolean
}

export const fetchLetters = async (p: { box: LetterBox; status?: string; search?: string; page?: number }): Promise<Paginated<Letter> & { meta: { with_me: number } }> =>
  (await api.get('/letters', { params: { ...p, status: p.status || undefined, search: p.search || undefined } })).data
export const fetchLetter = async (id: number): Promise<LetterDetail> => (await api.get(`/letters/${id}`)).data.data
export const fetchLetterOptions = async (): Promise<LetterOptions> => (await api.get('/letters/options')).data.data
export const searchRecipients = async (search: string): Promise<Recipient[]> => (await api.get('/letters/recipients', { params: { search } })).data.data
export const createLetter = async (input: LetterInput): Promise<LetterDetail> => (await api.post('/letters', input)).data.data
export const updateLetter = async (id: number, input: LetterInput): Promise<LetterDetail> => (await api.put(`/letters/${id}`, input)).data.data
export const sendLetter = async (id: number, action: 'submit' | 'forward', toUserId: number | null, remark: string): Promise<LetterDetail> =>
  (await api.post(`/letters/${id}/${action}`, { to_user_id: toUserId ?? undefined, remark: remark || undefined })).data.data
export const returnLetter = async (id: number, remark: string): Promise<LetterDetail> => (await api.post(`/letters/${id}/return`, { remark })).data.data
export const remarkLetter = async (id: number, remark: string): Promise<LetterDetail> => (await api.post(`/letters/${id}/remarks`, { remark })).data.data
export const reassignLetter = async (id: number, toUserId: number, remark: string): Promise<LetterDetail> =>
  (await api.post(`/letters/${id}/reassign`, { to_user_id: toUserId, remark: remark || undefined })).data.data
export const dispatchLetter = async (id: number, input: { dispatch_mode: DispatchMode; dispatch_tracking_no: string | null; dispatch_date: string | null }): Promise<LetterDetail> =>
  (await api.post(`/letters/${id}/dispatch`, input)).data.data
export const signLetter = async (id: number, input: { password: string; remark: string | null; approved_amount: number | null }): Promise<LetterDetail> =>
  (await api.post(`/letters/${id}/sign`, { ...input, approved_amount: input.approved_amount ?? undefined })).data.data
export const attachToLetter = async (id: number, file: File): Promise<LetterDetail> => {
  const form = new FormData()
  form.append('file', file)
  return (await api.post(`/letters/${id}/attachments`, form)).data.data
}
export const attachmentUrl = (letterId: number, attachmentId: number) => `/api/v1/letters/${letterId}/attachments/${attachmentId}`

export const fetchRegister = async (p: { type: 'outward' | 'inward'; fy?: number }): Promise<{ data: RegisterRow[]; meta: { fy: number; current_fy: number } }> =>
  (await api.get('/letters/register', { params: p })).data
export const registerCsvUrl = (p: { type: 'outward' | 'inward'; fy: number }) => `/api/v1/letters/register?${new URLSearchParams({ type: p.type, fy: String(p.fy), format: 'csv' })}`

export const fetchCategories = async (): Promise<{ id: number; name: string; is_active: boolean }[]> => (await api.get('/letter-settings/categories')).data.data
export const saveCategory = async (id: number | null, input: { name: string; is_active: boolean }) =>
  id ? (await api.put(`/letter-settings/categories/${id}`, input)).data.data : (await api.post('/letter-settings/categories', input)).data.data
export const seedCategories = async (): Promise<{ added: number }> => (await api.post('/letter-settings/categories/defaults')).data.data
export const fetchWorkflows = async (): Promise<Workflow[]> => (await api.get('/letter-settings/workflows')).data.data
export const saveWorkflow = async (id: number | null, input: { name: string; letter_category_id: number | null; is_active: boolean; steps: WorkflowStepInput[] }): Promise<Workflow> =>
  id ? (await api.put(`/letter-settings/workflows/${id}`, input)).data.data : (await api.post('/letter-settings/workflows', input)).data.data

export const fetchPublicSignature = async (code: string): Promise<PublicSignature> => (await api.get(`/public/signatures/${encodeURIComponent(code)}`)).data.data
