import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { notificationText } from '@/features/notifications/text'
import i18n, { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as hostelsApi from './api'

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
  fetchRollSheet: vi.fn(),
  saveRollSheet: vi.fn(),
}))

const auth = vi.mocked(authApi)
const hostels = vi.mocked(hostelsApi)

describe('hostels', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: [], hostel_staff: true }))
  })

  it('a warden takes a roll call: out-pass holders start on leave, a tap marks someone absent', async () => {
    const sheet = {
      date: '2026-07-10', session: 'morning' as const, marked: false, sessions_marked: [],
      residents: [
        { id: 1, name: 'Rohit', room: '101', status: 'on_leave' as const, remark: null, on_out_pass: true },
        { id: 2, name: 'Suraj', room: '101', status: 'present' as const, remark: null, on_out_pass: false },
      ],
    }
    hostels.fetchRollSheet.mockResolvedValue(sheet)
    hostels.saveRollSheet.mockResolvedValue({ ...sheet, marked: true })
    renderApp('/hostels/3/attendance')
    expect(await screen.findByText('1 present · 0 absent · 1 on leave')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Hostels' }).length).toBeGreaterThan(0)
    await userEvent.click(within(screen.getByRole('radiogroup', { name: 'Suraj' })).getByRole('radio', { name: 'Absent' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save roll call' }))
    await waitFor(() =>
      expect(hostels.saveRollSheet).toHaveBeenCalledWith(3, expect.any(String), expect.any(String), [
        { resident_id: 1, status: 'on_leave', remark: null },
        { resident_id: 2, status: 'absent', remark: null },
      ]),
    )
  })

  it('a missed roll call reads in words in both languages', async () => {
    const n = { type: 'hostel.attendance_missing', data: { hostel: 'Boys Hostel A', sessions: 'afternoon,evening', date: '2026-07-10' } } as never
    expect(notificationText(n, i18n.t)).toBe('Boys Hostel A: roll call not taken on 10 Jul 2026 (Afternoon, Evening).')
    await i18n.changeLanguage('mr')
    expect(notificationText(n, i18n.t)).toContain('दुपार, संध्याकाळ')
  })
})
