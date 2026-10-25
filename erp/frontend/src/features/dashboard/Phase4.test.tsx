import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '@/features/attendance/api'
import * as authApi from '@/features/auth/api'
import * as leaveApi from '@/features/leave/api'
import { setLanguage } from '@/i18n'
import { todayYmd } from '@/lib/format'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as dashboardApi from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('@/features/attendance/api', () => ({
  fetchMyEmployee: vi.fn(),
  fetchMyAttendance: vi.fn(),
  checkIn: vi.fn(),
  checkOut: vi.fn(),
  fetchMyCorrections: vi.fn(),
  requestCorrection: vi.fn(),
  fetchCorrections: vi.fn(),
  decideCorrection: vi.fn(),
}))
vi.mock('@/features/leave/api', () => ({
  fetchActiveLeaveTypes: vi.fn(),
  fetchMyLeave: vi.fn(),
  applyLeave: vi.fn(),
  cancelLeave: vi.fn(),
  attachmentUrl: (id: number) => `/x/${id}`,
  fetchLeaveApprovals: vi.fn(),
  decideLeave: vi.fn(),
}))

const auth = vi.mocked(authApi)
const att = vi.mocked(attendanceApi)
const leave = vi.mocked(leaveApi)
const dash = vi.mocked(dashboardApi)
const employee = { id: 5, name: 'Teacher', employee_code: 'EMP-005', department: { id: 1, name: 'Science' }, institution: { id: 1, name: 'College' } }

const kpis = { employees: 12, present_today: 9, on_leave_today: 1, absent_today: 2, students: null, pending_leave_approvals: 3, pending_corrections: null }

describe('phase 4 screens', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
  })

  it('shows only the KPIs the API returns and passes the period filter', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['employees.view', 'attendance.view'] }))
    dash.fetchDashboard.mockResolvedValue({
      from: '2026-10-19',
      to: '2026-10-25',
      today: '2026-10-25',
      kpis,
      charts: { attendance_trend: [{ date: '2026-10-25', present: 9, late: 1, absent: 2, leave: 1 }], by_department: null, by_institution: null },
    })
    renderApp('/')
    const tiles = await screen.findByRole('region', { name: 'Key figures' })
    expect(within(tiles).getByText('Active employees')).toBeInTheDocument()
    expect(within(tiles).getByText('12')).toBeInTheDocument()
    expect(within(tiles).queryByText('Active students')).not.toBeInTheDocument()
    expect(within(tiles).queryByText('Corrections awaiting approval')).not.toBeInTheDocument()
    expect(dash.fetchDashboard).toHaveBeenLastCalledWith(expect.objectContaining({ period: 'week' }))
    // No institutions.view → no institution filter (its list endpoint would answer 403).
    expect(screen.queryByLabelText('Institution')).not.toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Period'), 'month')
    await waitFor(() => expect(dash.fetchDashboard).toHaveBeenLastCalledWith(expect.objectContaining({ period: 'month' })))
  })

  it('checks in from My attendance', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    att.fetchMyEmployee.mockResolvedValue(employee as never)
    att.fetchMyAttendance.mockResolvedValue([])
    att.fetchMyCorrections.mockResolvedValue([])
    att.checkIn.mockResolvedValue({ id: 1, work_date: todayYmd(), check_in_at: new Date().toISOString(), check_out_at: null, status: 'present' } as never)
    renderApp('/my/attendance')
    await userEvent.click(await screen.findByRole('button', { name: 'Check in' }))
    expect(att.checkIn).toHaveBeenCalledOnce()
    expect(await screen.findByText('Checked in.')).toBeInTheDocument()
  })

  it('tells a user without an employee record why there is nothing to mark', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    att.fetchMyEmployee.mockResolvedValue(null)
    renderApp('/my/attendance')
    expect(await screen.findByText(/not linked to an employee record/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Check in' })).not.toBeInTheDocument()
  })

  it('blocks a leave request that ends before it starts', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    att.fetchMyEmployee.mockResolvedValue(employee as never)
    leave.fetchActiveLeaveTypes.mockResolvedValue([{ id: 1, code: 'CL', name: 'Casual leave', days_per_year: 12, is_paid: true }])
    leave.fetchMyLeave.mockResolvedValue([])
    renderApp('/my/leave')
    await userEvent.selectOptions(await screen.findByLabelText('Leave type'), '1')
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-28' } })
    fireEvent.change(screen.getByLabelText('To'), { target: { value: '2026-10-27' } })
    await userEvent.type(screen.getByLabelText('Reason'), 'Family function')
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }))
    expect(await screen.findByText('The end date cannot be before the start date.')).toBeInTheDocument()
    expect(leave.applyLeave).not.toHaveBeenCalled()
  })

  it('approves a pending leave request with remarks', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    leave.fetchLeaveApprovals.mockResolvedValue([
      {
        id: 7,
        employee: { id: 5, name: 'Teacher', employee_code: 'EMP-005' },
        leave_type: { id: 1, name: 'Casual leave', code: 'CL' },
        start_date: '2026-10-28',
        end_date: '2026-10-29',
        days: 2,
        reason: 'Family function',
        has_attachment: false,
        attachment_name: null,
        status: 'requested',
        reviewer: null,
        decided_at: null,
        decision_remarks: null,
      },
    ])
    leave.decideLeave.mockResolvedValue({} as never)
    renderApp('/approvals')
    await userEvent.click(await screen.findByRole('button', { name: 'Approve: Teacher — Casual leave' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Remarks (optional)'), 'OK')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Approve' }))
    await waitFor(() => expect(leave.decideLeave).toHaveBeenCalledWith(7, true, 'OK'))
    // Corrections are only fetched for attendance.approve holders.
    expect(att.fetchCorrections).not.toHaveBeenCalled()
  })

  it('hides people screens from the menu without permission and guards the route', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    renderApp('/employees')
    const nav = await screen.findByRole('navigation', { name: 'Main' })
    expect(within(nav).queryByRole('link', { name: 'Employees' })).not.toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'My attendance' })).toBeInTheDocument()
    expect(await screen.findByText(/permission/i)).toBeInTheDocument()
  })
})
