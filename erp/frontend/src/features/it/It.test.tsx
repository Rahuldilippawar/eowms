import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { notificationText } from '@/features/notifications/text'
import i18n, { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as itApi from './api'

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
  lookupTag: vi.fn(),
  reportProblem: vi.fn(),
  fetchMyTickets: vi.fn(),
}))

const auth = vi.mocked(authApi)
const it_ = vi.mocked(itApi)

describe('IT problems', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: [] }))
    it_.fetchMyTickets.mockResolvedValue([])
  })

  it('anyone finds a computer by its tag and reports what is wrong', async () => {
    it_.lookupTag.mockResolvedValue({ id: 4, asset_tag: 'PC-26-0001', asset_type: 'cpu', brand: 'Dell', model: 'OptiPlex', workstation_no: 'PC-01', lab: 'Computer Lab 1', status: 'working', open_tickets: 1 })
    it_.reportProblem.mockResolvedValue({} as never)
    renderApp('/it/report')
    await userEvent.type(await screen.findByLabelText('Tag'), 'pc-26-0001')
    await userEvent.click(screen.getByRole('button', { name: 'Find' }))
    expect(await screen.findByText('1 problem is already reported and not fixed yet.')).toBeInTheDocument()
    const send = screen.getByRole('button', { name: 'Send to IT' })
    expect(send).toBeDisabled()
    await userEvent.type(screen.getByLabelText('What is wrong?'), 'Does not start')
    await userEvent.click(send)
    await waitFor(() => expect(it_.reportProblem).toHaveBeenCalledWith(4, 'Does not start'))
    expect(it_.lookupTag).toHaveBeenCalledWith('pc-26-0001')
  })

  it('a new problem reads in words in both languages', async () => {
    const n = { type: 'it.ticket_new', data: { tag: 'PC-26-0001', lab: 'Computer Lab 1', issue: 'Does not start' } } as never
    expect(notificationText(n, i18n.t)).toBe('Problem with PC-26-0001 (Computer Lab 1): Does not start')
    await i18n.changeLanguage('mr')
    expect(notificationText(n, i18n.t)).toContain('मध्ये बिघाड')
  })
})
