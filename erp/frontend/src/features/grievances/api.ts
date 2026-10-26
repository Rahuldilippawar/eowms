import { api } from '@/lib/api'

export const GRIEVANCE_STATUSES = ['open', 'in_progress', 'resolved', 'closed'] as const
export type GrievanceStatus = (typeof GRIEVANCE_STATUSES)[number]

export interface Grievance {
  id: number
  category: string | null
  description: string
  is_anonymous: boolean
  submitted_by: string | null
  is_mine: boolean
  status: GrievanceStatus
  assignee: { id: number; name: string } | null
  resolution: string | null
  sla_deadline: string | null
  overdue: boolean
  escalated: boolean
  reopened_count: number
  created_at: string | null
  resolved_at: string | null
}

export interface GrievanceDetail extends Grievance {
  comments: { id: number; comment: string; author_role: 'submitter' | 'handler'; by: string | null; at: string | null }[]
  can_update: boolean
  can_assign: boolean
  can_comment: boolean
  can_reopen: boolean
}

export const fetchGrievances = async (p: { status?: string; mine?: boolean }): Promise<{ data: Grievance[]; meta: { is_handler: boolean; open: number; overdue: number } }> =>
  (await api.get('/grievances', { params: { status: p.status || undefined, mine: p.mine ? 1 : undefined } })).data
export const fetchGrievance = async (id: number): Promise<GrievanceDetail> => (await api.get(`/grievances/${id}`)).data.data
export const submitGrievance = async (input: { category: string | null; description: string; is_anonymous: boolean }): Promise<GrievanceDetail> =>
  (await api.post('/grievances', input)).data.data
export const updateGrievance = async (id: number, input: { status: GrievanceStatus; resolution: string | null; assigned_to?: number | null }): Promise<GrievanceDetail> =>
  (await api.put(`/grievances/${id}`, input)).data.data
export const commentOnGrievance = async (id: number, comment: string): Promise<GrievanceDetail> => (await api.post(`/grievances/${id}/comments`, { comment })).data.data
export const reopenGrievance = async (id: number, reason: string): Promise<GrievanceDetail> => (await api.post(`/grievances/${id}/reopen`, { reason })).data.data
