import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'

export type TaskStatus = 'open' | 'in_progress' | 'submitted' | 'completed' | 'cancelled'
export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent'
export type TaskAction = 'start' | 'submit' | 'complete' | 'reopen' | 'cancel' | 'edit' | 'comment'

export interface TaskUpdate {
  id: number
  type: 'created' | 'comment' | 'started' | 'submitted' | 'completed' | 'reopened' | 'cancelled' | 'edited'
  from_status: TaskStatus | null
  to_status: TaskStatus | null
  body: string | null
  user: { id: number; name: string }
  created_at: string
}

export interface Task {
  id: number
  code: string
  title: string
  description: string | null
  priority: TaskPriority
  due_date: string
  status: TaskStatus
  is_overdue: boolean
  assignee: { id: number; name: string; employee_code: string }
  department: { id: number; name: string } | null
  assigned_by: { id: number; name: string }
  submitted_at: string | null
  completed_at: string | null
  cancelled_at: string | null
  created_at: string
  actions: TaskAction[]
  updates?: TaskUpdate[]
}

export interface TaskInput {
  title: string
  description: string | null
  priority: TaskPriority
  due_date: string
  assignee_employee_id: number
}

export interface Assignable {
  id: number
  name: string
  employee_code: string
  department: string | null
}

export type TaskView = 'mine' | 'assigned' | 'all'

export interface TaskListParams {
  view: TaskView
  status?: TaskStatus | 'active' | ''
  overdue?: boolean
  search?: string
  page?: number
}

export const fetchTasks = async (p: TaskListParams): Promise<Paginated<Task>> =>
  (await api.get('/tasks', { params: { view: p.view, status: p.status || undefined, overdue: p.overdue ? 1 : undefined, search: p.search || undefined, page: p.page } })).data
export const fetchTask = async (id: number): Promise<Task> => (await api.get(`/tasks/${id}`)).data.data
export const fetchAssignable = async (): Promise<Assignable[]> => (await api.get('/tasks/assignable')).data.data
export const createTask = async (input: TaskInput): Promise<Task> => (await api.post('/tasks', input)).data.data
export const updateTask = async (id: number, input: Partial<TaskInput>): Promise<Task> => (await api.put(`/tasks/${id}`, input)).data.data
export const taskTransition = async (id: number, action: Exclude<TaskAction, 'edit' | 'comment'>, note: string): Promise<Task> =>
  (await api.post(`/tasks/${id}/${action}`, { note: note || undefined })).data.data
export const commentTask = async (id: number, body: string): Promise<Task> => (await api.post(`/tasks/${id}/comments`, { body })).data.data
