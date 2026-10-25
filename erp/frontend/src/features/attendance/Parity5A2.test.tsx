import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as api from './parityApi'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./parityApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./parityApi')>()),
  fetchGrid: vi.fn(),
  fetchHolidays: vi.fn(),
  saveHoliday: vi.fn(),
  fetchBalances: vi.fn(),
  allocateLeave: vi.fn(),
  fetchCertificate: vi.fn(),
}))

// The day view under the first tab is not part of these tests.
vi.mock('./api', async (importOriginal) => ({ ...(await importOriginal<typeof import('./api')>()), fetchDay: vi.fn(() => new Promise(() => {})) }))

const auth = vi.mocked(authApi)
const p = vi.mocked(api)
const meta = { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 }
const totals = { present: 2, late: 1, half_day: 0, absent: 1, leave: 0, unpaid_leave: 0, weekly_off: 1, holiday: 1, unmarked: 1, working_days: 5 }

describe('old-app parity: attendance', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
  })

  it('month grid shows a code per day with a legend', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['attendance.view'] }))
    p.fetchGrid.mockResolvedValue({ data: [{ employee: { id: 3, name: 'Physics Teacher', employee_code: 'EMP-003' }, days: { '2026-03-01': 'WO', '2026-03-02': 'P', '2026-03-03': 'HO', '2026-03-04': 'A' }, totals }], meta, from: '2026-03-01', to: '2026-03-31' })
    renderApp('/attendance')
    await userEvent.click(await screen.findByRole('tab', { name: 'Month grid' }))
    const row = (await screen.findByRole('rowheader', { name: 'Physics Teacher' })).closest('tr')!
    expect(within(row).getByText('HO')).toBeInTheDocument()
    expect(within(row).getByText('A')).toBeInTheDocument()
    expect(screen.getByRole('list', { name: 'Codes' })).toHaveTextContent('Weekly off')
    expect(within(row).getByRole('link', { name: 'Attendance certificate: Physics Teacher' })).toHaveAttribute('href', '/attendance/certificate/3?from=2026-03-01&to=2026-03-31')
  })

  it('holidays are read-only without attendance.manage', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    p.fetchHolidays.mockResolvedValue([{ id: 1, holiday_date: '2026-08-15', name: 'Independence Day', institution: null }])
    renderApp('/holidays')
    expect(await screen.findByText('Independence Day')).toBeInTheDocument()
    expect(screen.getByText(/Whole organization/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'New holiday' })).not.toBeInTheDocument()
  })

  it('HR sets the days given for a leave type', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['leave.view', 'leave.balances'] }))
    p.fetchBalances.mockResolvedValue({
      data: [{ employee: { id: 3, name: 'Physics Teacher', employee_code: 'EMP-003' }, balances: [{ leave_type: { id: 1, code: 'CL', name: 'Casual Leave', is_paid: true }, allocated: 12, used: 2, pending: 1, remaining: 9 }] }],
      meta,
      can_allocate: true,
    })
    p.allocateLeave.mockResolvedValue({})
    renderApp('/leave-balances')
    await userEvent.click(await screen.findByRole('button', { name: 'Days given to Physics Teacher for Casual Leave' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Days for the year'), '15')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(p.allocateLeave).toHaveBeenCalledWith({ employee_id: 3, leave_type_id: 1, year: new Date().getFullYear(), days: 15 }))
  })

  it('certificate lists the totals for printing', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    p.fetchCertificate.mockResolvedValue({
      employee: { name: 'Physics Teacher', employee_code: 'EMP-003', designation: 'Assistant Professor', department: 'Physics', institution: 'College', joining_date: null },
      organization: 'Trust',
      from: '2026-03-01',
      to: '2026-03-31',
      totals,
      issued_on: '2026-04-01',
    })
    renderApp('/my/attendance/certificate?from=2026-03-01&to=2026-03-31')
    expect(await screen.findByRole('heading', { name: 'Attendance certificate' })).toBeInTheDocument()
    expect(screen.getByText(/This is to certify that Physics Teacher \(EMP-003\)/)).toBeInTheDocument()
    expect(screen.getByRole('rowheader', { name: 'Working days' }).nextSibling).toHaveTextContent('5')
    expect(p.fetchCertificate).toHaveBeenCalledWith('me', { from: '2026-03-01', to: '2026-03-31' })
  })
})
