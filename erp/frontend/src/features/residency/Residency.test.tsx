import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { notificationText } from '@/features/notifications/text'
import i18n, { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as residencyApi from './api'

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
  fetchMyResidency: vi.fn(),
  requestHousing: vi.fn(),
}))

const auth = vi.mocked(authApi)
const residency = vi.mocked(residencyApi)

describe('residency', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: [] }))
  })

  it('a staff member without a room asks for one', async () => {
    const empty = { home: null, bills: [], requests: [], buildings: [{ id: 4, name: 'Staff Quarters A' }] }
    residency.fetchMyResidency.mockResolvedValue(empty)
    residency.requestHousing.mockResolvedValue({
      ...empty,
      requests: [
        { id: 9, requested_by: 'Amol', requested_by_id: 1, preferred_building: 'Staff Quarters A', family_size: 3, reason: null, status: 'pending', allotted_room: null, decided_by: null, decided_at: null, remarks: null, created_at: '2026-07-10T10:00:00+05:30' },
      ],
    })
    renderApp('/my/residency')
    expect(await screen.findByText('You do not have a room on campus.')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Staff housing' })).not.toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText('Preferred building'), '4')
    await userEvent.clear(screen.getByLabelText('People who will live (with you)'))
    await userEvent.type(screen.getByLabelText('People who will live (with you)'), '3')
    await userEvent.click(screen.getByRole('button', { name: 'Send request' }))
    await waitFor(() => expect(residency.requestHousing).toHaveBeenCalledWith({ preferred_building_id: 4, family_size: 3, reason: null }))
    expect(await screen.findByText('Your request of 10 Jul 2026 is open.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Cancel request' })).toBeInTheDocument()
  })

  it('a housed staff member sees the room, family and bill share', async () => {
    residency.fetchMyResidency.mockResolvedValue({
      home: { room: 'Q-101', building: 'Staff Quarters A', moved_in_date: '2026-01-15', family: [{ id: 2, name: 'Asha', relation: 'Mother', age: 62 }] },
      bills: [{ id: 5, billing_month: '2026-07', amount_due: 750.5, bill_total: 1501, shared_by: 2, paid_at: null }],
      requests: [],
      buildings: [],
    })
    renderApp('/my/residency')
    expect(await screen.findByText('Q-101 · Staff Quarters A')).toBeInTheDocument()
    expect(screen.getByText('Asha · Mother · 62 yrs')).toBeInTheDocument()
    expect(screen.getByText('Not paid')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Send request' })).not.toBeInTheDocument()
  })

  it('a bill notification reads in words in both languages', async () => {
    const n = { type: 'residency.bill', data: { month: '2026-07', amount: '750.50', room: 'Q-101' } } as never
    expect(notificationText(n, i18n.t)).toBe('Housing bill for 2026-07 (Q-101): your share 750.50.')
    await i18n.changeLanguage('mr')
    expect(notificationText(n, i18n.t)).toContain('तुमचा वाटा 750.50')
  })
})
