import { api } from '@/lib/api'

export interface LeaveType {
  id: number
  code: string
  name: string
  days_per_year: string | number
  is_paid: boolean
  is_active?: boolean
}

export interface LeaveRequest {
  id: number
  employee?: { id: number; name: string; employee_code: string }
  leave_type: { id: number; name: string; code: string }
  start_date: string
  end_date: string
  days: number
  reason: string
  has_attachment: boolean
  attachment_name: string | null
  status: 'requested' | 'approved' | 'rejected' | 'cancelled'
  reviewer: { id: number; name: string } | null
  decided_at: string | null
  decision_remarks: string | null
}

export const fetchActiveLeaveTypes = async (): Promise<LeaveType[]> => (await api.get('/leave-types/active')).data.data
export const fetchMyLeave = async (): Promise<LeaveRequest[]> => (await api.get('/me/leave')).data.data
export async function applyLeave(input: { leave_type_id: number; start_date: string; end_date: string; reason: string; attachment?: File | null }): Promise<LeaveRequest> {
  const form = new FormData()
  form.append('leave_type_id', String(input.leave_type_id))
  form.append('start_date', input.start_date)
  form.append('end_date', input.end_date)
  form.append('reason', input.reason)
  if (input.attachment) form.append('attachment', input.attachment)
  return (await api.post('/me/leave', form)).data.data
}
export const cancelLeave = async (id: number): Promise<LeaveRequest> => (await api.post(`/me/leave/${id}/cancel`)).data.data
export const attachmentUrl = (id: number) => `/api/v1/leave-requests/${id}/attachment`

export const fetchLeaveApprovals = async (): Promise<LeaveRequest[]> => (await api.get('/leave-approvals')).data.data
export const decideLeave = async (id: number, approve: boolean, remarks: string): Promise<LeaveRequest> =>
  (await api.post(`/leave-requests/${id}/decide`, { approve, remarks: remarks || undefined })).data.data

export const fetchLeaveTypes = async (): Promise<LeaveType[]> => (await api.get('/leave-types')).data.data
export const saveLeaveType = async (id: number | null, input: { code: string; name: string; days_per_year: number; is_paid: boolean; is_active: boolean }) =>
  id ? (await api.put(`/leave-types/${id}`, input)).data.data : (await api.post('/leave-types', input)).data.data
