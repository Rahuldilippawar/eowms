import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import * as grievanceApi from '@/features/grievances/api'
import { notificationText } from '@/features/notifications/text'
import i18n, { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as requestsApi from './api'
import type { WorkRequestDetail } from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchRequests: vi.fn(),
  fetchRequest: vi.fn(),
  fetchRequestDepartments: vi.fn(),
  createRequest: vi.fn(),
  closeRequest: vi.fn(),
  fetchPublicForm: vi.fn(),
  submitPublicRequest: vi.fn(),
}))
vi.mock('@/features/grievances/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/grievances/api')>()),
  fetchGrievances: vi.fn(),
  submitGrievance: vi.fn(),
  fetchGrievance: vi.fn(),
}))

const auth = vi.mocked(authApi)
const requests = vi.mocked(requestsApi)
const grievances = vi.mocked(grievanceApi)
const page = { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null }

const detail = (over: Partial<WorkRequestDetail> = {}): WorkRequestDetail => ({
  id: 4, request_code: 'REQ-26-0004', department: { id: 2, name: 'Maintenance' }, category: 'maintenance', sub_category: null, title: 'Leaking tap', work_description: 'Lab sink tap leaks',
  location: 'Physics lab', priority: 'high', status: 'completed', source: 'staff', requester_name: 'Physics Teacher', assignee: { id: 8, name: 'Plumber' }, due_at: null, overdue: false,
  escalation_level: 0, created_at: '2026-06-09T10:00:00+05:30', completed_at: '2026-06-10T10:00:00+05:30', rating: null, requester_contact: null, requesting_department: 'Physics',
  cost_incurred: 150, completion_remarks: 'Washer replaced', completed_by: 'Plumber', feedback: null, reopened_count: 0, actions: [], comments: [], seen_by: [],
  can_handle: false, can_assign: false, is_requester: true, can_reopen: true, ...over,
})

describe('work requests and grievances', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: [] }))
  })

  it('anyone raises a request; an IT request needs the kind of problem but no department', async () => {
    requests.fetchRequests.mockResolvedValue({ data: [], meta: { ...page, counts: { pending: 0, in_progress: 0, overdue: 0, completed: 0 }, can_export: false, can_view_queue: false } })
    requests.fetchRequestDepartments.mockResolvedValue([{ id: 2, name: 'Maintenance' }])
    requests.createRequest.mockResolvedValue(detail({ status: 'pending' }))
    renderApp('/requests')
    await userEvent.click(await screen.findByRole('button', { name: 'New request' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.selectOptions(within(dialog).getByLabelText('Kind of work'), 'it')
    await userEvent.type(within(dialog).getByLabelText('Short title'), 'Printer jam')
    await userEvent.type(within(dialog).getByLabelText('Details'), 'Office printer jams on every page')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send request' }))
    expect(await within(dialog).findByText('This is required')).toBeInTheDocument()
    await userEvent.selectOptions(within(dialog).getByLabelText('IT problem'), 'printer_scanner')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send request' }))
    await waitFor(() =>
      expect(requests.createRequest).toHaveBeenCalledWith(expect.objectContaining({ category: 'it', sub_category: 'printer_scanner', department_id: null, title: 'Printer jam', priority: 'normal' })),
    )
  })

  it('the requester closes a completed request with a star rating', async () => {
    requests.fetchRequest.mockResolvedValue(detail())
    requests.closeRequest.mockResolvedValue(detail({ status: 'closed', rating: 3 }))
    renderApp('/requests/4')
    expect(await screen.findByText('Washer replaced')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('radio', { name: '3 stars' }))
    await userEvent.click(screen.getByRole('button', { name: 'Close with rating' }))
    await waitFor(() => expect(requests.closeRequest).toHaveBeenCalledWith(4, 3, null))
  })

  it('the public form sends the hidden honeypot field along, and shows the request number', async () => {
    requests.fetchPublicForm.mockResolvedValue({ organization: { name: 'Shivaji Education Trust', code: 'SET' }, departments: [{ id: 2, name: 'Maintenance' }], categories: ['general'] })
    requests.submitPublicRequest.mockResolvedValue({ received: true, request_code: 'REQ-26-0007' })
    renderApp('/request/SET')
    await userEvent.type(await screen.findByLabelText('Your name'), 'Parent')
    await userEvent.type(screen.getByLabelText('Your phone number'), '98220')
    await userEvent.selectOptions(screen.getByLabelText('To department'), '2')
    await userEvent.type(screen.getByLabelText('What needs doing'), 'Gate light is off')
    await userEvent.click(screen.getByRole('button', { name: 'Send request' }))
    expect(await screen.findByText('Enter a 10-digit phone number.')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Your phone number'), '12345')
    await userEvent.click(screen.getByRole('button', { name: 'Send request' }))
    expect(await screen.findByText('REQ-26-0007')).toBeInTheDocument()
    expect(requests.submitPublicRequest).toHaveBeenCalledWith('SET', expect.objectContaining({ requester_contact: '9822012345', department_id: 2, website: '' }))
  })

  it('a grievance can be sent anonymously', async () => {
    grievances.fetchGrievances.mockResolvedValue({ data: [], meta: { is_handler: false, open: 0, overdue: 0 } })
    grievances.submitGrievance.mockResolvedValue({ id: 3 } as never)
    grievances.fetchGrievance.mockReturnValue(new Promise(() => {}))
    renderApp('/grievances')
    await userEvent.click(await screen.findByRole('button', { name: 'New grievance' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('What happened'), 'The canteen water is not clean.')
    await userEvent.click(within(dialog).getByLabelText(/Send anonymously/))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send grievance' }))
    await waitFor(() => expect(grievances.submitGrievance).toHaveBeenCalledWith({ category: null, description: 'The canteen water is not clean.', is_anonymous: true }))
  })

  it('statuses in notifications are shown in words', async () => {
    const n = { type: 'request.status', data: { code: 'REQ-26-0004', title: 'Tap', status: 'in_progress' } } as never
    expect(notificationText(n, i18n.t)).toBe('Request REQ-26-0004 is now In progress.')
    await i18n.changeLanguage('mr')
    expect(notificationText(n, i18n.t)).toContain('सुरू आहे')
  })
})
