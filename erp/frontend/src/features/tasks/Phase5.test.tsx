import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '@/features/attendance/api'
import * as authApi from '@/features/auth/api'
import * as notificationsApi from '@/features/notifications/api'
import { notificationText } from '@/features/notifications/text'
import * as workApi from '@/features/work/api'
import i18n, { setLanguage } from '@/i18n'
import { todayYmd } from '@/lib/format'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as tasksApi from './api'
import type { Task } from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./api', () => ({
  fetchTasks: vi.fn(),
  fetchTask: vi.fn(),
  fetchAssignable: vi.fn(),
  createTask: vi.fn(),
  updateTask: vi.fn(),
  taskTransition: vi.fn(),
  commentTask: vi.fn(),
}))
vi.mock('@/features/work/api', () => ({
  fetchMyWork: vi.fn(),
  fetchMyOpenTasks: vi.fn(),
  saveWork: vi.fn(),
  deleteWork: vi.fn(),
  fetchTeamWork: vi.fn(),
  reviewWork: vi.fn(),
  workCsvUrl: () => '/x',
}))
vi.mock('@/features/attendance/api', () => ({ fetchMyEmployee: vi.fn() }))

const auth = vi.mocked(authApi)
const tasks = vi.mocked(tasksApi)
const work = vi.mocked(workApi)
const notifications = vi.mocked(notificationsApi)
const att = vi.mocked(attendanceApi)
const meta = { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 }

const task = (over: Partial<Task> = {}): Task => ({
  id: 7,
  code: 'TSK-00007',
  title: 'Fix projector',
  description: null,
  priority: 'high',
  due_date: '2026-05-06',
  status: 'open',
  is_overdue: false,
  assignee: { id: 3, name: 'Physics Teacher', employee_code: 'EMP-003' },
  department: null,
  assigned_by: { id: 2, name: 'HOD Physics' },
  submitted_at: null,
  completed_at: null,
  cancelled_at: null,
  created_at: '2026-05-04T10:00:00+05:30',
  actions: ['start', 'submit', 'comment'],
  updates: [{ id: 1, type: 'created', from_status: null, to_status: 'open', body: null, user: { id: 2, name: 'HOD Physics' }, created_at: '2026-05-04T10:00:00+05:30' }],
  ...over,
})

describe('phase 5 screens', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    notifications.fetchUnreadCount.mockResolvedValue(0)
  })

  it('renders notification text in the reader’s language from type + data', () => {
    const n = { id: 1, type: 'leave.decided', data: { status: 'rejected', by: 'HOD', type: 'Casual Leave', start_date: '2026-05-06', end_date: '2026-05-07' }, link: null, read_at: null, created_at: '' }
    expect(notificationText(n, i18n.t)).toBe('HOD rejected your Casual Leave, 6 May 2026 – 7 May 2026.')
    setLanguage('mr')
    expect(notificationText({ ...n, data: { ...n.data, status: 'approved' } }, i18n.t)).toContain('मंजूर केली')
    expect(notificationText({ ...n, type: 'something.new' }, i18n.t)).toBe('तुमच्यासाठी नवीन माहिती आहे.')
  })

  it('bell shows the unread count and opens the linked page, marking it read', async () => {
    notifications.fetchUnreadCount.mockResolvedValue(2)
    notifications.fetchNotifications.mockResolvedValue({
      data: [{ id: 5, type: 'task.assigned', data: { by: 'HOD Physics', code: 'TSK-00007', title: 'Fix projector', due_date: '2026-05-06' }, link: '/tasks/7', read_at: null, created_at: '' }],
      meta,
      unread: 2,
    })
    notifications.markRead.mockResolvedValue(1)
    tasks.fetchTask.mockResolvedValue(task())
    renderApp('/')
    await userEvent.click(await screen.findByRole('button', { name: 'Notifications, 2 unread' }))
    await userEvent.click(await screen.findByRole('menuitem', { name: /HOD Physics assigned you TSK-00007/ }))
    expect(notifications.markRead).toHaveBeenCalledWith(5)
    expect(await screen.findByRole('heading', { name: 'Fix projector' })).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Notifications, 1 unread' })).toBeInTheDocument()
  })

  it('task page offers only the server’s actions; sending back needs a reason', async () => {
    tasks.fetchTask.mockResolvedValue(task({ status: 'submitted', actions: ['complete', 'reopen', 'cancel', 'edit', 'comment'] }))
    tasks.taskTransition.mockResolvedValue(task({ status: 'in_progress', actions: ['cancel', 'edit', 'comment'] }))
    renderApp('/tasks/7')
    const group = await screen.findByRole('group', { name: 'Actions' })
    expect(within(group).queryByRole('button', { name: 'Start' })).not.toBeInTheDocument()

    await userEvent.click(within(group).getByRole('button', { name: 'Send back' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send back' }))
    expect(await within(dialog).findByText('Please write a short reason.')).toBeInTheDocument()
    expect(tasks.taskTransition).not.toHaveBeenCalled()

    await userEvent.type(within(dialog).getByLabelText('Reason'), 'Remote missing')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send back' }))
    await waitFor(() => expect(tasks.taskTransition).toHaveBeenCalledWith(7, 'reopen', 'Remote missing'))
    expect(await screen.findByText('In progress')).toBeInTheDocument()
  })

  it('assignee starts a task in one click', async () => {
    tasks.fetchTask.mockResolvedValue(task())
    tasks.taskTransition.mockResolvedValue(task({ status: 'in_progress', actions: ['submit', 'comment'] }))
    renderApp('/tasks/7')
    await userEvent.click(await screen.findByRole('button', { name: 'Start' }))
    await waitFor(() => expect(tasks.taskTransition).toHaveBeenCalledWith(7, 'start', ''))
    expect(await screen.findByRole('button', { name: 'Mark done' })).toBeInTheDocument()
  })

  it('hides "Assign a task" when there is nobody to assign to', async () => {
    tasks.fetchTasks.mockResolvedValue({ data: [task()], meta })
    tasks.fetchAssignable.mockResolvedValue([])
    renderApp('/tasks')
    expect(await screen.findByText('Fix projector')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Assign a task' })).not.toBeInTheDocument()
  })

  it('logs work with validated hours', async () => {
    att.fetchMyEmployee.mockResolvedValue({ id: 3, name: 'Physics Teacher', employee_code: 'EMP-003', department: { id: 1, name: 'Physics' }, is_active: true })
    work.fetchMyWork.mockResolvedValue({ data: [], meta: { edit_days: 3, today: todayYmd() } })
    work.fetchMyOpenTasks.mockResolvedValue([{ id: 7, code: 'TSK-00007', title: 'Fix projector', status: 'open', due_date: '2026-05-06' }])
    work.saveWork.mockResolvedValue({} as never)
    renderApp('/my/work')
    await userEvent.type(await screen.findByLabelText('What did you do?'), 'Checked lab records')
    await userEvent.type(screen.getByLabelText('Hours (optional)'), '0.3')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Use 0.25 to 24, in steps of 0.25.')).toBeInTheDocument()

    await userEvent.clear(screen.getByLabelText('Hours (optional)'))
    await userEvent.type(screen.getByLabelText('Hours (optional)'), '1.5')
    await screen.findByRole('option', { name: 'TSK-00007 — Fix projector' })
    await userEvent.selectOptions(screen.getByLabelText('Related task (optional)'), '7')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() =>
      expect(work.saveWork).toHaveBeenCalledWith(null, { work_date: todayYmd(), title: 'Checked lab records', description: null, hours: 1.5, status: 'completed', task_id: 7 }),
    )
  })
})
