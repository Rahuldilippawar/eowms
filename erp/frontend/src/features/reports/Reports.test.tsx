import { screen, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as reportsApi from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./api', async (importOriginal) => ({ ...(await importOriginal<typeof import('./api')>()), fetchCampusComparison: vi.fn() }))

const auth = vi.mocked(authApi)
const reports = vi.mocked(reportsApi)

describe('Reports', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
  })

  it('the hub lists only the reports the user can open', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['daily_work.view', 'institutions.view'] }))
    renderApp('/reports')
    expect(await screen.findByRole('link', { name: /Work report \(print\)/ })).toHaveAttribute('href', '/work/print')
    expect(screen.getByRole('link', { name: /Campus comparison/ })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Salary report/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Money' })).not.toBeInTheDocument()
  })

  it('campus comparison shows campuses side by side and highlights the best share', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['institutions.view'] }))
    reports.fetchCampusComparison.mockResolvedValue({
      from: '2026-10-01', to: '2026-10-30', today: '2026-10-30', metrics: ['departments', 'employees', 'present_today', 'work_entries', 'work_completed'],
      campuses: [
        { id: 1, name: 'Arts College', code: 'AC', type: 'college', departments: 4, employees: 20, present_today: 18, attendance_percent: 90, work_entries: 10, work_completed: 5, work_completion_percent: 50 },
        { id: 2, name: 'Bal School', code: 'BS', type: 'school', departments: 2, employees: 8, present_today: 6, attendance_percent: 75, work_entries: 0, work_completed: 0, work_completion_percent: null },
      ],
    })
    renderApp('/reports/campuses')
    const row = (await screen.findByRole('rowheader', { name: 'Attendance today' })).closest('tr') as HTMLElement
    expect(within(row).getAllByRole('cell').map((c) => c.textContent)).toEqual(['90%', '75%'])
    expect(within(row).getByText('90%')).toHaveClass('font-semibold')
    expect(screen.getByRole('columnheader', { name: 'Bal School' })).toBeInTheDocument()
    expect(screen.queryByRole('rowheader', { name: 'Students' })).not.toBeInTheDocument()
    const work = screen.getByRole('rowheader', { name: 'Work completed' }).closest('tr') as HTMLElement
    expect(within(work).getAllByRole('cell').map((c) => c.textContent)).toEqual(['50%', '—'])
  })
})
