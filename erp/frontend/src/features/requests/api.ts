import { api, ensureCsrfCookie } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'

/** Same lists as the server (WorkRequest constants). */
export const CATEGORIES = ['general', 'it', 'maintenance', 'housekeeping', 'other'] as const
export const IT_SUB_CATEGORIES = ['internet_network', 'software', 'new_hardware', 'account_access', 'printer_scanner', 'other'] as const
export const PRIORITIES = ['low', 'normal', 'high', 'urgent'] as const
export const STATUSES = ['pending', 'in_progress', 'completed', 'closed', 'cancelled'] as const
export type Category = (typeof CATEGORIES)[number]
export type Priority = (typeof PRIORITIES)[number]
export type RequestStatus = (typeof STATUSES)[number]

export interface WorkRequest {
  id: number
  request_code: string
  department: { id: number; name: string } | null
  category: Category
  sub_category: string | null
  title: string | null
  work_description: string
  location: string | null
  priority: Priority
  status: RequestStatus
  source: 'staff' | 'public'
  requester_name: string
  assignee: { id: number; name: string } | null
  due_at: string | null
  overdue: boolean
  escalation_level: number
  created_at: string | null
  completed_at: string | null
  rating: number | null
}

export interface WorkRequestDetail extends WorkRequest {
  requester_contact: string | null
  requesting_department: string | null
  cost_incurred: number | null
  completion_remarks: string | null
  completed_by: string | null
  feedback: string | null
  reopened_count: number
  actions: { type: string; old_status: string | null; new_status: string | null; remark: string | null; by: string | null; at: string | null }[]
  comments: { id: number; comment: string; by: string | null; at: string | null }[]
  seen_by: { name: string | null; at: string | null }[]
  can_handle: boolean
  can_assign: boolean
  is_requester: boolean
  can_reopen: boolean
}

export interface RequestInput {
  category: Category
  sub_category: string | null
  department_id: number | null
  title: string
  work_description: string
  location: string | null
  priority: Priority
  requester_contact: string | null
}

export interface QueueMeta {
  counts: { pending: number; in_progress: number; overdue: number; completed: number }
  can_export: boolean
  can_view_queue: boolean
}

export interface RequestReport {
  from: string
  to: string
  by_status: Partial<Record<RequestStatus, number>>
  by_category: Partial<Record<Category, number>>
  by_department: { id: number; name: string; total: number; done: number; overdue: number; avg_hours: number | null; cost: number; avg_rating: number | null }[]
}

export type QueueParams = { status?: string; category?: string; priority?: string; assigned?: string; mine?: boolean; search?: string; page?: number }

export const fetchRequests = async (p: QueueParams): Promise<Paginated<WorkRequest> & { meta: QueueMeta }> =>
  (await api.get('/requests', { params: Object.fromEntries(Object.entries({ ...p, mine: p.mine ? 1 : undefined }).filter(([, v]) => v !== '' && v !== undefined)) })).data
export const requestsCsvUrl = '/api/v1/requests?format=csv&status=all'
export const fetchRequestDepartments = async (): Promise<{ id: number; name: string }[]> => (await api.get('/requests/departments')).data.data
export const fetchRequest = async (id: number): Promise<WorkRequestDetail> => (await api.get(`/requests/${id}`)).data.data
export const createRequest = async (input: RequestInput): Promise<WorkRequestDetail> => (await api.post('/requests', input)).data.data
export const setRequestStatus = async (id: number, input: { status: 'pending' | 'in_progress' | 'completed'; remark: string | null; cost_incurred?: number | null }): Promise<WorkRequestDetail> =>
  (await api.post(`/requests/${id}/status`, input)).data.data
export const fetchAssignees = async (id: number): Promise<{ user_id: number; name: string; designation: string | null }[]> => (await api.get(`/requests/${id}/assignees`)).data.data
export const assignRequest = async (id: number, userId: number | null): Promise<WorkRequestDetail> => (await api.post(`/requests/${id}/assign`, { user_id: userId })).data.data
export const commentOnRequest = async (id: number, comment: string): Promise<WorkRequestDetail> => (await api.post(`/requests/${id}/comments`, { comment })).data.data
export const cancelRequest = async (id: number, reason: string): Promise<WorkRequestDetail> => (await api.post(`/requests/${id}/cancel`, { reason })).data.data
export const closeRequest = async (id: number, rating: number, feedback: string | null): Promise<WorkRequestDetail> => (await api.post(`/requests/${id}/close`, { rating, feedback })).data.data
export const reopenRequest = async (id: number, reason: string): Promise<WorkRequestDetail> => (await api.post(`/requests/${id}/reopen`, { reason })).data.data
export const fetchRequestReport = async (from: string, to: string): Promise<RequestReport> => (await api.get('/requests/report', { params: { from, to } })).data.data

export interface PublicForm {
  organization: { name: string; code: string }
  departments: { id: number; name: string }[]
  categories: Category[]
}
export interface TrackResult {
  request_code: string
  status: RequestStatus
  department: string | null
  created_at: string | null
  completed_at: string | null
  completion_remarks: string | null
  seen_at: string | null
  timeline: { type: string; status: string | null; at: string | null }[]
}

export const fetchPublicForm = async (org: string): Promise<PublicForm> => (await api.get(`/public/requests/${encodeURIComponent(org)}`)).data.data
export const submitPublicRequest = async (
  org: string,
  input: { requester_name: string; requester_contact: string; department_id: number; category: Category; location: string | null; work_description: string; website: string },
): Promise<{ received: boolean; request_code: string | null }> => {
  await ensureCsrfCookie()
  return (await api.post(`/public/requests/${encodeURIComponent(org)}`, input)).data.data
}
export const trackRequest = async (org: string, code: string, contact: string): Promise<TrackResult> => {
  await ensureCsrfCookie()
  return (await api.post(`/public/requests/${encodeURIComponent(org)}/track`, { code, contact })).data.data
}
